<?php
/**
 * Local filesystem storage.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores backups on the server.
 */
class Maca_Backup_Pro_Local_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'local';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'Local storage', 'maca-backup' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		$dir = Maca_Backup_Pro_Settings::local_backup_dir();
		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$base = Maca_Backup_Pro_Settings::local_backup_dir();
		if ( '' === $base || ! Maca_Backup_Pro_Paths::is_under_uploads( $base ) ) {
			return new WP_Error( 'local', __( 'Local backup directory is not available under uploads.', 'maca-backup' ) );
		}

		$remote_path = ltrim( str_replace( '\\', '/', $remote_path ), '/' );
		$safe_rel    = Maca_Backup_Pro_Security::safe_zip_entry_path( $remote_path );
		if ( false === $safe_rel ) {
			return new WP_Error( 'path', __( 'Invalid local storage path.', 'maca-backup' ) );
		}

		$dest = Maca_Backup_Pro_Security::path_under_directory( $base, $safe_rel );
		if ( false === $dest ) {
			return new WP_Error( 'path', __( 'Invalid local storage path.', 'maca-backup' ) );
		}

		$dest_dir = dirname( $dest );
		if ( ! is_dir( $dest_dir ) ) {
			wp_mkdir_p( $dest_dir );
		}

		// Already in place (engine wrote there).
		if ( wp_normalize_path( $local_path ) === wp_normalize_path( $dest ) ) {
			return $dest;
		}

		if ( ! copy( $local_path, $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copy within uploads/maca-backups only.
			return new WP_Error( 'copy', __( 'Could not copy backup to local storage.', 'maca-backup' ) );
		}

		return $dest;
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		if ( ! is_readable( $remote_path ) ) {
			return new WP_Error( 'missing', __( 'Local backup file not found.', 'maca-backup' ) );
		}
		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! copy( $remote_path, $local_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			return new WP_Error( 'copy', __( 'Could not copy local backup.', 'maca-backup' ) );
		}
		return true;
	}

	/** @inheritdoc */
	public function delete( string $remote_path ) {
		if ( is_file( $remote_path ) ) {
			wp_delete_file( $remote_path );
		}
		return true;
	}

	/** @inheritdoc */
	public function space_info(): ?array {
		$dir = Maca_Backup_Pro_Settings::local_backup_dir();
		$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$total = @disk_total_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free || false === $total ) {
			return null;
		}
		return array(
			'free'  => (int) $free,
			'total' => (int) $total,
			'used'  => (int) ( $total - $free ),
		);
	}
}
