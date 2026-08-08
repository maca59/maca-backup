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
	 * Normalize a CRC32 hex string to 8 lowercase chars (or empty).
	 *
	 * @param string $crc Raw CRC.
	 * @return string
	 */
	public static function normalize_crc32( string $crc ): string {
		$raw = strtolower( preg_replace( '/[^a-f0-9]/i', '', $crc ) ?? '' );
		return ( 8 === strlen( $raw ) ) ? $raw : '';
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

	/**
	 * Map of basename => crc32 hex for each readable part.
	 *
	 * @param string[] $paths Absolute part paths.
	 * @return array<string, string>
	 */
	public static function crc32_map( array $paths ): array {
		$map = array();
		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$crc = self::crc32_file( $path );
			if ( '' === $crc ) {
				continue;
			}
			$map[ basename( $path ) ] = $crc;
		}
		return $map;
	}

	/**
	 * Copy CRC values from ZIP central directory into an inventory map.
	 *
	 * @param array<string, array{size?:int,mtime?:int,crc?:int}> $inventory Inventory.
	 * @param string[]                                            $parts     ZIP parts (plain or decrypted paths).
	 * @return array<string, array{size:int,mtime:int,crc:int}>
	 */
	public static function enrich_inventory_crc_from_parts( array $inventory, array $parts ): array {
		if ( empty( $parts ) || ! class_exists( 'ZipArchive' ) ) {
			return $inventory;
		}

		foreach ( $parts as $part ) {
			if ( ! is_readable( (string) $part ) ) {
				continue;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( (string) $part ) ) {
				continue;
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( in_array( $name, array( 'manifest.json', 'database.sql', 'files.json' ), true ) ) {
					continue;
				}
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) ) {
					continue;
				}
				if ( ! isset( $inventory[ $name ] ) ) {
					$inventory[ $name ] = array(
						'size'  => (int) ( $stat['size'] ?? 0 ),
						'mtime' => (int) ( $stat['mtime'] ?? 0 ),
						'crc'   => (int) ( $stat['crc'] ?? 0 ),
					);
					continue;
				}
				$inventory[ $name ]['crc'] = (int) ( $stat['crc'] ?? 0 );
				if ( empty( $inventory[ $name ]['size'] ) ) {
					$inventory[ $name ]['size'] = (int) ( $stat['size'] ?? 0 );
				}
			}
			$zip->close();
		}

		return $inventory;
	}
}
