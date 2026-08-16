<?php
/**
 * Backups history table.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be prepared placeholders; queries use validated identifiers.

/**
 * CRUD for backup records.
 */
class Maca_Backup_Pro_Backups_Table {

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'maca_backups';
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
			backup_key varchar(64) NOT NULL DEFAULT '',
			type varchar(32) NOT NULL DEFAULT 'full',
			status varchar(32) NOT NULL DEFAULT 'pending',
			storage varchar(64) NOT NULL DEFAULT 'local',
			path text NULL,
			parts int(11) NOT NULL DEFAULT 1,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			db_size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			file_count int(11) NOT NULL DEFAULT 0,
			checksum varchar(64) NOT NULL DEFAULT '',
			manifest longtext NULL,
			inventory longtext NULL,
			parent_backup_id bigint(20) unsigned NOT NULL DEFAULT 0,
			duration int(11) NOT NULL DEFAULT 0,
			error_message text NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY backup_key (backup_key),
			KEY status (status),
			KEY created_at (created_at),
			KEY parent_backup_id (parent_backup_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Insert a backup row.
	 *
	 * @param array<string, mixed> $data Row data.
	 * @return int
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$ok = $wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $ok ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a backup row.
	 *
	 * @param int                  $id   Row ID.
	 * @param array<string, mixed> $data Fields.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		if ( $id < 1 ) {
			return false;
		}
		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table_name(),
			$data,
			array( 'id' => $id )
		);
		return false !== $result;
	}

	/**
	 * Get by ID.
	 *
	 * @param int $id Backup ID.
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
	 * Find by backup_key.
	 *
	 * @param string $key Backup key.
	 * @return object|null
	 */
	public static function get_by_key( string $key ): ?object {
		global $wpdb;
		if ( '' === $key ) {
			return null;
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE backup_key = %s LIMIT 1", $key )
		);
		return $row ?: null;
	}

	/**
	 * Recent backups.
	 *
	 * @param int $limit Limit.
	 * @return array<object>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, (int) $limit );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Recent completed backups (for restore pickers).
	 *
	 * @param int $limit Limit.
	 * @return array<object>
	 */
	public static function recent_completed( int $limit = 100 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY COALESCE(NULLIF(finished_at, '0000-00-00 00:00:00'), created_at) DESC, id DESC LIMIT %d",
				'completed',
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count completed backups.
	 *
	 * @return int
	 */
	public static function count_completed(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count all backup rows (any status).
	 *
	 * @return int
	 */
	public static function count_all(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Latest completed backup.
	 *
	 * @return object|null
	 */
	public static function latest_completed(): ?object {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY COALESCE(NULLIF(finished_at, '0000-00-00 00:00:00'), created_at) DESC LIMIT 1",
				'completed'
			)
		);
		return $row ?: null;
	}

	/**
	 * Latest failed backup.
	 *
	 * @return object|null
	 */
	public static function latest_failed(): ?object {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY COALESCE(NULLIF(finished_at, '0000-00-00 00:00:00'), created_at) DESC LIMIT 1",
				'failed'
			)
		);
		return $row ?: null;
	}

	/**
	 * Latest failed backup that has not been followed by a newer completed backup.
	 *
	 * @return object|null
	 */
	public static function unresolved_failed(): ?object {
		$failed = self::latest_failed();
		if ( ! $failed ) {
			return null;
		}

		$completed = self::latest_completed();
		if ( ! $completed ) {
			return $failed;
		}

		$fail_ts = strtotime( (string) ( ! empty( $failed->finished_at ) ? $failed->finished_at : $failed->created_at ) );
		$ok_ts   = strtotime( (string) ( ! empty( $completed->finished_at ) ? $completed->finished_at : $completed->created_at ) );
		if ( ! $fail_ts ) {
			return $failed;
		}
		if ( $ok_ts && $ok_ts >= $fail_ts ) {
			return null;
		}

		return $failed;
	}

	/**
	 * Latest completed full backup (base for incremental/differential).
	 *
	 * @return object|null
	 */
	public static function latest_full_completed(): ?object {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND type = %s ORDER BY id DESC LIMIT 1",
				'completed',
				'full'
			)
		);
		return $row ?: null;
	}

	/**
	 * Resolve restore chain from incremental/differential back to full.
	 *
	 * @param int $backup_id Leaf backup ID.
	 * @return object[] Oldest (full) first.
	 */
	public static function restore_chain( int $backup_id ): array {
		$chain = array();
		$seen  = array();
		$id    = $backup_id;
		while ( $id > 0 && ! isset( $seen[ $id ] ) ) {
			$seen[ $id ] = true;
			$row         = self::get( $id );
			if ( ! $row || 'completed' !== $row->status ) {
				break;
			}
			array_unshift( $chain, $row );
			$parent = (int) ( $row->parent_backup_id ?? 0 );
			if ( 'full' === $row->type || $parent < 1 ) {
				break;
			}
			$id = $parent;
		}
		return $chain;
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Backup ID.
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		$result = $wpdb->delete( self::table_name(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $result;
	}

	/**
	 * Sum of completed backup sizes.
	 *
	 * @return int
	 */
	public static function total_size(): int {
		global $wpdb;
		$table = self::table_name();
		return (int) $wpdb->get_var( "SELECT COALESCE(SUM(size_bytes),0) FROM {$table} WHERE status = 'completed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
