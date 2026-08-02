<?php
/**
 * SHA256 checksum helpers.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * File and payload integrity via SHA256.
 */
class Maca_Backup_Pro_Checksum {

	/**
	 * Hash a string.
	 *
	 * @param string $data Raw data.
	 * @return string
	 */
	public static function make( string $data ): string {
		return hash( 'sha256', $data );
	}

	/**
	 * Hash a file in streaming fashion (low memory).
	 *
	 * @param string $path Absolute file path.
	 * @return string|false
	 */
	public static function file( string $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$hash = hash_file( 'sha256', $path );
		return false === $hash ? false : $hash;
	}

	/**
	 * Constant-time compare.
	 *
	 * @param string $expected Expected checksum.
	 * @param string $actual   Actual checksum.
	 * @return bool
	 */
	public static function equals( string $expected, string $actual ): bool {
		return hash_equals( $expected, $actual );
	}

	/**
	 * Verify a file against an expected checksum.
	 *
	 * @param string $path     File path.
	 * @param string $expected Expected SHA256.
	 * @return bool
	 */
	public static function verify_file( string $path, string $expected ): bool {
		$actual = self::file( $path );
		if ( false === $actual || '' === $expected ) {
			return false;
		}

		return self::equals( $expected, $actual );
	}

	/**
	 * CRC32 (crc32b) of a file as 8-char lowercase hex.
	 *
	 * @param string $path Absolute path.
	 * @return string Empty string on failure.
	 */
	public static function crc32_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$hash = hash_file( 'crc32b', $path );
		return is_string( $hash ) ? strtolower( $hash ) : '';
	}

	/**
	 * Combined CRC32 for one or more archive parts (stable across part order).
	 *
	 * @param string[] $paths Absolute part paths.
	 * @return string 8-char hex, or empty on failure.
	 */
	public static function crc32_parts( array $paths ): string {
		$parts = array();
		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$crc = self::crc32_file( $path );
			if ( '' === $crc ) {
				return '';
			}
			$parts[ basename( $path ) ] = $crc;
		}
		if ( empty( $parts ) ) {
			return '';
		}
		if ( 1 === count( $parts ) ) {
			return (string) reset( $parts );
		}
		ksort( $parts );
		return strtolower( hash( 'crc32b', (string) wp_json_encode( $parts ) ) );
	}
}
