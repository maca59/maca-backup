<?php
/**
 * Dropbox storage provider.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads via Dropbox API v2.
 */
class Maca_Backup_Pro_Dropbox_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'dropbox';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'Dropbox', 'maca-backup' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		return '' !== $this->secret( 'access_token' );
	}

	/**
	 * Full remote path.
	 *
	 * @param string $remote_path Relative path.
	 * @return string
	 */
	private function full_path( string $remote_path ): string {
		$s    = $this->settings();
		$base = rtrim( (string) ( $s['path'] ?? '/maca-backups' ), '/' );
		return $base . '/' . ltrim( $remote_path, '/' );
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$token = $this->secret( 'access_token' );
		$path  = $this->full_path( $remote_path );
		$body  = (string) file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$response = wp_remote_post(
			'https://content.dropboxapi.com/2/files/upload',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $token,
					'Content-Type'    => 'application/octet-stream',
					'Dropbox-API-Arg' => wp_json_encode(
						array(
							'path'       => $path,
							'mode'       => 'overwrite',
							'autorename' => false,
							'mute'       => true,
						)
					),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'upload', __( 'Dropbox upload failed.', 'maca-backup' ) );
		}

		return $path;
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		$token = $this->secret( 'access_token' );
		$response = wp_remote_post(
			'https://content.dropboxapi.com/2/files/download',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization'   => 'Bearer ' . $token,
					'Dropbox-API-Arg' => wp_json_encode( array( 'path' => $remote_path ) ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'download', __( 'Dropbox download failed.', 'maca-backup' ) );
		}

		$dir = dirname( $local_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $local_path, wp_remote_retrieve_body( $response ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return true;
	}

	/** @inheritdoc */
	public function delete( string $remote_path ) {
		$token = $this->secret( 'access_token' );
		wp_remote_post(
			'https://api.dropboxapi.com/2/files/delete_v2',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'path' => $remote_path ) ),
			)
		);
		return true;
	}
}
