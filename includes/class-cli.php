<?php
/**
 * WP-CLI commands for maca BackUp.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * Manage backups from the command line.
 */
class Maca_Backup_Pro_CLI {

	/**
	 * Register commands.
	 *
	 * @return void
	 */
	public static function register(): void {
		WP_CLI::add_command( 'maca-backup', __CLASS__ );
	}

	/**
	 * Create a backup.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : full|database|files|incremental|differential
	 * ---
	 * default: full
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp maca-backup backup --type=full
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function backup( $args, $assoc_args ): void {
		$type   = sanitize_key( $assoc_args['type'] ?? 'full' );
		$result = Maca_Backup_Pro_Backup_Engine::start( $type );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$job_id = (int) $result['job_id'];
		WP_CLI::log( sprintf( 'Backup job #%d started (%s)…', $job_id, $type ) );
		self::run_job( $job_id, 'backup' );
	}

	/**
	 * List recent backups.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Number of rows
	 * ---
	 * default: 20
	 * ---
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function list( $args, $assoc_args ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.listFound
		$limit = max( 1, (int) ( $assoc_args['limit'] ?? 20 ) );
		$rows  = Maca_Backup_Pro_Backups_Table::recent( $limit );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'ID'     => (int) $row->id,
				'type'   => (string) $row->type,
				'status' => (string) $row->status,
				'size'   => size_format( (int) $row->size_bytes ),
				'parent' => (int) ( $row->parent_backup_id ?? 0 ),
				'when'   => (string) ( $row->finished_at ?: $row->created_at ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'ID', 'type', 'status', 'size', 'parent', 'when' ) );
	}

	/**
	 * Restore a backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID
	 *
	 * [--scope=<scope>]
	 * : full|database|wp-content|uploads|plugins|themes|path
	 * ---
	 * default: full
	 * ---
	 *
	 * [--path=<path>]
	 * : Relative file/folder when scope=path (repeatable via comma list)
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ): void {
		$id    = (int) ( $args[0] ?? 0 );
		$scope = sanitize_key( $assoc_args['scope'] ?? 'full' );
		$opts  = array();
		if ( ! empty( $assoc_args['path'] ) ) {
			$scope                 = 'path';
			$opts['selected_files'] = array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['path'] ) ) );
		}
		$result = Maca_Backup_Pro_Restore_Engine::start( $id, $scope, $opts );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		self::run_job( (int) $result['job_id'], 'restore' );
	}

	/**
	 * Smart compare a backup against the live site.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID
	 *
	 * @param array $args Positional.
	 * @return void
	 */
	public function smart_compare( $args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = Maca_Backup_Pro_Smart_Restore::compare( $id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		$s = $result['summary'] ?? array();
		WP_CLI::success(
			sprintf(
				'new=%d changed=%d unchanged=%d deleted=%d',
				(int) ( $s['new'] ?? 0 ),
				(int) ( $s['changed'] ?? 0 ),
				(int) ( $s['unchanged'] ?? 0 ),
				(int) ( $s['deleted'] ?? 0 )
			)
		);
	}

	/**
	 * Delete a backup.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID
	 *
	 * @param array $args Positional.
	 * @return void
	 */
	public function delete( $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		$ok = Maca_Backup_Pro_Backup_Engine::delete_backup( $id );
		if ( ! $ok ) {
			WP_CLI::error( 'Could not delete backup.' );
		}
		WP_CLI::success( 'Deleted backup #' . $id );
	}

	/**
	 * Verify a backup via temporary extract.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup ID
	 *
	 * @param array $args Positional.
	 * @return void
	 */
	public function verify( $args ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = Maca_Backup_Pro_Staging::verify( $id );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		if ( ! empty( $result['ok'] ) ) {
			WP_CLI::success( 'Verification passed.' );
		} else {
			WP_CLI::warning( 'Verification finished with issues.' );
			WP_CLI::log( wp_json_encode( $result['checks'] ?? array() ) );
		}
	}

	/**
	 * Process a job until done.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $type   backup|restore.
	 * @return void
	 */
	private static function run_job( int $job_id, string $type ): void {
		$progress = \WP_CLI\Utils\make_progress_bar( ucfirst( $type ), 100 );
		$last     = 0;
		while ( true ) {
			$tick = ( 'restore' === $type )
				? Maca_Backup_Pro_Restore_Engine::process( $job_id )
				: Maca_Backup_Pro_Backup_Engine::process( $job_id );
			$pct = (int) ( $tick['progress'] ?? 0 );
			if ( $pct > $last ) {
				$progress->tick( $pct - $last );
				$last = $pct;
			}
			if ( ! empty( $tick['done'] ) ) {
				break;
			}
			usleep( 200000 );
		}
		$progress->finish();
		if ( ( $tick['status'] ?? '' ) === 'failed' ) {
			WP_CLI::error( (string) ( $tick['error'] ?? 'Job failed' ) );
		}
		WP_CLI::success( ucfirst( $type ) . ' completed.' );
	}
}

Maca_Backup_Pro_CLI::register();
