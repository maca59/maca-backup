<?php
/**
 * Google Drive storage (OAuth refresh token).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads via Google Drive REST API v3.
 */
class Maca_Backup_Pro_Google_Drive_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'google_drive';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'Google Drive', 'maca-backup-pro' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		$s = $this->settings();
		return ! empty( $s['client_id'] ) && '' !== $this->secret( 'client_secret' ) && '' !== $this->secret( 'refresh_token' );
	}

	/**
	 * Access token via refresh.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$s = $this->settings();
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => (string) $s['client_id'],
					'client_secret' => $this->secret( 'client_secret' ),
					'refresh_token' => $this->secret( 'refresh_token' ),
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'token', __( 'Google Drive token refresh failed.', 'maca-backup-pro' ) );
		}

		return (string) $data['access_token'];
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$s         = $this->settings();
		$folder_id = (string) ( $s['folder_id'] ?? '' );
		$meta      = array( 'name' => basename( $remote_path ) );
		if ( '' !== $folder_id ) {
			$meta['parents'] = array( $folder_id );
		}

		$boundary = 'maca_' . wp_generate_password( 12, false );
		$body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
			. wp_json_encode( $meta ) . "\r\n"
			. "--{$boundary}\r\nContent-Type: application/zip\r\n\r\n"
			. (string) file_get_contents( $local_path ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			. "\r\n--{$boundary}--";

		$response = wp_remote_post(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
			array(
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'upload', __( 'Google Drive upload failed.', 'maca-backup-pro' ) );
		}

		return (string) $data['id'];
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $remote_path ) . '?alt=media',
			array(
				'timeout' => 120,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'download', __( 'Google Drive download failed.', 'maca-backup-pro' ) );
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
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		wp_remote_request(
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $remote_path ),
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		return true;
	}
}
