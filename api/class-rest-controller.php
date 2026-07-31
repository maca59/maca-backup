<?php
/**
 * REST API controller.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes under maca-backup-pro/v1.
 */
class Maca_Backup_Pro_REST_Controller {

	public const NAMESPACE = 'maca-backup-pro/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_backups' ),
				'permission_callback' => array( 'Maca_Backup_Pro_Security', 'rest_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/backups',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_backup' ),
				'permission_callback' => array( 'Maca_Backup_Pro_Security', 'rest_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( 'Maca_Backup_Pro_Security', 'rest_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/hub',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'hub_status' ),
				'permission_callback' => array( 'Maca_Backup_Pro_Security', 'rest_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/verify/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'verify_backup' ),
				'permission_callback' => array( 'Maca_Backup_Pro_Security', 'rest_permission' ),
			)
		);
	}

	/**
	 * List backups.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_backups(): WP_REST_Response {
		return new WP_REST_Response( Maca_Backup_Pro_Backups_Table::recent( 50 ), 200 );
	}

	/**
	 * Create backup.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_backup( WP_REST_Request $request ) {
		$type   = sanitize_key( (string) $request->get_param( 'type' ) ) ?: 'full';
		$result = Maca_Backup_Pro_Backup_Engine::start( $type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * Dashboard status payload.
	 *
	 * @return \WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		$latest   = Maca_Backup_Pro_Backups_Table::latest_completed();
		$provider = Maca_Backup_Pro_Storage_Registry::instance()->get(
			(string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' )
		);
		$space    = $provider ? $provider->space_info() : null;
		$next     = Maca_Backup_Pro_Scheduler::instance()->next_run();
		$job      = Maca_Backup_Pro_Jobs_Table::active( 'backup' );

		return new WP_REST_Response(
			array(
				'latest'       => $latest,
				'count'        => Maca_Backup_Pro_Backups_Table::count_completed(),
				'total_size'   => Maca_Backup_Pro_Backups_Table::total_size(),
				'storage'      => $provider ? $provider->label() : 'local',
				'space'        => $space,
				'next_backup'  => $next,
				'active_job'   => $job,
			),
			200
		);
	}

	/**
	 * Smart Restore compare.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function smart_compare( WP_REST_Request $request ) {
		$result = Maca_Backup_Pro_Smart_Restore::compare( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Hub monitoring payload.
	 *
	 * @return \WP_REST_Response
	 */
	public function hub_status(): WP_REST_Response {
		$latest = Maca_Backup_Pro_Backups_Table::latest_completed();
		$job    = Maca_Backup_Pro_Jobs_Table::active( 'backup' ) ?: Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		return new WP_REST_Response(
			array(
				'version'      => MACA_BACKUP_PRO_VERSION,
				'site_url'     => home_url(),
				'latest'       => $latest,
				'count'        => Maca_Backup_Pro_Backups_Table::count_completed(),
				'total_size'   => Maca_Backup_Pro_Backups_Table::total_size(),
				'storage'      => (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' ),
				'active_job'   => $job,
				'hub_enabled'  => (bool) Maca_Backup_Pro_Settings::get( 'hub_enabled', false ),
				'last_heartbeat' => (int) get_option( 'maca_backup_pro_hub_last_heartbeat', 0 ),
			),
			200
		);
	}

	/**
	 * Verify backup integrity via temporary extract.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function verify_backup( WP_REST_Request $request ) {
		$result = Maca_Backup_Pro_Staging::verify( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return new WP_REST_Response( $result, 200 );
	}
}
