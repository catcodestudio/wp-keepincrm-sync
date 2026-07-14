<?php
/**
 * Uninstall handler. Removes options. The log table is kept (audit data).
 *
 * @package CcKeepincrmSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'cc_keepincrm_settings' );
delete_option( 'cc_keepincrm_version' );
delete_option( 'cc_keepincrm_crypto_key' );
