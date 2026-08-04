<?php
/**
 * Import backups from local files (migration between hosts).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers an uploaded maca BackUp archive as a local completed backup.
 */
class Maca_Backup_Pro_Importer {

	/**
	 * Import from a WordPress $_FILES entry (single archive or transfer zip).
	 *
	 * @param array<string, mixed> $file $_FILES['…'] row.
	 * @return int|\WP_Error New backup ID.
	 */
	public static function from_upload( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'upload', __( 'No file was uploaded.', 'maca-backup' ) );
		}

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'upload', self::upload_error_message( $error ) );
		}

		$name = sanitize_file_name( (string) ( $file['name'] ?? 'backup.zip' ) );
		$tmp  = (string) $file['tmp_name'];

		return self::from_path( $tmp, $name, true );
	}

	/**
	 * Import from a readable path on disk.
	 *
	 * @param string $source      Absolute path.
	 * @param string $origin_name Original filename (for extension hints).
	 * @param bool   $is_upload   Whether $source is an uploaded temp file.
	 * @return int|\WP_Error
	 */
	public static function from_path( string $source, string $origin_name = '', bool $is_upload = false ) {
		if ( ! is_readable( $source ) ) {
			return new WP_Error( 'readable', __( 'Uploaded file is not readable.', 'maca-backup' ) );
		}

		$key = 'mbp_import_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
		$dir = trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . $key;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mkdir', __( 'Could not create import directory.', 'maca-backup' ) );
		}

		$origin_name = '' !== $origin_name ? $origin_name : basename( $source );
		$lower       = strtolower( $origin_name );
		$dest_name   = self::preferred_archive_name( $lower );
		$dest        = trailingslashit( $dir ) . $dest_name;

		$moved = false;
		if ( $is_upload ) {
			// Prefer copy + delete over PHP's upload-move helper (Plugin Check forbidden).
			$moved = copy( $source, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( $moved ) {
				wp_delete_file( $source );
			}
		} else {
			$moved = copy( $source, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		}

		if ( ! $moved || ! is_readable( $dest ) ) {
			self::rrmdir( $dir );
			return new WP_Error( 'move', __( 'Could not store the uploaded backup.', 'maca-backup' ) );
		}

		$normalized = self::normalize_import_dir( $dir, $dest );
		if ( is_wp_error( $normalized ) ) {
			self::rrmdir( $dir );
			return $normalized;
		}

		$parts = Maca_Backup_Pro_Verifier::discover_local_parts( $dir );
		if ( empty( $parts ) ) {
			self::rrmdir( $dir );
			return new WP_Error( 'parts', __( 'No backup ZIP found in the upload.', 'maca-backup' ) );
		}

		$meta = self::read_archive_meta( $parts );
		if ( is_wp_error( $meta ) ) {
			self::rrmdir( $dir );
			return $meta;
		}

		$size = 0;
		foreach ( $parts as $part ) {
			$size += is_readable( $part ) ? (int) filesize( $part ) : 0;
		}

		$manifest  = $meta['manifest'];
		$inventory = $meta['inventory'];
		$type      = sanitize_key( (string) ( $manifest['type'] ?? 'full' ) );
		if ( '' === $type ) {
			$type = 'full';
		}

		$id = Maca_Backup_Pro_Backups_Table::insert(
			array(
				'backup_key'    => $key,
				'type'          => $type,
				'status'        => 'completed',
				'storage'       => 'local',
				'path'          => $parts[0],
				'parts'         => count( $parts ),
				'size_bytes'    => $size,
				'db_size_bytes' => 0,
				'file_count'    => (int) ( $manifest['file_count'] ?? count( $inventory ) ),
				'checksum'      => '',
				'manifest'      => wp_json_encode( $manifest ),
				'inventory'     => ! empty( $inventory ) ? wp_json_encode( $inventory ) : null,
				'started_at'    => current_time( 'mysql' ),
				'finished_at'   => current_time( 'mysql' ),
				'created_at'    => current_time( 'mysql' ),
				'error_message' => '',
			)
		);

		if ( $id < 1 ) {
			self::rrmdir( $dir );
			return new WP_Error( 'db', __( 'Could not register the imported backup.', 'maca-backup' ) );
		}

		Maca_Backup_Pro_Logger::success(
			__( 'Backup imported.', 'maca-backup' ),
			array(
				'backup_id' => $id,
				'key'       => $key,
				'parts'     => count( $parts ),
			)
		);

		return $id;
	}

	/**
	 * Choose on-disk name for the uploaded archive.
	 *
	 * @param string $lower Lowercased original name.
	 * @return string
	 */
	private static function preferred_archive_name( string $lower ): string {
		if ( str_ends_with( $lower, '.enc' ) ) {
			if ( preg_match( '/backup\.part\d+\.zip\.enc$/i', $lower ) ) {
				return basename( $lower );
			}
			return 'backup.zip.enc';
		}
		if ( preg_match( '/backup\.part\d+\.zip$/i', $lower ) ) {
			return basename( $lower );
		}
		return 'upload.zip';
	}

	/**
	 * Unpack transfer zips / rename single archives into discoverable parts.
	 *
	 * @param string $dir  Import directory.
	 * @param string $file Uploaded file absolute path.
	 * @return true|\WP_Error
	 */
	private static function normalize_import_dir( string $dir, string $file ) {
		$base = basename( $file );

		// Encrypted single archive — leave as backup.zip.enc for decrypt-on-restore.
		if ( str_ends_with( strtolower( $base ), '.enc' ) ) {
			if ( 'backup.zip.enc' !== $base && ! preg_match( '/backup\.part\d+\.zip\.enc$/i', $base ) ) {
				$target = trailingslashit( $dir ) . 'backup.zip.enc';
				if ( $file !== $target ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					rename( $file, $target );
				}
			}
			return true;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', __( 'ZipArchive is required to import backups.', 'maca-backup' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return new WP_Error( 'zip', __( 'Could not open the uploaded ZIP.', 'maca-backup' ) );
		}

		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false !== $name && ! str_ends_with( $name, '/' ) ) {
				$names[] = $name;
			}
		}

		$has_manifest = in_array( 'manifest.json', $names, true );
		$inner_parts  = array_values(
			array_filter(
				$names,
				static function ( string $n ): bool {
					$b = basename( $n );
					return (bool) preg_match( '/^(backup\.zip|backup\.part\d+\.zip)(\.enc)?$/i', $b );
				}
			)
		);

		// Transfer package: contains one or more maca backup part archives.
		// Stream each part to basename only — never extractTo() with archive-relative paths (Zip Slip).
		if ( ! $has_manifest && ! empty( $inner_parts ) ) {
			foreach ( $inner_parts as $inner ) {
				$safe_name = Maca_Backup_Pro_Security::safe_zip_entry_path( $inner );
				if ( false === $safe_name ) {
					$zip->close();
					return new WP_Error( 'extract', __( 'Could not extract backup parts from the upload.', 'maca-backup' ) );
				}
				$target = trailingslashit( $dir ) . basename( $safe_name );
				$dest   = Maca_Backup_Pro_Security::path_under_directory( $dir, basename( $safe_name ) );
				if ( false === $dest ) {
					$zip->close();
					return new WP_Error( 'extract', __( 'Could not extract backup parts from the upload.', 'maca-backup' ) );
				}

				$stream = $zip->getStream( $inner );
				if ( ! $stream ) {
					$zip->close();
					return new WP_Error( 'extract', __( 'Could not extract backup parts from the upload.', 'maca-backup' ) );
				}
				$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $out ) {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					$zip->close();
					return new WP_Error( 'extract', __( 'Could not extract backup parts from the upload.', 'maca-backup' ) );
				}
				while ( ! feof( $stream ) ) {
					$buf = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false !== $buf ) {
						fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					}
				}
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

				if ( ! is_readable( $target ) ) {
					$zip->close();
					return new WP_Error( 'extract', __( 'Could not extract backup parts from the upload.', 'maca-backup' ) );
				}
			}
			$zip->close();
			wp_delete_file( $file );
			return true;
		}

		// Native maca archive (manifest inside).
		if ( $has_manifest ) {
			$zip->close();
			$target = trailingslashit( $dir ) . 'backup.zip';
			if ( $file !== $target ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $file, $target );
			}
			return true;
		}

		$zip->close();
		return new WP_Error(
			'format',
			__( 'Not a maca BackUp archive. Expected a backup ZIP (with manifest.json) or a transfer package of backup parts.', 'maca-backup' )
		);
	}

	/**
	 * Read manifest (+ optional inventory) from local parts.
	 *
	 * @param string[] $parts Absolute paths.
	 * @return array{manifest:array<string,mixed>,inventory:array<string,mixed>}|\WP_Error
	 */
	private static function read_archive_meta( array $parts ) {
		$manifest  = array();
		$inventory = array();

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( (string) $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			if ( ! class_exists( 'ZipArchive' ) ) {
				return new WP_Error( 'zip', __( 'ZipArchive is required to import backups.', 'maca-backup' ) );
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}
			$raw = $zip->getFromName( 'manifest.json' );
			if ( false !== $raw && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$manifest = $decoded;
				}
			}
			$inv_raw = $zip->getFromName( 'files.json' );
			if ( false !== $inv_raw && '' !== $inv_raw ) {
				$decoded = json_decode( $inv_raw, true );
				if ( is_array( $decoded ) ) {
					$inventory = $decoded;
				}
			}
			$zip->close();
			if ( ! empty( $manifest ) ) {
				break;
			}
		}

		if ( empty( $manifest ) ) {
			return new WP_Error( 'manifest', __( 'Backup manifest.json is missing — cannot import this file.', 'maca-backup' ) );
		}

		$manifest['imported_at'] = gmdate( 'c' );
		$manifest['imported']    = true;

		return array(
			'manifest'  => $manifest,
			'inventory' => $inventory,
		);
	}

	/**
	 * Human message for PHP upload error codes.
	 *
	 * @param int $code Upload error.
	 * @return string
	 */
	private static function upload_error_message( int $code ): string {
		return match ( $code ) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf(
				/* translators: %s: max upload size */
				__( 'File is too large. Server limit is %s. Try uploading parts separately or raise upload_max_filesize.', 'maca-backup' ),
				size_format( wp_max_upload_size() )
			),
			UPLOAD_ERR_PARTIAL => __( 'The upload was incomplete. Please try again.', 'maca-backup' ),
			UPLOAD_ERR_NO_FILE => __( 'No file was uploaded.', 'maca-backup' ),
			default            => __( 'Upload failed.', 'maca-backup' ),
		};
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function rrmdir( string $dir ): void {
		$dir = untrailingslashit( $dir );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = glob( $dir . '/{,.}*', GLOB_BRACE );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				$base = basename( (string) $item );
				if ( '.' === $base || '..' === $base ) {
					continue;
				}
				if ( is_dir( $item ) ) {
					self::rrmdir( (string) $item );
				} else {
					wp_delete_file( (string) $item );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
