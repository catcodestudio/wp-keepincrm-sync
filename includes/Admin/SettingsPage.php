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

	private const SLUG = 'keepincrm-sync-for-woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_cckc_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_cckc_test_connection', array( $this, 'handle_test' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'KeepinCRM Sync', 'keepincrm-sync-for-woocommerce' ),
			__( 'KeepinCRM Sync', 'keepincrm-sync-for-woocommerce' ),
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

		echo '<div class="wrap ccks-wrap">';
		echo '<h1>' . esc_html__( 'KeepinCRM Sync', 'keepincrm-sync-for-woocommerce' ) . '</h1>';
		echo '<p class="ccks-lead">' . esc_html__( 'Автоматична відправка замовлень WooCommerce у KeepinCRM.', 'keepincrm-sync-for-woocommerce' ) . '</p>';

		if ( isset( $_GET['cckc_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type = isset( $_GET['cckc_err'] ) ? 'notice-error' : 'notice-success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['cckc_msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$base = admin_url( 'admin.php?page=' . self::SLUG );
		echo '<h2 class="nav-tab-wrapper">';
		echo '<a href="' . esc_url( $base ) . '" class="nav-tab' . ( 'log' !== $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Налаштування', 'keepincrm-sync-for-woocommerce' ) . '</a>';
		echo '<a href="' . esc_url( $base . '&tab=log' ) . '" class="nav-tab' . ( 'log' === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html__( 'Журнал', 'keepincrm-sync-for-woocommerce' ) . '</a>';
		echo '</h2>';

		if ( 'log' === $tab ) {
			$this->render_log();
		} else {
			$this->render_settings();
		}

		$this->assets();
		echo '</div>';
	}

	private function render_settings(): void {
		$cfg     = Settings::all();
		$has_key = '' !== (string) $cfg['api_key'];

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cckc_save_settings"/>';
		wp_nonce_field( 'cckc_save_settings' );

		echo '<div class="ccks-card">';
		echo '<h2>' . esc_html__( 'Підключення', 'keepincrm-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="ccks-api-key">' . esc_html__( 'API-токен (X-Auth-Token)', 'keepincrm-sync-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" id="ccks-api-key" name="api_key" value="" autocomplete="new-password" placeholder="' . esc_attr( $has_key ? __( '•••••• збережено — введіть щоб замінити', 'keepincrm-sync-for-woocommerce' ) : '' ) . '"/>';
		echo '<p class="description">' . esc_html__( 'Кабінет KeepinCRM → Налаштування → Інтеграції → API. Токен передається у заголовку X-Auth-Token і зберігається у зашифрованому вигляді.', 'keepincrm-sync-for-woocommerce' ) . ( $has_key ? ' <span class="ccks-saved">' . esc_html__( 'Токен збережено.', 'keepincrm-sync-for-woocommerce' ) . '</span>' : '' ) . '</p>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="ccks-card">';
		echo '<h2>' . esc_html__( 'Маршрутизація в CRM (необов’язково)', 'keepincrm-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';
		$route_fields = array(
			'funnel_id'           => __( 'ID воронки (funnel_id)', 'keepincrm-sync-for-woocommerce' ),
			'stage_id'            => __( 'ID етапу (stage_id)', 'keepincrm-sync-for-woocommerce' ),
			'source_id'           => __( 'ID джерела (source_id)', 'keepincrm-sync-for-woocommerce' ),
			'main_responsible_id' => __( 'ID відповідального (main_responsible_id)', 'keepincrm-sync-for-woocommerce' ),
		);
		foreach ( $route_fields as $key => $label ) {
			$val = (int) ( $cfg[ $key ] ?? 0 );
			echo '<tr><th scope="row"><label for="ccks-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="number" min="0" step="1" class="small-text" id="ccks-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val > 0 ? (string) $val : '' ) . '"/>';
			echo '</td></tr>';
		}
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Залиште порожнім, щоб KeepinCRM обрав значення за замовчуванням. Значення беруться з довідників кабінету (воронки, етапи, джерела, працівники).', 'keepincrm-sync-for-woocommerce' ) . '</p></td></tr>';
		echo '</table></div>';

		echo '<div class="ccks-card">';
		echo '<h2>' . esc_html__( 'Відправка замовлень', 'keepincrm-sync-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Тригерні статуси', 'keepincrm-sync-for-woocommerce' ) . '</th><td>';
		$selected = is_array( $cfg['trigger_statuses'] ) ? $cfg['trigger_statuses'] : array();
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$short = 0 === strncmp( $slug, 'wc-', 3 ) ? substr( $slug, 3 ) : $slug;
			echo '<label style="display:block;margin:2px 0"><input type="checkbox" name="trigger_statuses[]" value="' . esc_attr( $short ) . '"' . checked( in_array( $short, $selected, true ), true, false ) . '/> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Замовлення відправляється в KeepinCRM одразу після checkout або при переході в один із вибраних статусів (якщо ще не відправлено).', 'keepincrm-sync-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Статус оплати', 'keepincrm-sync-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="pass_payment_status" value="yes"' . checked( 'yes' === $cfg['pass_payment_status'], true, false ) . '/> ' . esc_html__( 'Позначати оплату онлайн (для сплачених замовлень додавати «✅ Оплачено онлайн» у коментар заявки)', 'keepincrm-sync-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Товари з нульовою ціною', 'keepincrm-sync-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="skip_zero_price" value="yes"' . checked( 'yes' === $cfg['skip_zero_price'], true, false ) . '/> ' . esc_html__( 'Пропускати позиції з ціною 0 (подарунки, семпли)', 'keepincrm-sync-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Доставка', 'keepincrm-sync-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="include_shipping" value="yes"' . checked( 'yes' === $cfg['include_shipping'], true, false ) . '/> ' . esc_html__( 'Передавати вартість доставки (shipping_costs)', 'keepincrm-sync-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '</table></div>';

		echo '<div class="ccks-actions">';
		submit_button( __( 'Зберегти налаштування', 'keepincrm-sync-for-woocommerce' ), 'primary large', 'submit', false );
		echo '</div>';
		echo '</form>';

		// Separate small form for the connection test (does not touch settings).
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:4px">';
		echo '<input type="hidden" name="action" value="cckc_test_connection"/>';
		wp_nonce_field( 'cckc_test_connection' );
		submit_button( __( 'Перевірити з’єднання', 'keepincrm-sync-for-woocommerce' ), 'secondary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'Виконує запит GET /clients/statuses зі збереженим токеном.', 'keepincrm-sync-for-woocommerce' ) . '</span>';
		echo '</form>';
	}

	private function render_log(): void {
		$rows = Logger::latest( 100 );

		echo '<div class="ccks-card" style="margin-top:16px">';
		echo '<h2>' . esc_html__( 'Останні події', 'keepincrm-sync-for-woocommerce' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'Подій ще немає.', 'keepincrm-sync-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Дата', 'keepincrm-sync-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Замовлення', 'keepincrm-sync-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Подія', 'keepincrm-sync-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Спроба', 'keepincrm-sync-for-woocommerce' ) . '</th>';
		echo '<th>HTTP</th>';
		echo '<th>' . esc_html__( 'Результат', 'keepincrm-sync-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Повідомлення', 'keepincrm-sync-for-woocommerce' ) . '</th>';
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
			echo '<td>' . ( $r['success'] ? '<span style="color:#1a7f37;font-weight:600">OK</span>' : '<span style="color:#a00;font-weight:600">' . esc_html__( 'Помилка', 'keepincrm-sync-for-woocommerce' ) . '</span>' ) . '</td>';
			echo '<td><code style="font-size:11px">' . esc_html( mb_substr( (string) $r['message'], 0, 200 ) ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Немає прав', 'keepincrm-sync-for-woocommerce' ) );
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

		$this->redirect( __( 'Налаштування збережено.', 'keepincrm-sync-for-woocommerce' ), false );
	}

	public function handle_test(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Немає прав', 'keepincrm-sync-for-woocommerce' ) );
		}
		check_admin_referer( 'cckc_test_connection' );

		$client = new Client();
		$res    = $client->test_connection();

		Logger::log( 0, 'test', 1, $res['status'], $res['ok'] ? 'OK' : $res['error'] . ' ' . mb_substr( $res['body'], 0, 300 ), $res['ok'] );

		if ( $res['ok'] ) {
			/* translators: %d — HTTP status code. */
			$this->redirect( sprintf( __( 'З’єднання успішне (HTTP %d).', 'keepincrm-sync-for-woocommerce' ), $res['status'] ), false );
		}
		/* translators: %s — error details. */
		$this->redirect( sprintf( __( 'Помилка з’єднання: %s', 'keepincrm-sync-for-woocommerce' ), $res['error'] ), true );
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
	 * Scoped styles printed only on this screen.
	 */
	private function assets(): void {
		?>
<style>
.ccks-wrap{max-width:880px}
.ccks-wrap .ccks-lead{font-size:14px;color:#50575e;margin:.2em 0 1em}
.ccks-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 22px 18px;margin:16px 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.ccks-card>h2{font-size:15px;margin:14px 0 2px;padding:0;border:0}
.ccks-card .form-table th{padding-top:16px;padding-bottom:16px;width:220px;font-weight:600}
.ccks-card .form-table td{padding-top:14px;padding-bottom:14px}
.ccks-card .ccks-saved{color:#1a7f37;font-weight:600}
.ccks-actions{padding:4px 0 8px}
.ccks-actions .button-large{padding:6px 26px;height:auto;font-size:14px}
</style>
		<?php
	}
}
