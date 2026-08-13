<?php
/**
 * Settings page under the WooCommerce menu — native WP admin components
 * (.form-table, .nav-tab-wrapper, submit_button). Two tabs: settings + log.
 *
 * Secrets are never echoed back; an empty submit never wipes a stored key.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync\Admin;

use CatCode\KeepincrmSync\Api\Client;
use CatCode\KeepincrmSync\Core\Installer;
use CatCode\KeepincrmSync\Core\Logger;
use CatCode\KeepincrmSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	private const SLUG = 'catcode-order-sync-with-keepincrm-for-woocommerce';

	/** @var string Hook suffix of our own admin screen. */
	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_cckc_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_cckc_test_connection', array( $this, 'handle_test' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'KeepinCRM Sync', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			__( 'KeepinCRM Sync', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap cckc-wrap">';
		echo '<h1>' . esc_html__( 'KeepinCRM Sync', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</h1>';
		echo '<p class="cckc-lead">' . esc_html__( 'Sends WooCommerce orders to KeepinCRM automatically.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';

		if ( isset( $_GET['cckc_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['cckc_err'] ) ? 'notice-error' : 'notice-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['cckc_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'log' !== $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Settings', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</a>';
		echo '<a href="' . esc_url( $base . '&tab=log' ) . '" class="nav-tab' . ( 'log' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Log', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</a>';
		echo '</h2>';

		if ( 'log' === $tab ) {
			$this->render_log();
		} else {
			$this->render_settings();
		}

		echo '</div>';
	}

	private function render_settings(): void {
		$cfg     = Settings::all();
		$has_key = '' !== (string) $cfg['api_key'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cckc_save_settings"/>';
		wp_nonce_field( 'cckc_save_settings' );

		echo '<div class="cckc-card">';
		echo '<h2>' . esc_html__( 'Connection', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="cckc-api-key">' . esc_html__( 'API token (X-Auth-Token)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" id="cckc-api-key" name="api_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $has_key ? __( '•••••• saved — type a new one to replace it', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) : '' ) . '"/>';
		echo '<p class="description">' . esc_html__( 'KeepinCRM account → Settings → Company profile → API tab (the account owner only). The token travels in the X-Auth-Token header and is stored encrypted. Note: KeepinCRM does not expose its API on the free plan.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . ( $has_key ? ' <span class="cckc-saved">' . esc_html__( 'Token saved.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</span>' : '' ) . '</p>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="cckc-card">';
		echo '<h2>' . esc_html__( 'CRM routing (optional)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$route_fields = array(
			'funnel_id'           => __( 'Funnel ID (funnel_id)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			'stage_id'            => __( 'Stage ID (stage_id)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			'source_id'           => __( 'Source ID (source_id)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
			'main_responsible_id' => __( 'Responsible user ID (main_responsible_id)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ),
		);
		foreach ( $route_fields as $key => $label ) {
			$val = (int) ( $cfg[ $key ] ?? 0 );
			echo '<tr><th scope="row"><label for="cckc-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" min="0" step="1" class="small-text" id="cckc-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val > 0 ? (string) $val : '' ) . '"/>';
			echo '</td></tr>';
		}
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Leave empty to let KeepinCRM pick its own defaults. The ids come from your account dictionaries (funnels, stages, sources, employees).', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p></td></tr>';
		echo '</table></div>';

		echo '<div class="cckc-card">';
		echo '<h2>' . esc_html__( 'Order sending', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Trigger statuses', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th><td>';
		$selected = is_array( $cfg['trigger_statuses'] ) ? $cfg['trigger_statuses'] : array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$short = 0 === strncmp( $slug, 'wc-', 3 ) ? substr( $slug, 3 ) : $slug;
			echo '<label style="display:block;margin:2px 0"><input type="checkbox" name="trigger_statuses[]" value="' . esc_attr( $short ) . '"' . checked( in_array( $short, $selected, true ), true, false ) . '/> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'An order goes to KeepinCRM right after checkout, or when it moves into one of the selected statuses — unless it is there already.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Payment status', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="pass_payment_status" value="yes"' . checked( 'yes' === $cfg['pass_payment_status'], true, false ) . '/> ' . esc_html__( 'Mark online payments (add “✅ Paid online” to the agreement comment for paid orders)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Zero-price items', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_zero_price" value="yes"' . checked( 'yes' === $cfg['skip_zero_price'], true, false ) . '/> ' . esc_html__( 'Skip line items priced at 0 (gifts, samples)', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Shipping', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="include_shipping" value="yes"' . checked( 'yes' === $cfg['include_shipping'], true, false ) . '/> ' . esc_html__( 'Send the shipping cost as a separate agreement line', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="cckc-actions">';
		submit_button( __( 'Save settings', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), 'primary large', 'submit', false );
		echo '</div>';
		echo '</form>';

		// Separate small form for the connection test (does not touch settings).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px">';
		echo '<input type="hidden" name="action" value="cckc_test_connection"/>';
		wp_nonce_field( 'cckc_test_connection' );
		submit_button( __( 'Test connection', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Sends a GET /clients/statuses request with the stored token.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</span>';
		echo '</form>';
	}

	private function render_log(): void {
		$rows = Logger::latest( 100 );

		echo '<div class="cckc-card" style="margin-top:16px">';
		echo '<h2>' . esc_html__( 'Recent events', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No events yet.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Event', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Attempt', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '<th>HTTP</th>';
		echo '<th>' . esc_html__( 'Result', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$order_id = (int) $r['order_id'];
			$link     = $order_id > 0 ? admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ) : '';
			echo '<tr>';
			echo '<td>' . esc_html( (string) $r['created_at'] ) . '</td>';
			echo '<td>' . ( $order_id > 0 ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $order_id ) . '</a>' : '—' ) . '</td>';
			echo '<td>' . esc_html( (string) $r['event'] ) . '</td>';
			echo '<td>' . esc_html( (string) $r['attempt_no'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['http_status'] ?? '—' ) ) . '</td>';
			echo '<td>' . ( $r['success'] ? '<span style="color:#1a7f37;font-weight:600">OK</span>' : '<span style="color:#a00;font-weight:600">' . esc_html__( 'Error', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) . '</span>' ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( mb_substr( (string) $r['message'], 0, 200 ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) );
		}
		check_admin_referer( 'cckc_save_settings' );

		$current = Settings::all();

		$api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) ) : '';
		if ( '' === $api_key ) {
			$api_key = (string) $current['api_key']; // Empty submit keeps the stored key.
		}

		$statuses = array();
		if ( isset( $_POST['trigger_statuses'] ) && is_array( $_POST['trigger_statuses'] ) ) {
			$statuses = array_map( 'sanitize_key', wp_unslash( $_POST['trigger_statuses'] ) );
		}
		if ( empty( $statuses ) ) {
			$statuses = Installer::default_settings()['trigger_statuses'];
		}

		Settings::save(
			array(
				'api_key'             => $api_key,
				'trigger_statuses'    => $statuses,
				'pass_payment_status' => isset( $_POST['pass_payment_status'] ) ? 'yes' : 'no',
				'skip_zero_price'     => isset( $_POST['skip_zero_price'] ) ? 'yes' : 'no',
				'include_shipping'    => isset( $_POST['include_shipping'] ) ? 'yes' : 'no',
				'funnel_id'           => isset( $_POST['funnel_id'] ) ? absint( wp_unslash( $_POST['funnel_id'] ) ) : 0,
				'stage_id'            => isset( $_POST['stage_id'] ) ? absint( wp_unslash( $_POST['stage_id'] ) ) : 0,
				'source_id'           => isset( $_POST['source_id'] ) ? absint( wp_unslash( $_POST['source_id'] ) ) : 0,
				'main_responsible_id' => isset( $_POST['main_responsible_id'] ) ? absint( wp_unslash( $_POST['main_responsible_id'] ) ) : 0,
			)
		);

		$this->redirect( __( 'Settings saved.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), false );
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'catcode-order-sync-with-keepincrm-for-woocommerce' ) );
		}
		check_admin_referer( 'cckc_test_connection' );

		$client = new Client();
		$res    = $client->test_connection();

		Logger::log( 0, 'test', 1, $res['status'], $res['ok'] ? 'OK' : $res['error'] . ' ' . mb_substr( $res['body'], 0, 300 ), $res['ok'] );

		if ( $res['ok'] ) {
			/* translators: %d — HTTP status code. */
			$this->redirect( sprintf( __( 'Connection successful (HTTP %d).', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), $res['status'] ), false );
		}
		/* translators: %s — error details. */
		$this->redirect( sprintf( __( 'Connection error: %s', 'catcode-order-sync-with-keepincrm-for-woocommerce' ), $res['error'] ), true );
	}

	private function redirect( string $msg, bool $is_error ): void {
		$url = add_query_arg(
			array_filter(
				array(
					'page'     => self::SLUG,
					'cckc_msg' => rawurlencode( $msg ),
					'cckc_err' => $is_error ? '1' : null,
				)
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Enqueue scoped admin styles only on this settings screen,
	 * via a registered handle + wp_add_inline_style (no raw <style> output).
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_register_style( 'cc-keepincrm-admin', false, array(), CCKC_VERSION );
		wp_enqueue_style( 'cc-keepincrm-admin' );

		$css = '
.cckc-wrap{max-width:880px}
.cckc-wrap .cckc-lead{font-size:14px;color:#50575e;margin:.2em 0 1em}
.cckc-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 22px 18px;margin:16px 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.cckc-card>h2{font-size:15px;margin:14px 0 2px;padding:0;border:0}
.cckc-card .form-table th{padding-top:16px;padding-bottom:16px;width:220px;font-weight:600}
.cckc-card .form-table td{padding-top:14px;padding-bottom:14px}
.cckc-card .cckc-saved{color:#1a7f37;font-weight:600}
.cckc-actions{padding:4px 0 8px}
.cckc-actions .button-large{padding:6px 26px;height:auto;font-size:14px}
';
		wp_add_inline_style( 'cc-keepincrm-admin', $css );
	}
}
