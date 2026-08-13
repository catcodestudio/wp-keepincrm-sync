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
	/** Set when an attempt got no HTTP response at all — the agreement may still exist in the CRM. */
	public const META_UNCERTAIN  = '_cc_keepincrm_uncertain';

	private const MAX_ATTEMPTS = 3;

	/** Seconds after which a lock left by a dead request is considered stale. */
	private const LOCK_TTL = 120;

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

		// Checkout hook, status hook and a payment callback can land on the
		// same order in parallel. A transient guard is a read-then-write, so
		// both requests pass it and KeepinCRM gets the order twice; the raw
		// INSERT below lets exactly one through.
		$lock = 'cc_keepincrm_lock_' . $order->get_id();
		if ( ! $this->lock_acquire( $lock ) ) {
			return;
		}

		try {
			// The winner may have written while we were taking the lock —
			// decide on fresh state, not on our in-request copy.
			$order = self::reload_order( $order->get_id(), $order );
			if ( '' !== (string) $order->get_meta( self::META_AGREEMENT_ID ) ) {
				return;
			}
			$this->send( $order, 1 );
		} finally {
			$this->lock_release( $lock );
		}
	}

	/**
	 * Take the lock, or fail if somebody else holds it.
	 *
	 * add_option() cannot be used here: WordPress runs it as
	 * INSERT ... ON DUPLICATE KEY UPDATE, so it reports success even when the
	 * row already exists and both requests believe they hold the lock.
	 */
	private function lock_acquire( string $name ): bool {
		global $wpdb;

		// A request that died mid-flight must not block the order forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d', $wpdb->options, $name, time() - self::LOCK_TTL ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $wpdb->options, $name, (string) time() ) );

		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return (int) $rows > 0;
	}

	private function lock_release( string $name ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lock row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Re-read an order past every cache layer, including the HPOS order cache
	 * that classic invalidation does not touch.
	 */
	private static function reload_order( int $order_id, \WC_Order $fallback ): \WC_Order {
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( $order_id, 'order-items' );
		clean_post_cache( $order_id );

		if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
			try {
				wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
			} catch ( \Throwable $e ) {
				unset( $e ); // Older WooCommerce without the order cache in its container.
			}
		}

		$fresh = wc_get_order( $order_id );

		return $fresh instanceof \WC_Order ? $fresh : $fallback;
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
				'message' => __( 'The KeepinCRM API token is not set.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
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
					'message' => sprintf( __( 'Order updated in KeepinCRM (ID %d).', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), $existing ),
				);
			}
			return array(
				'ok'      => false,
				'message' => $res['error'],
			);
		}

		// Two managers pressing "Send" at the same moment must not create two
		// agreements either — the create path takes the same lock as the hooks.
		$lock = 'cc_keepincrm_lock_' . $order->get_id();
		if ( ! $this->lock_acquire( $lock ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'This order is already being sent to KeepinCRM. Reload the page in a few seconds.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			);
		}

		try {
			$order = self::reload_order( $order->get_id(), $order );
			if ( (int) $order->get_meta( self::META_AGREEMENT_ID ) > 0 ) {
				return array(
					'ok'      => true,
					/* translators: %d — KeepinCRM order id. */
					'message' => sprintf( __( 'Order updated in KeepinCRM (ID %d).', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), (int) $order->get_meta( self::META_AGREEMENT_ID ) ),
				);
			}
			$ok = $this->send( $order, 1, false );
		} finally {
			$this->lock_release( $lock );
		}

		return array(
			'ok'      => $ok,
			'message' => $ok
				? __( 'Order sent to KeepinCRM.', 'catcode-order-sync-with-keepincrm-for-woocommerce' )
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
			$order->add_order_note( sprintf( __( 'Order sent to KeepinCRM, agreement ID %d.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), $keepincrm_id ) );
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

		// Status 0 = the request never came back (timeout, dropped connection).
		// KeepinCRM has no external id on agreements, so we cannot ask it
		// whether this order landed — the retry below may create a second
		// agreement. Flag it so the shop owner can check instead of finding
		// the duplicate in the CRM later.
		$uncertain = 0 === (int) $res['status'];
		if ( $uncertain ) {
			$order->update_meta_data( self::META_UNCERTAIN, '1' );
		}
		$order->save();
		Logger::log( $order->get_id(), $uncertain ? 'no_response' : 'create', $attempt, $res['status'], $error, false );

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
