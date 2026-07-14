<?php
/**
 * Plugin Name: KeepinCRM Sync for WooCommerce
 * Plugin URI: https://catcode.com.ua/plugins/keepincrm-sync-for-woocommerce
 * Description: Автоматична відправка замовлень WooCommerce у KeepinCRM: створення замовлення в CRM після checkout, дедуплікація, повторні спроби, журнал подій.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: keepincrm-sync-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 7.0
 * WC tested up to: 10.7
 *
 * @package CcKeepincrmSync
 */

defined( 'ABSPATH' ) || exit;

// Per-constant guards keep WP's activation sandbox-scrape (which includes this
// file twice) from emitting "already defined" warnings — without ever skipping
// the autoloader/hook registration below.
defined( 'CCKC_VERSION' ) || define( 'CCKC_VERSION', '0.1.0' );
defined( 'CCKC_FILE' ) || define( 'CCKC_FILE', __FILE__ );
defined( 'CCKC_DIR' ) || define( 'CCKC_DIR', plugin_dir_path( __FILE__ ) );
defined( 'CCKC_URL' ) || define( 'CCKC_URL', plugin_dir_url( __FILE__ ) );
defined( 'CCKC_BASENAME' ) || define( 'CCKC_BASENAME', plugin_basename( __FILE__ ) );

// Explicit, dependency-ordered includes. (An spl_autoload_register closure
// proved unreliable across SAPIs on some hosts, so we load the small class set
// directly — deterministic and fast.)
foreach (
	array(
		'includes/Core/Crypto.php',
		'includes/Core/Logger.php',
		'includes/Core/Settings.php',
		'includes/Core/Installer.php',
		'includes/Api/Client.php',
		'includes/OrderMapper.php',
		'includes/Sender.php',
		'includes/Admin/SettingsPage.php',
		'includes/Admin/OrderMetaBox.php',
		'includes/Core/Plugin.php',
	) as $cckc_inc
) {
	require_once CCKC_DIR . $cckc_inc;
}
unset( $cckc_inc );

register_activation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		\CatCode\KeepincrmSync\Core\Installer::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		\CatCode\KeepincrmSync\Core\Installer::deactivate();
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Для роботи KeepinCRM Sync for WooCommerce потрібен активний WooCommerce.', 'keepincrm-sync-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		\CatCode\KeepincrmSync\Core\Plugin::instance()->boot();
	}
);
