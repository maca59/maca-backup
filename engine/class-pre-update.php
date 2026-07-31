<?php
/**
 * Pre-update automatic backups.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates scoped backups before WP core / plugin / theme updates.
 */
class Maca_Backup_Pro_Pre_Update {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_filter( 'upgrader_pre_install', array( __CLASS__, 'before_install' ), 10, 2 );
		add_action( 'automatic_updates_complete', array( __CLASS__, 'after_auto' ), 10, 1 );
	}

	/**
	 * Before a package is installed.
	 *
	 * @param bool|WP_Error $response Response.
	 * @param array         $hook_extra Extra data.
	 * @return bool|WP_Error
	 */
	public static function before_install( $response, $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! Maca_Backup_Pro_Settings::get( 'pre_update_backup', false ) ) {
			return $response;
		}
		if ( Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) {
			return $response;
		}
		// One pre-update backup per hour — bulk/auto updates must not spam full jobs.
		if ( get_transient( 'maca_backup_pro_pre_update_cool' ) ) {
			return $response;
		}

		$scope = 'wp-content';
		$type  = 'files';
		if ( ! empty( $hook_extra['plugin'] ) ) {
			$scope = 'plugins';
		} elseif ( ! empty( $hook_extra['theme'] ) ) {
			$scope = 'themes';
		} elseif ( ! empty( $hook_extra['language_update_type'] ) ) {
			return $response;
		} else {
			// Core update — include DB.
			$type  = 'full';
			$scope = 'full';
		}

		// Skip when overlapping another backup (database-only may still run beside files).
		$probe = Maca_Backup_Pro_Backup_Engine::can_start( $type, array( 'scope' => $scope ) );
		if ( is_wp_error( $probe ) ) {
			return $response;
		}

		$result = Maca_Backup_Pro_Backup_Engine::start(
			$type,
			array(
				'scope'      => $scope,
				'pre_update' => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			Maca_Backup_Pro_Logger::error(
				__( 'Pre-update backup failed.', 'maca-backup-pro' ) . ' ' . $result->get_error_message()
			);
			return $response;
		}

		set_transient( 'maca_backup_pro_pre_update_cool', 1, HOUR_IN_SECONDS );

		// Process a few chunks synchronously so something exists before the upgrade continues.
		$job_id = (int) ( $result['job_id'] ?? 0 );
		for ( $i = 0; $i < 8; $i++ ) {
			$tick = Maca_Backup_Pro_Backup_Engine::process( $job_id );
			if ( ! empty( $tick['done'] ) ) {
				break;
			}
		}

		Maca_Backup_Pro_Logger::info(
			__( 'Pre-update backup started.', 'maca-backup-pro' ),
			array(
				'job_id' => $job_id,
				'scope'  => $scope,
			)
		);

		self::prune_pre_update();

		return $response;
	}

	/**
	 * After automatic updates (best-effort logging).
	 *
	 * @param array $results Results.
	 * @return void
	 */
	public static function after_auto( $results ): void {
		unset( $results );
		self::prune_pre_update();
	}

	/**
	 * Keep only N pre-update backups (by retention setting).
	 *
	 * @return void
	 */
	private static function prune_pre_update(): void {
		$keep = max( 1, (int) Maca_Backup_Pro_Settings::get( 'pre_update_retention', 5 ) );
		$rows = Maca_Backup_Pro_Backups_Table::recent_completed( 100 );
		$pre  = array();
		foreach ( $rows as $row ) {
			$manifest = json_decode( (string) ( $row->manifest ?? '' ), true );
			// Heuristic: short-lived scoped backups around updates — keep newest N overall if tagged.
			if ( ! empty( $manifest['pre_update'] ) || str_contains( (string) $row->backup_key, '_pre_' ) ) {
				$pre[] = $row;
			}
		}
		if ( count( $pre ) <= $keep ) {
			return;
		}
		$drop = array_slice( $pre, $keep );
		foreach ( $drop as $row ) {
			Maca_Backup_Pro_Backup_Engine::delete_backup( (int) $row->id );
		}
	}
}
