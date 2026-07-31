<?php
/**
 * Staging restore and automatic backup verification.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restores into a temporary folder and runs smoke checks.
 */
class Maca_Backup_Pro_Staging {

	/**
	 * Restore a backup into a staging directory (files only by default).
	 *
	 * @param int    $backup_id Backup ID.
	 * @param string $target    Absolute target dir (optional).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function restore( int $backup_id, string $target = '' ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup-pro' ) );
		}

		if ( '' === $target ) {
			$target = trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . 'staging-' . $backup->backup_key;
		}
		$target = untrailingslashit( $target );
		wp_mkdir_p( $target );

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$count = 0;
		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( in_array( $name, array( 'manifest.json', 'files.json', 'database.sql' ), true ) ) {
					// Still extract metadata for smoke tests.
				}
				$dest = $target . '/' . $name;
				$dir  = dirname( $dest );
				if ( ! is_dir( $dir ) ) {
					wp_mkdir_p( $dir );
				}
				$stream = $zip->getStream( $name );
				if ( ! $stream ) {
					continue;
				}
				$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( $out ) {
					while ( ! feof( $stream ) ) {
						$buf = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
						if ( false !== $buf ) {
							fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						}
					}
					fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					++$count;
				}
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$zip->close();
		}

		// Optional URL rewrite marker file for operators.
		$marker = array(
			'source_site' => home_url(),
			'staging_path'=> $target,
			'created_at'  => gmdate( 'c' ),
			'note'        => 'Search-replace site URLs before using as a live staging site.',
		);
		file_put_contents( $target . '/maca-staging.json', wp_json_encode( $marker, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		Maca_Backup_Pro_Logger::info(
			__( 'Staging restore completed.', 'maca-backup-pro' ),
			array(
				'backup_id' => $backup_id,
				'path'      => $target,
				'files'     => $count,
			)
		);

		return array(
			'path'       => $target,
			'file_count' => $count,
			'backup_id'  => $backup_id,
		);
	}

	/**
	 * Automatic verification: inspect archive parts (no full extract), then cleanup temps.
	 *
	 * @param int $backup_id Backup ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function verify( int $backup_id ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup-pro' ) );
		}

		$type = (string) ( $backup->type ?? 'full' );
		$needs_db = ! in_array( $type, array( 'files' ), true );

		$checks = array(
			'archive_ok'  => false,
			'manifest_ok' => false,
			'database_ok' => $needs_db ? false : null,
			'files_ok'    => false,
			'file_count'  => 0,
		);

		$verified = Maca_Backup_Pro_Verifier::verify_backup( $backup );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$checks['archive_ok'] = true;

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$found_manifest = false;
		$found_files_json = false;
		$db_bytes         = 0;
		$file_entries     = 0;
		$meta_names       = array( 'manifest.json', 'files.json', 'database.sql', 'maca-staging.json' );

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}

				$base = strtolower( basename( str_replace( '\\', '/', $name ) ) );
				$stat = $zip->statIndex( $i );
				$size = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;

				if ( 'manifest.json' === $base ) {
					$found_manifest = true;
				} elseif ( 'files.json' === $base ) {
					$found_files_json = true;
				} elseif ( 'database.sql' === $base ) {
					$db_bytes = max( $db_bytes, $size );
				}

				if ( ! in_array( $base, $meta_names, true ) ) {
					++$file_entries;
				}
			}

			$zip->close();
		}

		$checks['manifest_ok'] = $found_manifest;
		$checks['file_count']  = $file_entries;
		$checks['files_ok']    = $file_entries > 0 || 'database' === $type || $found_files_json;

		if ( $needs_db ) {
			$checks['database_ok'] = $db_bytes > 20;
		} else {
			$checks['database_ok'] = null;
		}

		$ok = $checks['archive_ok'] && $checks['manifest_ok'] && $checks['files_ok']
			&& ( null === $checks['database_ok'] || true === $checks['database_ok'] );

		Maca_Backup_Pro_Logger::info(
			$ok
				? __( 'Backup verification passed.', 'maca-backup-pro' )
				: __( 'Backup verification finished with issues.', 'maca-backup-pro' ),
			array_merge(
				array(
					'backup_id' => $backup_id,
					'db_bytes'  => $db_bytes,
					'type'      => $type,
				),
				$checks
			)
		);

		return array(
			'ok'     => $ok,
			'checks' => $checks,
		);
	}
}
