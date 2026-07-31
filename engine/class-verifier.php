<?php
/**
 * Backup integrity verifier.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies ZIP parts and manifests before restore.
 */
class Maca_Backup_Pro_Verifier {

	/**
	 * Verify part checksums.
	 *
	 * @param string[]             $parts     Absolute part paths.
	 * @param array<string,string> $checksums basename => sha256.
	 * @return bool
	 */
	public static function verify_parts( array $parts, array $checksums ): bool {
		if ( empty( $parts ) ) {
			return false;
		}

		foreach ( $parts as $part ) {
			if ( ! is_readable( $part ) ) {
				return false;
			}
			$base = basename( $part );
			if ( empty( $checksums[ $base ] ) ) {
				continue;
			}
			if ( ! Maca_Backup_Pro_Checksum::verify_file( $part, $checksums[ $base ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Verify a backup record is restoreable.
	 *
	 * @param object $backup Backup row.
	 * @return true|\WP_Error
	 */
	public static function verify_backup( object $backup ) {
		if ( 'completed' !== $backup->status ) {
			return new WP_Error( 'not_ready', __( 'Backup is not completed.', 'maca-backup-pro' ) );
		}

		$path = (string) $backup->path;
		if ( '' === $path ) {
			return new WP_Error( 'missing', __( 'Backup path missing.', 'maca-backup-pro' ) );
		}

		$parts = self::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', __( 'ZipArchive extension required.', 'maca-backup-pro' ) );
		}

		$has_manifest = false;
		foreach ( $parts as $part ) {
			$ready = self::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				return new WP_Error( 'open', __( 'Could not open backup archive.', 'maca-backup-pro' ) );
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name ) {
					continue;
				}
				if ( 'manifest.json' === strtolower( basename( str_replace( '\\', '/', $name ) ) ) ) {
					$has_manifest = true;
					break;
				}
			}
			$zip->close();
			if ( $has_manifest ) {
				break;
			}
		}

		if ( ! $has_manifest ) {
			return new WP_Error( 'manifest', __( 'Backup manifest missing — verification failed.', 'maca-backup-pro' ) );
		}

		return true;
	}

	/**
	 * Ensure backup ZIP is available locally (download if remote).
	 * Returns the first part path (backward compatible).
	 *
	 * @param object $backup Backup row.
	 * @return string|\WP_Error Local path.
	 */
	public static function ensure_local( object $backup ) {
		$parts = self::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}
		return $parts[0];
	}

	/**
	 * Ensure all ZIP parts are available locally.
	 *
	 * @param object $backup Backup row.
	 * @return string[]|\WP_Error Absolute part paths.
	 */
	public static function ensure_local_parts( object $backup ) {
		$dir = trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . $backup->backup_key;

		$local = self::discover_local_parts( $dir, (string) $backup->path );
		if ( ! empty( $local ) ) {
			return $local;
		}

		$provider = Maca_Backup_Pro_Storage_Registry::instance()->get( (string) $backup->storage );
		if ( ! $provider ) {
			return new WP_Error( 'storage', __( 'Storage provider unavailable.', 'maca-backup-pro' ) );
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$path        = (string) $backup->path;
		$parts_count = max( 1, (int) ( $backup->parts ?? 1 ) );
		$downloaded  = array();

		// Single remote file.
		if ( 1 === $parts_count || ! preg_match( '/backup\.part\d+\.zip$/i', $path ) ) {
			$dest = $dir . '/restore-download.zip';
			if ( ! is_readable( $dest ) ) {
				$result = $provider->download( $path, $dest );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			return array( $dest );
		}

		// Multi-part: derive sibling remote paths from part001.
		$base_remote = preg_replace( '/backup\.part\d+\.zip$/i', '', $path );
		for ( $i = 1; $i <= $parts_count; $i++ ) {
			$remote = $base_remote . sprintf( 'backup.part%03d.zip', $i );
			$dest   = $dir . '/' . sprintf( 'backup.part%03d.zip', $i );
			if ( ! is_readable( $dest ) ) {
				$result = $provider->download( $remote, $dest );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$downloaded[] = $dest;
		}

		return $downloaded;
	}

	/**
	 * Discover local ZIP parts for a backup key directory.
	 *
	 * @param string $dir  Work directory.
	 * @param string $path Stored primary path (may already be local).
	 * @return string[]
	 */
	public static function discover_local_parts( string $dir, string $path = '' ): array {
		$parts = array();

		if ( '' !== $path && is_readable( $path ) ) {
			$parts[] = $path;
			// If path is part001, include siblings.
			if ( preg_match( '/backup\.part(\d+)\.zip$/i', $path, $m ) ) {
				$parent = dirname( $path );
				$glob   = glob( $parent . '/backup.part*.zip' );
				if ( is_array( $glob ) ) {
					sort( $glob );
					foreach ( $glob as $g ) {
						if ( is_readable( $g ) && ! in_array( $g, $parts, true ) ) {
							$parts[] = $g;
						}
					}
				}
			}
			return array_values( array_unique( $parts ) );
		}

		$single = $dir . '/backup.zip';
		if ( is_readable( $single ) ) {
			$parts[] = $single;
		}

		$download = $dir . '/restore-download.zip';
		if ( is_readable( $download ) && ! in_array( $download, $parts, true ) ) {
			$parts[] = $download;
		}

		$glob = glob( $dir . '/backup.part*.zip' );
		if ( is_array( $glob ) ) {
			sort( $glob );
			foreach ( $glob as $g ) {
				if ( is_readable( $g ) && ! in_array( $g, $parts, true ) ) {
					$parts[] = $g;
				}
			}
		}

		$enc = glob( $dir . '/backup*.zip.enc' );
		if ( is_array( $enc ) ) {
			sort( $enc );
			foreach ( $enc as $g ) {
				if ( is_readable( $g ) && ! in_array( $g, $parts, true ) ) {
					$parts[] = $g;
				}
			}
		}

		return $parts;
	}

	/**
	 * Optionally decrypt an encrypted archive to a working path.
	 *
	 * @param string $path Local archive path.
	 * @return string|\WP_Error Readable (possibly decrypted) path.
	 */
	public static function maybe_decrypt_archive( string $path ) {
		if ( ! str_ends_with( strtolower( $path ), '.enc' ) && ! self::looks_encrypted( $path ) ) {
			return $path;
		}

		$passphrase = Maca_Backup_Pro_Settings::backup_passphrase();
		if ( '' === $passphrase ) {
			return new WP_Error( 'encrypted', __( 'Backup is encrypted. Set the backup passphrase in Settings.', 'maca-backup-pro' ) );
		}

		$out = preg_replace( '/\.enc$/i', '', $path );
		if ( $out === $path ) {
			$out = $path . '.decrypted.zip';
		}

		$result = Maca_Backup_Pro_Encryption::decrypt_file( $path, $out, $passphrase );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $out;
	}

	/**
	 * Heuristic: encrypted payload starts with macaenc1 magic.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private static function looks_encrypted( string $path ): bool {
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$magic = fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return is_string( $magic ) && str_starts_with( $magic, 'macaenc1' );
	}
}
