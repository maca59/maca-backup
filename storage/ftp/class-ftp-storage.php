<?php
/**
 * FTP storage provider.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads backups via FTP.
 */
class Maca_Backup_Pro_Ftp_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'ftp';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'FTP', 'maca-backup' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		$s = $this->settings();
		return ! empty( $s['host'] ) && ! empty( $s['user'] ) && '' !== $this->secret( 'pass' );
	}

	/**
	 * Connect.
	 *
	 * @return resource|\WP_Error
	 */
	private function connect() {
		if ( ! function_exists( 'ftp_connect' ) ) {
			return new WP_Error( 'ext', __( 'PHP FTP extension is not available.', 'maca-backup' ) );
		}

		$s    = $this->settings();
		$host = (string) ( $s['host'] ?? '' );
		$port = (int) ( $s['port'] ?? 21 );
		$conn = ftp_connect( $host, $port, 20 );
		if ( ! $conn ) {
			return new WP_Error( 'connect', __( 'FTP connection failed.', 'maca-backup' ) );
		}

		if ( ! ftp_login( $conn, (string) ( $s['user'] ?? '' ), $this->secret( 'pass' ) ) ) {
			ftp_close( $conn );
			return new WP_Error( 'auth', __( 'FTP login failed.', 'maca-backup' ) );
		}

		if ( ! empty( $s['passive'] ) ) {
			ftp_pasv( $conn, true );
		}

		return $conn;
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		$s        = $this->settings();
		$base     = rtrim( (string) ( $s['path'] ?? '/' ), '/' );
		$remote   = $base . '/' . ltrim( $remote_path, '/' );
		$this->ensure_remote_dir( $conn, dirname( $remote ) );

		$ok = ftp_put( $conn, $remote, $local_path, FTP_BINARY );
		ftp_close( $conn );

		if ( ! $ok ) {
			return new WP_Error( 'upload', __( 'FTP upload failed.', 'maca-backup' ) );
		}

		return $remote;
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}

		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$ok = ftp_get( $conn, $local_path, $remote_path, FTP_BINARY );
		ftp_close( $conn );

		return $ok ? true : new WP_Error( 'download', __( 'FTP download failed.', 'maca-backup' ) );
	}

	/** @inheritdoc */
	public function delete( string $remote_path ) {
		$conn = $this->connect();
		if ( is_wp_error( $conn ) ) {
			return $conn;
		}
		ftp_delete( $conn, $remote_path );
		ftp_close( $conn );
		return true;
	}

	/**
	 * Recursively create remote directories.
	 *
	 * @param resource $conn FTP connection.
	 * @param string   $dir  Remote dir.
	 * @return void
	 */
	private function ensure_remote_dir( $conn, string $dir ): void {
		$parts = array_filter( explode( '/', str_replace( '\\', '/', $dir ) ) );
		$path  = '';
		foreach ( $parts as $part ) {
			$path .= '/' . $part;
			@ftp_mkdir( $conn, $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
