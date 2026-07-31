<?php
/**
 * Minimal S3-compatible client (Signature V4).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight AWS S3 / B2 / custom endpoint client.
 */
class Maca_Backup_Pro_S3_Client {

	/**
	 * @param array<string, mixed> $cfg Config.
	 */
	public function __construct( private array $cfg ) {}

	/**
	 * Put object from local file.
	 *
	 * @param string $local_path Local file.
	 * @param string $key        Object key.
	 * @return true|\WP_Error
	 */
	public function put_object( string $local_path, string $key ) {
		$body = file_get_contents( $local_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $body ) {
			return new WP_Error( 'read', __( 'Could not read file for S3 upload.', 'maca-backup-pro' ) );
		}

		$headers = array(
			'Content-Type'   => 'application/zip',
			'Content-Length' => (string) strlen( $body ),
			'x-amz-content-sha256' => hash( 'sha256', $body ),
		);

		return $this->request( 'PUT', $key, $headers, $body );
	}

	/**
	 * Download object to local path.
	 *
	 * @param string $key        Object key.
	 * @param string $local_path Destination.
	 * @return true|\WP_Error
	 */
	public function get_object( string $key, string $local_path ) {
		$result = $this->request( 'GET', $key, array( 'x-amz-content-sha256' => hash( 'sha256', '' ) ), '' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ok = file_put_contents( $local_path, (string) $result ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false === $ok
			? new WP_Error( 'write', __( 'Could not write S3 download.', 'maca-backup-pro' ) )
			: true;
	}

	/**
	 * Delete object.
	 *
	 * @param string $key Object key.
	 * @return true|\WP_Error
	 */
	public function delete_object( string $key ) {
		$result = $this->request( 'DELETE', $key, array( 'x-amz-content-sha256' => hash( 'sha256', '' ) ), '' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Signed HTTP request.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $key     Object key.
	 * @param array<string,string>  $headers Headers.
	 * @param string                $body    Body.
	 * @return string|true|\WP_Error Response body or true.
	 */
	private function request( string $method, string $key, array $headers, string $body ) {
		$bucket   = (string) ( $this->cfg['bucket'] ?? '' );
		$region   = (string) ( $this->cfg['region'] ?? 'us-east-1' );
		$endpoint = rtrim( (string) ( $this->cfg['endpoint'] ?? '' ), '/' );
		$access   = (string) ( $this->cfg['access_key'] ?? '' );
		$secret   = (string) ( $this->cfg['secret_key'] ?? '' );
		$path_style = ! empty( $this->cfg['path_style'] );

		if ( '' === $bucket || '' === $access || '' === $secret ) {
			return new WP_Error( 'cfg', __( 'S3 is not fully configured.', 'maca-backup-pro' ) );
		}

		$key = ltrim( str_replace( '\\', '/', $key ), '/' );
		$host = '';
		$path = '';

		if ( '' !== $endpoint ) {
			$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
			$scheme = (string) ( wp_parse_url( $endpoint, PHP_URL_SCHEME ) ?: 'https' );
			if ( $path_style ) {
				$path = '/' . rawurlencode( $bucket ) . '/' . $this->encode_key( $key );
				$url  = $endpoint . '/' . $bucket . '/' . $key;
			} else {
				$host = $bucket . '.' . $host;
				$path = '/' . $this->encode_key( $key );
				$url  = $scheme . '://' . $host . '/' . $key;
			}
		} else {
			$host = $bucket . '.s3.' . $region . '.amazonaws.com';
			$path = '/' . $this->encode_key( $key );
			$url  = 'https://' . $host . '/' . $key;
		}

		$amz_date = gmdate( 'Ymd\THis\Z' );
		$date     = gmdate( 'Ymd' );
		$payload_hash = $headers['x-amz-content-sha256'] ?? hash( 'sha256', $body );

		$headers['Host'] = $host;
		$headers['x-amz-date'] = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;

		$signed_headers_list = array_keys( $headers );
		sort( $signed_headers_list );
		$canonical_headers = '';
		foreach ( $signed_headers_list as $h ) {
			$canonical_headers .= strtolower( $h ) . ':' . trim( $headers[ $h ] ) . "\n";
		}
		$signed_headers = implode( ';', array_map( 'strtolower', $signed_headers_list ) );

		$canonical = implode(
			"\n",
			array(
				$method,
				$path,
				'',
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope     = $date . '/' . $region . '/s3/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical );
		$signing_key = $this->signing_key( $secret, $date, $region, 's3' );
		$signature   = hash_hmac( 'sha256', $string_to_sign, $signing_key );

		$headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $access . '/' . $scope
			. ', SignedHeaders=' . $signed_headers
			. ', Signature=' . $signature;

		$args = array(
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 120,
			'body'    => $body,
		);

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				's3',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'S3 request failed (HTTP %d).', 'maca-backup-pro' ),
					$code
				)
			);
		}

		if ( 'GET' === $method ) {
			return (string) wp_remote_retrieve_body( $response );
		}

		return true;
	}

	/**
	 * Encode object key path segments.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private function encode_key( string $key ): string {
		$parts = explode( '/', $key );
		$parts = array_map( 'rawurlencode', $parts );
		return implode( '/', $parts );
	}

	/**
	 * Derive SigV4 signing key.
	 *
	 * @param string $secret Secret.
	 * @param string $date   YYYYMMDD.
	 * @param string $region Region.
	 * @param string $service Service.
	 * @return string
	 */
	private function signing_key( string $secret, string $date, string $region, string $service ): string {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $secret, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}
}
