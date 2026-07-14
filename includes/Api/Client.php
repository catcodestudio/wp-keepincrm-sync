<?php
/**
 * KeepinCRM API HTTP client.
 *
 * Public REST API — a single host, token in a header (no per-account domain):
 *   Base:   https://api.keepincrm.com
 *   Auth:   header  X-Auth-Token: <token>
 *
 * Order creation posts an "agreement" (угода):
 *   POST /agreements                       -> 201 { id, created_at, updated_at }
 * Update of an existing agreement:
 *   PATCH /agreements/{id}                 -> 200
 * Cheap authenticated read used by "test connection":
 *   GET /clients/statuses                  -> 200 [ ... ]
 *
 * Docs: https://app.swaggerhub.com/apis/KeepInCRM/keepincrm-api/
 *
 * @package CcKeepincrmSync
 */

namespace CatCode\KeepincrmSync\Api;

use CatCode\KeepincrmSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Client {

	/** API base (versioned) — shared for every account; the token scopes it. */
	private const BASE = 'https://api.keepincrm.com/v1';

	/** @var string API token (X-Auth-Token). */
	private $token;

	public function __construct( ?string $token = null ) {
		$this->token = null !== $token ? trim( $token ) : (string) Settings::get( 'api_key', '' );
	}

	/**
	 * Create an agreement (order/deal) in KeepinCRM.
	 *
	 * @param array $payload Agreement body (title, total, client_attributes, jobs_attributes...).
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	public function create_agreement( array $payload ): array {
		return $this->request( 'POST', '/agreements', $payload );
	}

	/**
	 * Update an existing agreement by its KeepinCRM id.
	 *
	 * @param int   $id      KeepinCRM agreement id.
	 * @param array $payload Fields to update.
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	public function update_agreement( int $id, array $payload ): array {
		return $this->request( 'PATCH', '/agreements/' . $id, $payload );
	}

	/**
	 * Cheap authenticated read for the "test connection" button — the client
	 * status list is readable by any valid token.
	 *
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	public function test_connection(): array {
		return $this->request( 'GET', '/clients/statuses' );
	}

	/**
	 * Send a request with the X-Auth-Token header and normalise the response.
	 *
	 * @param string     $method HTTP method (GET/POST/PATCH).
	 * @param string     $path   API path beginning with a slash.
	 * @param array|null $body   JSON body for write requests.
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	private function request( string $method, string $path, ?array $body = null ): array {
		if ( '' === $this->token ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'json'   => null,
				'error'  => __( 'API-токен KeepinCRM не задано.', 'keepincrm-sync-for-woocommerce' ),
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'X-Auth-Token' => $this->token,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$response = wp_remote_request( self::BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'json'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		$json    = is_array( $decoded ) ? $decoded : null;

		// KeepinCRM: 2xx = success (201 on create). 401 = bad token, 422 = validation
		// error with a ValidationError body ({ message, errors:{field:[...]} }).
		$ok = $status >= 200 && $status < 300;

		$error = '';
		if ( ! $ok ) {
			$error = self::extract_error( $json, $status );
		}

		return array(
			'ok'     => $ok,
			'status' => $status,
			'body'   => $raw,
			'json'   => $json,
			'error'  => $error,
		);
	}

	/** Build a human-readable error string from a KeepinCRM error body. */
	private static function extract_error( ?array $json, int $status ): string {
		if ( is_array( $json ) ) {
			if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$parts = array();
				foreach ( $json['errors'] as $field => $messages ) {
					$msg = is_array( $messages ) ? implode( ', ', array_map( 'strval', $messages ) ) : (string) $messages;
					// Flat list (["Invalid auth token"]) — no field label; keyed
					// object ({"total":["is required"]}) — prefix with the field.
					$parts[] = is_int( $field ) ? $msg : $field . ': ' . $msg;
				}
				if ( $parts ) {
					return implode( '; ', $parts );
				}
			}
			if ( ! empty( $json['message'] ) && is_scalar( $json['message'] ) ) {
				return (string) $json['message'];
			}
			if ( ! empty( $json['error'] ) && is_scalar( $json['error'] ) ) {
				return (string) $json['error'];
			}
		}
		if ( 401 === $status ) {
			return __( 'Невірний API-токен (HTTP 401).', 'keepincrm-sync-for-woocommerce' );
		}
		return 'HTTP ' . $status;
	}
}
