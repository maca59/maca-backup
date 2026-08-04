<?php
/**
 * OneDrive storage provider.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uploads via Microsoft Graph.
 */
class Maca_Backup_Pro_OneDrive_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/** @inheritdoc */
	public function id(): string {
		return 'onedrive';
	}

	/** @inheritdoc */
	public function label(): string {
		return __( 'OneDrive', 'maca-backup' );
	}

	/** @inheritdoc */
	public function is_configured(): bool {
		$s = $this->settings();
		return ! empty( $s['client_id'] ) && '' !== $this->secret( 'client_secret' ) && '' !== $this->secret( 'refresh_token' );
	}

	/**
	 * Access token.
	 *
	 * @return string|\WP_Error
	 */
	private function access_token() {
		$s = $this->settings();
		$response = wp_remote_post(
			'https://login.microsoftonline.com/common/oauth2/v2.0/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => (string) $s['client_id'],
					'client_secret' => $this->secret( 'client_secret' ),
					'refresh_token' => $this->secret( 'refresh_token' ),
					'grant_type'    => 'refresh_token',
					'scope'         => 'https://graph.microsoft.com/.default offline_access',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'token', __( 'OneDrive token refresh failed.', 'maca-backup' ) );
		}

		return (string) $data['access_token'];
	}

	/**
	 * Graph path for upload.
	 *
	 * @param string $remote_path Relative path.
	 * @return string
	 */
	private function graph_path( string $remote_path ): string {
		$s    = $this->settings();
		$base = trim( (string) ( $s['folder_path'] ?? '/maca-backups' ), '/' );
		$path = trim( $base . '/' . ltrim( $remote_path, '/' ), '/' );
		return 'https://graph.microsoft.com/v1.0/me/drive/root:/' . rawurlencode( $path ) . ':/content';
	}

	/** @inheritdoc */
	public function upload( string $local_path, string $remote_path ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$body = (string) file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$url  = $this->graph_path( $remote_path );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'PUT',
				'timeout' => 120,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/octet-stream',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'upload', __( 'OneDrive upload failed.', 'maca-backup' ) );
		}

		return (string) $data['id'];
	}

	/** @inheritdoc */
	public function download( string $remote_path, string $local_path ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		// remote_path may be item id.
		$url = 'https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode( $remote_path ) . '/content';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 120,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
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
			'https://graph.microsoft.com/v1.0/me/drive/items/' . rawurlencode( $remote_path ),
			array(
				'method'  => 'DELETE',
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);

		return true;
	}
}
