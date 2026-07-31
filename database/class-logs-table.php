<?php
/**
 * Logs table.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be prepared placeholders; queries use validated identifiers.

/**
 * Plugin activity logs.
 */
class Maca_Backup_Pro_Logs_Table {

	/**
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'maca_backup_logs';
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
			level varchar(16) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			backup_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY backup_id (backup_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Insert log.
	 *
	 * @param array<string, mixed> $data Row.
	 * @return int
	 */
	public static function insert( array $data ): int {
		global $wpdb;
		$wpdb->insert( self::table_name(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Recent logs.
	 *
	 * @param int         $limit  Limit.
	 * @param string|null $level  Optional level filter.
	 * @return array<object>
	 */
	public static function recent( int $limit = 100, ?string $level = null ): array {
		global $wpdb;
		$table = self::table_name();

		if ( $level ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE level = %s ORDER BY created_at DESC LIMIT %d",
					$level,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
					$limit
				)
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Logs for a backup.
	 *
	 * @param int $backup_id Backup ID.
	 * @return array<object>
	 */
	public static function for_backup( int $backup_id ): array {
		global $wpdb;
		$table = self::table_name();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE backup_id = %d ORDER BY created_at ASC",
				$backup_id
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}
