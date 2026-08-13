<?php
/**
 * Meta box on the WooCommerce order edit screen (HPOS + legacy).
 * Shows KeepinCRM sync status and a "resend" button.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync\Admin;

use CatCode\KeepincrmSync\Sender;

defined( 'ABSPATH' ) || exit;

class OrderMetaBox {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'admin_post_cckc_resend', array( $this, 'handle_resend' ) );
	}

	public function register(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box(
			'cckc_sync_box',
			__( 'KeepinCRM', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : (int) $post_or_order );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
			return;
		}

		$keepincrm_id = (string) $order->get_meta( Sender::META_AGREEMENT_ID );
		$status    = (string) $order->get_meta( Sender::META_STATUS );
		$error     = (string) $order->get_meta( Sender::META_LAST_ERROR );
		$sent_at   = (string) $order->get_meta( Sender::META_SENT_AT );

		if ( '' !== $keepincrm_id ) {
			echo '<p style="color:#1a7f37;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Sent to KeepinCRM', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
			echo '<p style="margin:4px 0"><strong>' . esc_html__( 'KeepinCRM ID:', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</strong> <code>' . esc_html( $keepincrm_id ) . '</code></p>';
			if ( '' !== $sent_at ) {
				echo '<p style="margin:4px 0"><strong>' . esc_html__( 'Sent at:', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</strong> ' . esc_html( $sent_at ) . '</p>';
			}
			echo '<p style="font-size:12px;color:#666;margin:8px 0 0">' . esc_html__( 'Resending updates the existing agreement in KeepinCRM (PATCH).', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
		} elseif ( 'failed' === $status ) {
			echo '<p style="color:#a00;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Send failed', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
			if ( '' !== $error ) {
				echo '<p style="margin:4px 0;font-size:12px"><code>' . esc_html( mb_substr( $error, 0, 200 ) ) . '</code></p>';
			}
		} else {
			echo '<p style="color:#666;font-weight:600;margin:0 0 6px"><span class="dashicons dashicons-minus"></span> ' . esc_html__( 'Not sent yet', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
		}

		// KeepinCRM keeps no external id on agreements, so an attempt that got
		// no answer at all cannot be reconciled automatically — say so instead
		// of letting a possible duplicate sit there unnoticed.
		if ( '' !== (string) $order->get_meta( Sender::META_UNCERTAIN ) ) {
			echo '<p style="margin:6px 0;font-size:12px;color:#8a6d3b">' . esc_html__( 'One attempt got no response from KeepinCRM (timeout). The agreement may have been created anyway — check the CRM before resending.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
		}

		// A metabox is rendered INSIDE the WooCommerce order form, and browsers
		// drop a nested <form> — the button then submitted the order form and
		// the resend never happened. A nonced link has no such problem.
		$resend_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'cckc_resend',
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'cckc_resend_' . $order->get_id()
		);
		echo '<p style="margin:8px 0 0"><a class="button button-primary" style="width:100%;text-align:center" href="' . esc_url( $resend_url ) . '">'
			. esc_html( '' === $keepincrm_id ? __( 'Send to KeepinCRM', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) : __( 'Resend', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) )
			. '</a></p>';
		echo '<p style="font-size:12px;color:#666;margin:8px 0 0">' . wp_kses_post(
			sprintf(
				/* translators: %s — link to the log page. */
				__( 'Event log: <a href="%s">WooCommerce → KeepinCRM Sync</a>.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
				esc_url( admin_url( 'admin.php?page=catcode-order-sync-with-keepincrm-for-woocommerce&tab=log' ) )
			)
		) . '</p>';
	}

	public function handle_resend(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( 'cckc_resend_' . $order_id );

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( $order ) {
			( new Sender() )->resend( $order );
		}

		// Back where the manager pressed the button — HPOS and the legacy
		// post.php screen have different URLs.
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}
		wp_safe_redirect( $back );
		exit;
	}
}
