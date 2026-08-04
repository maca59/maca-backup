<?php
/**
 * Amazon S3 / Backblaze B2 / S3-compatible storage.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * S3-compatible storage provider.
 */
class Maca_Backup_Pro_S3_Storage extends Maca_Backup_Pro_Abstract_Storage {

	/**
	 * @return string
	 */
	public function id(): string {
		return 's3';
	}

	/**
	 * @return string
	 */
	public function label(): string {
		return __( 'Amazon S3 / B2 / S3-compatible', 'maca-backup' );
	}

	/**
	 * @return bool
	 */
	public function is_configured(): bool {
		$s = $this->settings();
		return '' !== (string) ( $s['bucket'] ?? '' )
			&& '' !== (string) ( $s['access_key'] ?? '' )
			&& '' !== $this->secret( 'secret_key' );
	}

	/**
	 * @param string $local_path Local file.
	 * @param string $remote_path Remote relative path.
	 * @return string|\WP_Error
	 */
	public function upload( string $local_path, string $remote_path ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		$prefix = trim( (string) ( $this->settings()['prefix'] ?? 'maca-backups' ), '/' );
		$key    = ( '' !== $prefix ? $prefix . '/' : '' ) . ltrim( str_replace( '\\', '/', $remote_path ), '/' );
		$result = $client->put_object( $local_path, $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $key;
	}

	/**
	 * @param string $remote_path Remote key.
	 * @param string $local_path Destination.
	 * @return true|\WP_Error
	 */
	public function download( string $remote_path, string $local_path ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->get_object( $remote_path, $local_path );
	}

	/**
	 * @param string $remote_path Remote key.
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_path ) {
		$client = $this->client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		return $client->delete_object( $remote_path );
	}

	/**
	 * Build client from settings.
	 *
	 * @return Maca_Backup_Pro_S3_Client|\WP_Error
	 */
	private function client() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'cfg', __( 'S3 storage is not configured.', 'maca-backup' ) );
		}
		$s = $this->settings();
		return new Maca_Backup_Pro_S3_Client(
			array(
				'bucket'     => (string) ( $s['bucket'] ?? '' ),
				'region'     => (string) ( $s['region'] ?? 'us-east-1' ),
				'endpoint'   => (string) ( $s['endpoint'] ?? '' ),
				'access_key' => (string) ( $s['access_key'] ?? '' ),
				'secret_key' => $this->secret( 'secret_key' ),
				'path_style' => ! empty( $s['path_style'] ),
			)
		);
	}
}
