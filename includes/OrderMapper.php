<?php
/**
 * Turns a WC_Order into a KeepinCRM "agreement" (угода) payload.
 *
 * KeepinCRM's POST /agreements body is nested: title, total, currency, comment,
 * client_attributes{person,company,email,phones[],lead,comment}, and
 * jobs_attributes[]{title,amount,price,discount} for the line items. There is no
 * external-id field on agreements, so de-duplication relies solely on the
 * KeepinCRM agreement id we store in order meta (see Sender). Default funnel /
 * stage / source / responsible ids come from the settings and are attached only
 * when a positive value is configured.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync;

use CatCode\KeepincrmSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class OrderMapper {

	/**
	 * Build the KeepinCRM agreement payload for a WooCommerce order.
	 *
	 * @param \WC_Order $order Order object (HPOS-safe CRUD access only).
	 * @return array
	 */
	public static function build( \WC_Order $order ): array {
		$cfg       = Settings::all();
		$skip_zero = 'yes' === ( $cfg['skip_zero_price'] ?? 'yes' );

		$jobs = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$qty = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}
			$price = round( (float) $order->get_item_total( $item, true, false ), 2 );
			if ( $skip_zero && $price * $qty <= 0.0001 ) {
				continue;
			}

			$jobs[] = array(
				'title'  => (string) $item->get_name(),
				'amount' => $qty,
				'price'  => (string) $price,
			);
		}

		// Shipping cost becomes its own agreement line so the total reconciles.
		if ( 'yes' === ( $cfg['include_shipping'] ?? 'yes' ) ) {
			$ship_cost = round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 );
			if ( $ship_cost > 0 ) {
				$ship_title = (string) $order->get_shipping_method();
				$jobs[]     = array(
					'title'  => '' !== $ship_title ? $ship_title : __( 'Доставка', 'keepincrm-sync-for-woocommerce' ),
					'amount' => 1,
					'price'  => (string) $ship_cost,
				);
			}
		}

		$first = trim( (string) ( $order->get_shipping_first_name() ?: $order->get_billing_first_name() ) );
		$last  = trim( (string) ( $order->get_shipping_last_name() ?: $order->get_billing_last_name() ) );
		$person = trim( $first . ' ' . $last );
		if ( '' === $person ) {
			$person = (string) $order->get_billing_email();
		}
		if ( '' === $person ) {
			$person = __( 'Клієнт', 'keepincrm-sync-for-woocommerce' );
		}

		$client = array(
			'person'  => $person,
			'company' => '', // required by the schema; blank for a B2C person.
			'lead'    => true,
			'email'   => (string) $order->get_billing_email(),
		);
		$phone = self::phone( (string) $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$client['phones'] = array( $phone );
		}
		$address = self::address_line( $order );
		if ( '' !== $address ) {
			$client['comment'] = $address;
		}

		$comment = (string) $order->get_customer_note();
		$pay     = (string) $order->get_payment_method_title();
		if ( '' !== $pay ) {
			$comment = '' !== $comment ? $comment . ' | ' . $pay : $pay;
		}
		if ( 'yes' === ( $cfg['pass_payment_status'] ?? 'yes' ) && ( $order->is_paid() || null !== $order->get_date_paid() ) ) {
			$note    = sprintf( /* translators: %s: order total */ __( '✅ Оплачено онлайн (%s)', 'keepincrm-sync-for-woocommerce' ), wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ) );
			$comment = '' !== $comment ? $comment . ' | ' . $note : $note;
		}

		$payload = array(
			/* translators: 1: order number, 2: shop host. */
			'title'                   => sprintf( __( 'Замовлення #%1$s (%2$s)', 'keepincrm-sync-for-woocommerce' ), $order->get_order_number(), (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'total'                   => round( (float) $order->get_total(), 2 ),
			'currency'                => (string) $order->get_currency(),
			'products_total_as_total' => true,
			'client_attributes'       => $client,
			'jobs_attributes'         => $jobs,
		);
		if ( '' !== $comment ) {
			$payload['comment'] = $comment;
		}

		// Optional CRM routing defaults — only sent when a positive id is set.
		foreach ( array( 'funnel_id', 'stage_id', 'source_id', 'main_responsible_id' ) as $key ) {
			$val = (int) ( $cfg[ $key ] ?? 0 );
			if ( $val > 0 ) {
				$payload[ $key ] = $val;
			}
		}

		/**
		 * Allow site-specific payload adjustments before sending to KeepinCRM.
		 *
		 * @param array     $payload KeepinCRM agreement payload.
		 * @param \WC_Order $order   Source WooCommerce order.
		 */
		return (array) apply_filters( 'cc_keepincrm_order_payload', $payload, $order );
	}

	/** Compose a single delivery address line, preferring the shipping address. */
	private static function address_line( \WC_Order $order ): string {
		$parts = array_filter(
			array(
				(string) $order->get_shipping_city(),
				(string) $order->get_shipping_address_1(),
				(string) $order->get_shipping_address_2(),
			)
		);
		if ( ! $parts ) {
			$parts = array_filter(
				array(
					(string) $order->get_billing_city(),
					(string) $order->get_billing_address_1(),
					(string) $order->get_billing_address_2(),
				)
			);
		}
		return trim( implode( ', ', $parts ) );
	}

	/**
	 * Normalize a phone number to E.164-ish (+380...).
	 */
	private static function phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( '' === $digits || null === $digits ) {
			return '';
		}
		if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = '38' . $digits;
		}
		return '+' . $digits;
	}
}
