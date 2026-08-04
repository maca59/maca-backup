<?php
/**
 * REST endpoints for maca Hub (maca-hub/v1 namespace).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hub REST controller for maca BackUp.
 */
class Maca_Backup_Pro_Hub_Rest {

	/**
	 * @return void
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * @return void
	 */
	public function register_routes() {
		$key_args = array(
			'maca_key' => array(
				'required'          => false,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'limit'    => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 100,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			'maca-hub/v1',
			'/backup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_site_key' ),
				'args'                => $key_args,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_site_key( $request ) {
		if ( class_exists( 'Maca_Hub_Connector' ) && Maca_Hub_Connector::request_has_valid_bearer( $request ) ) {
			return true;
		}

		$stored = maca_backup_pro_hub_get_site_key();
		if ( '' === $stored ) {
			return new WP_Error(
				'maca_no_site_key',
				__( 'No site key stored in WordPress (maca Sec Hub).', 'maca-backup' ),
				array( 'status' => 401 )
			);
		}

		$key = $this->extract_request_site_key( $request );
		if ( '' === $key ) {
			return new WP_Error(
				'maca_missing_key',
				__( 'Site key is missing.', 'maca-backup' ),
				array( 'status' => 401 )
			);
		}

		if ( ! hash_equals( $stored, $key ) ) {
			return new WP_Error(
				'maca_invalid_key',
				__( 'Invalid site key.', 'maca-backup' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return string
	 */
	private function extract_request_site_key( $request ) {
		$key = $request->get_header( 'X-Maca-Site-Key' );
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return trim( $key );
		}

		$key = $request->get_param( 'maca_key' );
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return trim( $key );
		}

		return '';
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 ) {
			$limit = 100;
		}
		return rest_ensure_response( maca_backup_pro_hub_get_status( $limit ) );
	}
}
