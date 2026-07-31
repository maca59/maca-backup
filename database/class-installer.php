<?php
/**
 * Database installer / upgrader.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be prepared placeholders; queries use validated identifiers.

/**
 * Creates custom tables via dbDelta.
 */
class Maca_Backup_Pro_Installer {

	public const DB_VERSION_OPTION = 'maca_backup_pro_db_version';

	/**
	 * Install or upgrade schema.
	 *
	 * @return void
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		Maca_Backup_Pro_Backups_Table::create();
		Maca_Backup_Pro_Logs_Table::create();
		Maca_Backup_Pro_Jobs_Table::create();

		update_option( self::DB_VERSION_OPTION, MACA_BACKUP_PRO_DB_VERSION, false );
	}

	/**
	 * Upgrade when DB version differs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$current = get_option( self::DB_VERSION_OPTION, '' );
		if ( MACA_BACKUP_PRO_DB_VERSION !== $current ) {
			self::install();
		}
	}

	/**
	 * Drop all plugin tables.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		$tables = array(
			Maca_Backup_Pro_Backups_Table::table_name(),
			Maca_Backup_Pro_Logs_Table::table_name(),
			Maca_Backup_Pro_Jobs_Table::table_name(),
		);

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted table names.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
