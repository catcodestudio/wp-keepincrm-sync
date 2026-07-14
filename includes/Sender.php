<?php
/**
 * Order sync orchestrator: listens to checkout / status-change hooks,
 * deduplicates via order meta, retries failures with backoff.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync;

use CatCode\KeepincrmSync\Api\Client;
use CatCode\KeepincrmSync\Core\Logger;
use CatCode\KeepincrmSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Sender {

	public const META_AGREEMENT_ID  = '_cc_keepincrm_agreement_id';
	public const META_STATUS     = '_cc_keepincrm_status';
	public const META_LAST_ERROR = '_cc_keepincrm_last_error';
	public const META_SENT_AT    = '_cc_keepincrm_sent_at';

	private const MAX_ATTEMPTS = 3;

	/** Backoff delays between attempt N and N+1, seconds: 5 min, 30 min, 2 h. */
	private const BACKOFF = array( 300, 1800, 7200 );

	/**
	 * Attach WooCommerce hooks. Called once from Plugin::boot(); plain
	 * `new Sender()` (e.g. for a manual resend) does not register anything.
	 */
	public function register_hooks(): void {
		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout' ), 20, 1 );
		// Blocks (Store API) checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_checkout' ), 20, 1 );
		// Transition into a trigger status (covers manual orders, payment callbacks).
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
		// Scheduled retry.
		add_action( 'cc_keepincrm_retry_send', array( $this, 'on_retry' ), 10, 2 );
	}

	/**
	 * @param int|\WC_Order $order Order id (classic hook) or WC_Order (Store API hook).
	 */
	public function on_checkout( $order ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return;
		}
		$this->maybe_send( $order );
	}

	/**
	 * @param int       $order_id Order id.
	 * @param string    $from     Previous status.
	 * @param string    $to       New status.
	 * @param \WC_Order $order    Order.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) {
			return;
		}
		if ( ! in_array( (string) $to, $this->trigger_statuses(), true ) ) {
			return;
		}
		$this->maybe_send( $order );
	}

	/**
	 * Retry handler scheduled via wp_schedule_single_event.
	 *
	 * @param int $order_id Order id.
	 * @param int $attempt  Attempt number to execute (2 or 3).
	 */
	public function on_retry( $order_id, $attempt = 2 ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_AGREEMENT_ID ) ) {
			return; // Sent meanwhile.
		}
		$this->send( $order, (int) $attempt );
	}

	/**
	 * Send if configured, in a trigger status and not sent yet.
	 */
	public function maybe_send( \WC_Order $order ): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( self::META_AGREEMENT_ID ) ) {
			return; // Deduplication: already in KeepinCRM.
		}
		if ( ! in_array( $order->get_status(), $this->trigger_statuses(), true ) ) {
			return;
		}

		// Guard against double fire (checkout hook + status hook in one request).
		$lock = 'cckc_lock_' . $order->get_id();
		if ( get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, 30 );

		$this->send( $order, 1 );
	}

	/**
	 * Manual (re)send from the order meta box. Creates the order in KeepinCRM,
	 * or partially updates it when it is already there.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public function resend( \WC_Order $order ): array {
		if ( ! Settings::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => __( 'API-ключ KeepinCRM не задано.', 'keepincrm-sync-for-woocommerce' ),
			);
		}

		$existing = (int) $order->get_meta( self::META_AGREEMENT_ID );
		if ( $existing > 0 ) {
			// Update an existing agreement in place via PATCH /agreements/{id}.
			// Only scalar agreement fields are patched — resending jobs_attributes
			// or client_attributes would duplicate line items / the client in
			// KeepinCRM ("Товар вже зайнятий").
			$fields = OrderMapper::build( $order );
			unset( $fields['jobs_attributes'], $fields['client_attributes'], $fields['products_total_as_total'] );
			$client = new Client();
			$res    = $client->update_agreement( $existing, $fields );

			Logger::log( $order->get_id(), 'update', 1, $res['status'], $res['ok'] ? 'OK' : $res['error'] . ' ' . $res['body'], $res['ok'] );

			if ( $res['ok'] ) {
				$order->update_meta_data( self::META_STATUS, 'sent' );
				$order->delete_meta_data( self::META_LAST_ERROR );
				$order->save();
				return array(
					'ok'      => true,
					/* translators: %d — KeepinCRM order id. */
					'message' => sprintf( __( 'Замовлення оновлено в KeepinCRM (ID %d).', 'keepincrm-sync-for-woocommerce' ), $existing ),
				);
			}
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}

		$ok = $this->send( $order, 1, false );
		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Замовлення відправлено в KeepinCRM.', 'keepincrm-sync-for-woocommerce' )
				: (string) $order->get_meta( self::META_LAST_ERROR ),
		);
	}

	/**
	 * Perform one send attempt.
	 *
	 * @param \WC_Order $order          Order.
	 * @param int       $attempt        Attempt number (1..3).
	 * @param bool      $schedule_retry Whether to schedule the next attempt on failure.
	 * @return bool Success.
	 */
	private function send( \WC_Order $order, int $attempt, bool $schedule_retry = true ): bool {
		$payload = OrderMapper::build( $order );
		$client  = new Client();
		$res     = $client->create_agreement( $payload );

		// KeepinCRM returns 201 with {"id":N,"created_at":...}.
		$keepincrm_id = 0;
		if ( $res['ok'] && is_array( $res['json'] ) ) {
			$keepincrm_id = (int) ( $res['json']['id'] ?? 0 );
		}

		if ( $keepincrm_id > 0 ) {
			$order->update_meta_data( self::META_AGREEMENT_ID, (string) $keepincrm_id );
			$order->update_meta_data( self::META_STATUS, 'sent' );
			$order->update_meta_data( self::META_SENT_AT, current_time( 'mysql' ) );
			$order->delete_meta_data( self::META_LAST_ERROR );
			$order->save();

			/* translators: %d — KeepinCRM order id. */
			$order->add_order_note( sprintf( __( 'Замовлення відправлено в KeepinCRM, ID %d.', 'keepincrm-sync-for-woocommerce' ), $keepincrm_id ) );
			Logger::log( $order->get_id(), 'create', $attempt, $res['status'], 'KeepinCRM ID ' . $keepincrm_id, true );

			/**
			 * Fires after an order is successfully created in KeepinCRM.
			 *
			 * @param \WC_Order $order     Order.
			 * @param int       $keepincrm_id KeepinCRM order id.
			 */
			do_action( 'cc_keepincrm_order_sent', $order, $keepincrm_id );
			return true;
		}

		$error = trim( $res['error'] . ' ' . mb_substr( $res['body'], 0, 500 ) );
		$order->update_meta_data( self::META_STATUS, 'failed' );
		$order->update_meta_data( self::META_LAST_ERROR, $error );
		$order->save();
		Logger::log( $order->get_id(), 'create', $attempt, $res['status'], $error, false );

		if ( $schedule_retry && $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::BACKOFF[ $attempt - 1 ] ?? 7200;
			wp_schedule_single_event( time() + $delay, 'cc_keepincrm_retry_send', array( $order->get_id(), $attempt + 1 ) );
			Logger::log( $order->get_id(), 'retry_scheduled', $attempt, null, sprintf( 'attempt %d in %d s', $attempt + 1, $delay ), true );
		}

		return false;
	}

	/**
	 * @return string[] WC status slugs without the "wc-" prefix.
	 */
	private function trigger_statuses(): array {
		$statuses = Settings::get( 'trigger_statuses', array() );
		if ( ! is_array( $statuses ) ) {
			$statuses = array();
		}
		return array_values( array_filter( array_map( 'strval', $statuses ) ) );
	}
}
