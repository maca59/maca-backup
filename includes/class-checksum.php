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
}
