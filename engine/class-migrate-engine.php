<?php
/**
 * Migrate engine — thin wrapper around Restore_Engine in migrate mode.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts a full-site migration onto the current host.
 */
class Maca_Backup_Pro_Migrate_Engine {

	/**
	 * Start a migrate job (full DB + files, URL rewrite, preserve admin).
	 *
	 * @param int $backup_id Backup ID.
	 * @return array{job_id:int}|\WP_Error
	 */
	public static function start( int $backup_id ) {
		return Maca_Backup_Pro_Restore_Engine::start(
			$backup_id,
			'full',
			array(
				'mode'             => 'migrate',
				'restore_database' => true,
			)
		);
	}
}
