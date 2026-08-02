<?php
/**
 * Jobs table for chunked backup/restore.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be prepared placeholders; queries use validated identifiers.

/**
 * Background job state.
 */
class Maca_Backup_Pro_Jobs_Table {

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'maca_backup_jobs';
	}

	/**
	 * Create table.
	 *
	 * @return void
	 */
	public static function create(): void {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(32) NOT NULL DEFAULT 'backup',
			status varchar(32) NOT NULL DEFAULT 'pending',
			backup_id bigint(20) unsigned NOT NULL DEFAULT 0,
			progress int(11) NOT NULL DEFAULT 0,
			step varchar(64) NOT NULL DEFAULT '',
			state longtext NULL,
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY backup_id (backup_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Insert job.
	 *
	 * @param array<string, mixed> $data Row.
	 * @return int
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$data = array_merge(
			array(
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		$wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update job.
	 *
	 * @param int                  $id   Job ID.
	 * @param array<string, mixed> $data Fields.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$result             = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table_name(),
			$data,
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/**
	 * Atomically move a job from pending/running to a terminal status.
	 * Only the first caller wins — used to avoid duplicate emails/logs when
	 * AJAX, cron, and loopback finish the same job concurrently.
	 *
	 * @param int                  $id     Job ID.
	 * @param string               $status completed|failed|cancelled.
	 * @param array<string, mixed> $data   Extra columns to set with the claim.
	 * @return bool True if this caller claimed the transition.
	 */
	public static function claim_terminal( int $id, string $status, array $data = array() ): bool {
		global $wpdb;

		if ( $id < 1 || ! in_array( $status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return false;
		}

		$table              = self::table_name();
		$data['status']     = $status;
		$data['updated_at'] = current_time( 'mysql' );

		$set_parts = array();
		$values    = array();
		foreach ( $data as $column => $value ) {
			$column = preg_replace( '/[^a-z0-9_]/i', '', (string) $column );
			if ( ! is_string( $column ) || '' === $column ) {
				continue;
			}
			if ( null === $value ) {
				$set_parts[] = "`{$column}` = NULL";
				continue;
			}
			if ( is_int( $value ) ) {
				$set_parts[] = "`{$column}` = %d";
				$values[]    = $value;
				continue;
			}
			$set_parts[] = "`{$column}` = %s";
			$values[]    = is_scalar( $value ) ? (string) $value : '';
		}

		if ( empty( $set_parts ) ) {
			return false;
		}

		$values[] = $id;
		$sql      = "UPDATE {$table} SET " . implode( ', ', $set_parts ) . " WHERE id = %d AND status IN ('pending','running')";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$values ) );

		return 1 === (int) $result;
	}

	/**
	 * Acquire an exclusive lock for processing one job (prevents concurrent ZIP writes).
	 *
	 * @param int $id Job ID.
	 * @return bool
	 */
	public static function acquire_process_lock( int $id ): bool {
		if ( $id < 1 ) {
			return false;
		}

		global $wpdb;
		$name = 'maca_bp_job_' . $id;

		// MySQL/MariaDB named lock — atomic and released on connection end.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' === (string) $got ) {
			return true;
		}
		if ( '0' === (string) $got ) {
			return false;
		}

		// Fallback when GET_LOCK is unavailable (e.g. non-MySQL): atomic add_option.
		$option = '_maca_bp_job_lock_' . $id;
		$expires = (int) get_option( $option, 0 );
		if ( $expires > time() ) {
			return false;
		}
		if ( $expires > 0 ) {
			delete_option( $option );
		}
		return (bool) add_option( $option, (string) ( time() + 120 ), '', 'no' );
	}

	/**
	 * Release a job process lock.
	 *
	 * @param int $id Job ID.
	 * @return void
	 */
	public static function release_process_lock( int $id ): void {
		if ( $id < 1 ) {
			return;
		}

		global $wpdb;
		$name = 'maca_bp_job_' . $id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );

		delete_option( '_maca_bp_job_lock_' . $id );
	}

	/**
	 * Get job.
	 *
	 * @param int $id Job ID.
	 * @return object|null
	 */
	public static function get( int $id ): ?object {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
		return $row ?: null;
	}

	/**
	 * Seconds without an update before a pending/running job is considered stuck.
	 *
	 * @return int
	 */
	public static function stale_after_seconds(): int {
		/**
		 * Filter how long a job may sit without updates before it is treated as stale.
		 *
		 * @param int $seconds Default: 2 hours.
		 */
		return max( 300, (int) apply_filters( 'maca_backup_pro_job_stale_seconds', 2 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Whether a job looks abandoned (pending/running with no recent updated_at).
	 *
	 * @param object $job Job row.
	 * @return bool
	 */
	public static function is_stale( object $job ): bool {
		if ( ! in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			return false;
		}
		$updated = strtotime( (string) ( $job->updated_at ?? '' ) );
		if ( ! $updated ) {
			return false;
		}
		return ( (int) current_time( 'timestamp' ) - $updated ) > self::stale_after_seconds();
	}

	/**
	 * Soft-fail jobs that have been pending/running without updates for too long.
	 * Keeps the dashboard from resuming a phantom progress UI.
	 *
	 * @return int Number of jobs marked failed.
	 */
	public static function reap_stale(): int {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$table} WHERE status IN ('pending','running')"
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$failed = 0;
		$msg    = __( 'Job timed out (no progress). It was marked failed so the dashboard stays accurate.', 'maca-backup-pro' );
		foreach ( $rows as $row ) {
			if ( ! self::is_stale( $row ) ) {
				continue;
			}
			self::update(
				(int) $row->id,
				array(
					'status'        => 'failed',
					'error_message' => $msg,
				)
			);
			// Only fail the backup row for backup jobs — restore jobs reference a completed archive.
			if ( 'backup' === (string) $row->job_type && ! empty( $row->backup_id ) ) {
				Maca_Backup_Pro_Backups_Table::update(
					(int) $row->backup_id,
					array(
						'status'        => 'failed',
						'error_message' => $msg,
						'finished_at'   => current_time( 'mysql' ),
					)
				);
			}
			++$failed;
		}

		return $failed;
	}

	/**
	 * Active job of a type, if any.
	 *
	 * @param string $job_type backup|restore.
	 * @return object|null
	 */
	public static function active( string $job_type = 'backup' ): ?object {
		$all = self::active_all( $job_type );
		return $all[0] ?? null;
	}

	/**
	 * Fresh active job for admin UI (reaps stuck rows first).
	 *
	 * @param string $job_type backup|restore.
	 * @return object|null
	 */
	public static function active_for_ui( string $job_type = 'backup' ): ?object {
		self::reap_stale();
		return self::active( $job_type );
	}

	/**
	 * All active jobs of a type (pending/running), oldest first for fair processing.
	 *
	 * @param string $job_type backup|restore.
	 * @return array<object>
	 */
	public static function active_all( string $job_type = 'backup' ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE job_type = %s AND status IN ('pending','running') ORDER BY id ASC",
				$job_type
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recent jobs by type/status.
	 *
	 * @param string   $job_type backup|restore.
	 * @param string[] $statuses Statuses to include.
	 * @param int      $limit    Limit.
	 * @return array<object>
	 */
	public static function recent( string $job_type = 'backup', array $statuses = array( 'completed' ), int $limit = 50 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, $limit );

		$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
		if ( empty( $statuses ) ) {
			$statuses = array( 'completed' );
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( $job_type ), $statuses, array( $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$sql = (string) $wpdb->prepare(
			"SELECT * FROM {$table} WHERE job_type = %s AND status IN ($placeholders) ORDER BY id DESC LIMIT %d",
			...$params
		);

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Mark jobs for a deleted backup so reconcile will not recreate it.
	 *
	 * @param int    $backup_id Backup ID.
	 * @param string $backup_key Backup folder key.
	 * @return void
	 */
	public static function purge_backup( int $backup_id, string $backup_key = '' ): void {
		global $wpdb;
		$table = self::table_name();

		$rows = array();
		if ( $backup_id > 0 ) {
			$found = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE job_type = %s AND backup_id = %d",
					'backup',
					$backup_id
				)
			);
			if ( is_array( $found ) ) {
				$rows = $found;
			}
		}

		// Also match by work_dir key in state when backup_id was remapped.
		if ( '' !== $backup_key ) {
			$extra = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE job_type = %s AND state LIKE %s",
					'backup',
					'%' . $wpdb->esc_like( $backup_key ) . '%'
				)
			);
			if ( is_array( $extra ) ) {
				$ids = array_map(
					static fn( $r ) => (int) $r->id,
					$rows
				);
				foreach ( $extra as $row ) {
					if ( ! in_array( (int) $row->id, $ids, true ) ) {
						$rows[] = $row;
					}
				}
			}
		}

		foreach ( $rows as $row ) {
			$state = json_decode( (string) $row->state, true );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$state['purged']       = true;
			$state['parts']        = array();
			$state['remote_paths'] = array();
			self::update(
				(int) $row->id,
				array(
					'status'  => 'cancelled',
					'state'   => wp_json_encode( $state ),
					'backup_id' => 0,
				)
			);
		}
	}
}
