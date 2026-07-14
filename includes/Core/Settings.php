<?php
/**
 * Settings repository. Transparently encrypts the API key at rest.
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync\Core;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const SECRET_KEYS = array( 'api_key' );

	/** @var array|null */
	private static $cache = null;

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( 'cc_keepincrm_settings', Installer::default_settings() );
		if ( ! is_array( $raw ) ) {
			$raw = Installer::default_settings();
		}
		$raw = wp_parse_args( $raw, Installer::default_settings() );

		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $raw[ $secret ] ) && '' !== $raw[ $secret ] ) {
				$raw[ $secret ] = Crypto::decrypt( (string) $raw[ $secret ] );
			}
		}

		if ( ! is_array( $raw['trigger_statuses'] ) ) {
			$raw['trigger_statuses'] = Installer::default_settings()['trigger_statuses'];
		}

		self::$cache = $raw;
		return $raw;
	}

	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function save( array $values ): void {
		foreach ( self::SECRET_KEYS as $secret ) {
			if ( isset( $values[ $secret ] ) && '' !== $values[ $secret ] ) {
				$values[ $secret ] = Crypto::encrypt( (string) $values[ $secret ] );
			}
		}
		update_option( 'cc_keepincrm_settings', $values, true );
		self::$cache = null;
	}

	public static function is_configured(): bool {
		return '' !== (string) self::get( 'api_key', '' );
	}
}
