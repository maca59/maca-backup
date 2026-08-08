<?php
/**
 * Autoloader for maca BackUp classes.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps Maca_Backup_Pro_* class names to files under the plugin root.
 */
class Maca_Backup_Pro_Autoloader {

	/**
	 * Class → relative path map.
	 *
	 * @var array<string, string>
	 */
	private static array $map = array(
		'Maca_Backup_Pro_Plugin'               => 'includes/class-plugin.php',
		'Maca_Backup_Pro_Security'             => 'includes/class-security.php',
		'Maca_Backup_Pro_Legal'                => 'includes/class-legal.php',
		'Maca_Backup_Pro_Support'              => 'includes/class-support.php',
		'Maca_Backup_Pro_Encryption'           => 'includes/class-encryption.php',
		'Maca_Backup_Pro_Checksum'             => 'includes/class-checksum.php',
		'Maca_Backup_Pro_Settings'             => 'includes/class-settings.php',
		'Maca_Backup_Pro_Paths'                => 'includes/class-paths.php',
		'Maca_Backup_Pro_Download'             => 'includes/class-download.php',
		'Maca_Backup_Pro_Migrator'             => 'includes/class-migrator.php',
		'Maca_Backup_Pro_Mailer'               => 'includes/class-mailer.php',
		'Maca_Backup_Pro_Format'               => 'includes/class-format.php',
		'Maca_Backup_Pro_Logger'               => 'includes/class-logger.php',
		'Maca_Backup_Pro_Admin'                => 'admin/class-admin.php',
		'Maca_Backup_Pro_Assets'               => 'admin/class-assets.php',
		'Maca_Backup_Pro_Installer'            => 'database/class-installer.php',
		'Maca_Backup_Pro_Backups_Table'        => 'database/class-backups-table.php',
		'Maca_Backup_Pro_Logs_Table'           => 'database/class-logs-table.php',
		'Maca_Backup_Pro_Jobs_Table'           => 'database/class-jobs-table.php',
		'Maca_Backup_Pro_Backup_Engine'        => 'engine/class-backup-engine.php',
		'Maca_Backup_Pro_File_Scanner'         => 'engine/class-file-scanner.php',
		'Maca_Backup_Pro_Zip_Builder'          => 'engine/class-zip-builder.php',
		'Maca_Backup_Pro_Database_Exporter'    => 'engine/class-database-exporter.php',
		'Maca_Backup_Pro_Chunk_Processor'      => 'engine/class-chunk-processor.php',
		'Maca_Backup_Pro_Restore_Engine'       => 'engine/class-restore-engine.php',
		'Maca_Backup_Pro_Smart_Restore'        => 'engine/class-smart-restore.php',
		'Maca_Backup_Pro_Verifier'             => 'engine/class-verifier.php',
		'Maca_Backup_Pro_Importer'             => 'engine/class-importer.php',
		'Maca_Backup_Pro_Pre_Update'           => 'engine/class-pre-update.php',
		'Maca_Backup_Pro_Staging'              => 'engine/class-staging.php',
		'Maca_Backup_Pro_Storage_Provider'     => 'storage/interface-storage-provider.php',
		'Maca_Backup_Pro_Abstract_Storage'     => 'storage/class-abstract-storage.php',
		'Maca_Backup_Pro_Storage_Registry'     => 'storage/class-storage-registry.php',
		'Maca_Backup_Pro_Local_Storage'        => 'storage/local/class-local-storage.php',
		'Maca_Backup_Pro_Ftp_Storage'          => 'storage/ftp/class-ftp-storage.php',
		'Maca_Backup_Pro_Sftp_Storage'         => 'storage/sftp/class-sftp-storage.php',
		'Maca_Backup_Pro_Google_Drive_Storage' => 'storage/google-drive/class-google-drive-storage.php',
		'Maca_Backup_Pro_Dropbox_Storage'      => 'storage/dropbox/class-dropbox-storage.php',
		'Maca_Backup_Pro_OneDrive_Storage'     => 'storage/onedrive/class-onedrive-storage.php',
		'Maca_Backup_Pro_S3_Client'            => 'storage/s3/class-s3-client.php',
		'Maca_Backup_Pro_S3_Storage'           => 'storage/s3/class-s3-storage.php',
		'Maca_Backup_Pro_Scheduler'            => 'cron/class-scheduler.php',
		'Maca_Backup_Pro_REST_Controller'      => 'api/class-rest-controller.php',
		'Maca_Backup_Pro_Ajax'                 => 'api/class-ajax.php',
		'Maca_Backup_Pro_CLI'                  => 'includes/class-cli.php',
	);

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file if known.
	 *
	 * @param string $class Class name.
	 * @return void
	 */
	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, 'Maca_Backup_Pro_' ) ) {
			return;
		}

		if ( isset( self::$map[ $class ] ) ) {
			$file = MACA_BACKUP_PRO_PATH . self::$map[ $class ];
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
}
