<?php
/**
 * Installer. Creates the log table and default options.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync\Core;

defined( 'ABSPATH' ) || exit;

class Installer {

	public static function activate(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$log = "CREATE TABLE {$prefix}cckc_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			event VARCHAR(32) NOT NULL,
			attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			http_status SMALLINT UNSIGNED DEFAULT NULL,
			message TEXT NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY order_id (order_id),
			KEY event (event)
		) {$charset};";

		dbDelta( $log );

		if ( false === get_option( 'cc_keepincrm_settings' ) ) {
			add_option( 'cc_keepincrm_settings', self::default_settings(), '', true );
		}
		update_option( 'cc_keepincrm_version', CCKC_VERSION, false );
	}

	public static function deactivate(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( 'cc_keepincrm_retry_send' );
		}
	}

	public static function default_settings(): array {
		return array(
			'api_key'             => '',
			'trigger_statuses'    => array( 'pending', 'processing', 'on-hold' ),
			'pass_payment_status' => 'yes',
			'skip_zero_price'     => 'yes',
			'include_shipping'    => 'yes',
			'funnel_id'           => 0,
			'stage_id'            => 0,
			'source_id'           => 0,
			'main_responsible_id' => 0,
		);
	}
}
