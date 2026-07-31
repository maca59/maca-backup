<?php
/**
 * Uninstall maca BackUp.
 *
 * @package Maca_Backup_Pro
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Uninstall bootstrap locals.
$plugin_dir = plugin_dir_path( __FILE__ );

if ( ! defined( 'MACA_BACKUP_PRO_PATH' ) ) {
	define( 'MACA_BACKUP_PRO_PATH', $plugin_dir );
}
if ( ! defined( 'MACA_BACKUP_PRO_FILE' ) ) {
	define( 'MACA_BACKUP_PRO_FILE', $plugin_dir . 'maca-backup-pro.php' );
}
if ( ! defined( 'MACA_BACKUP_PRO_DB_VERSION' ) ) {
	define( 'MACA_BACKUP_PRO_DB_VERSION', '1.0.0' );
}

require_once $plugin_dir . 'includes/maca-api.php';
maca_backup_pro_api_on_uninstall();

if ( defined( 'MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK' ) ) {
	wp_clear_scheduled_hook( MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK );
}

require_once $plugin_dir . 'includes/class-autoloader.php';
Maca_Backup_Pro_Autoloader::register();

Maca_Backup_Pro_Installer::drop_tables();

delete_option( 'maca_backup_pro_settings' );
delete_option( 'maca_backup_pro_db_version' );
delete_option( 'maca_backup_pro_plugin_version' );
delete_option( 'maca_backup_pro_job_state' );
delete_option( 'maca_backup_pro_pending_telemetry' );
delete_option( 'maca_backup_pro_api_last_error' );
delete_option( 'maca_backup_pro_schedule_runs' );
delete_option( 'maca_backup_pro_schedules_migrated' );
delete_option( 'maca_backup_pro_legal_acceptance' );
delete_option( 'maca_backup_pro_onboarding_dismissed' );
delete_option( 'maca_backup_pro_onboarding_done' );
delete_transient( 'maca_backup_pro_api_deactivated_reported' );

wp_clear_scheduled_hook( 'maca_backup_pro_run_scheduled' );
wp_clear_scheduled_hook( 'maca_backup_pro_process_job' );
