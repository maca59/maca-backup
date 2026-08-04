<?php
/**
 * SFTP storage provider.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads via SSH2 SFTP when the extension is available.
 */
class Maca_Backup_Pro_Sftp_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'sftp';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'SFTP', 'maca-backup' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		$s = $this->settings();
		return ! empty( $s['host'] ) && ! empty( $s['user'] ) && ( '' !== $this->secret( 'pass' ) || '' !== $this->secret( 'key' ) );
	}

	/**
	 * Open SFTP resource.
	 *
	 * @return resource|\WP_Error
	 */
	private function sftp() {
		if ( ! function_exists( 'ssh2_connect' ) ) {
			return new WP_Error( 'ext', __( 'PHP ssh2 extension is required for SFTP.', 'maca-backup' ) );
		}

		$s    = $this->settings();
		$conn = ssh2_connect( (string) $s['host'], (int) ( $s['port'] ?? 22 ) );
		if ( ! $conn ) {
			return new WP_Error( 'connect', __( 'SFTP connection failed.', 'maca-backup' ) );
		}

		$key = $this->secret( 'key' );
		if ( '' !== $key ) {
			$tmp = wp_tempnam( 'maca-sftp-key' );
			file_put_contents( $tmp, $key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$ok = @ssh2_auth_pubkey_file( $conn, (string) $s['user'], $tmp . '.pub', $tmp, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );
			if ( ! $ok ) {
				// Fall through to password.
				$ok = ssh2_auth_password( $conn, (string) $s['user'], $this->secret( 'pass' ) );
			}
		} else {
			$ok = ssh2_auth_password( $conn, (string) $s['user'], $this->secret( 'pass' ) );
		}

		if ( ! $ok ) {
			return new WP_Error( 'auth', __( 'SFTP authentication failed.', 'maca-backup' ) );
		}

		$sftp = ssh2_sftp( $conn );
		if ( ! $sftp ) {
			return new WP_Error( 'sftp', __( 'Could not initialize SFTP subsystem.', 'maca-backup' ) );
		}

		return $sftp;
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$sftp = $this->sftp();
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}

		$s      = $this->settings();
		$base   = rtrim( (string) ( $s['path'] ?? '/' ), '/' );
		$remote = $base . '/' . ltrim( $remote_path, '/' );
		$stream = fopen( 'ssh2.sftp://' . (int) $sftp . $remote, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $stream ) {
			return new WP_Error( 'open', __( 'Could not open remote SFTP path.', 'maca-backup' ) );
		}

		$in = fopen( $local_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'local', __( 'Could not read local file for SFTP upload.', 'maca-backup' ) );
		}

		stream_copy_to_stream( $in, $stream );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $remote;
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		$sftp = $this->sftp();
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}

		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$in = fopen( 'ssh2.sftp://' . (int) $sftp . $remote_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			return new WP_Error( 'open', __( 'Could not open remote file.', 'maca-backup' ) );
		}
		$out = fopen( $local_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'local', __( 'Could not write local file.', 'maca-backup' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/** @inheritdoc */
	public function delete( string $remote_path ) {
		$sftp = $this->sftp();
		if ( is_wp_error( $sftp ) ) {
			return $sftp;
		}
		@unlink( 'ssh2.sftp://' . (int) $sftp . $remote_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		return true;
	}
}
