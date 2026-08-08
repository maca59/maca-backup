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

	public const CHUNK_TTL          = 12 * HOUR_IN_SECONDS;
	public const CHUNK_DIR_NAME     = '.import-chunks';
	public const MAX_IMPORT_DEFAULT = 53687091200; // 50 GiB.

	/**
	 * Max bytes accepted for one assembled import (filterable).
	 *
	 * @return int
	 */
	public static function max_import_bytes(): int {
		$max = (int) apply_filters( 'maca_backup_import_max_bytes', self::MAX_IMPORT_DEFAULT );
		return max( 64 * 1024 * 1024, $max );
	}

	/**
	 * Recommended chunk size for multi-part imports (under PHP post/upload limits).
	 *
	 * @return int
	 */
	public static function recommended_chunk_bytes(): int {
		$php_max = (int) wp_max_upload_size();
		if ( $php_max < 1 ) {
			$php_max = 2 * 1024 * 1024;
		}
		// Leave headroom for multipart form fields / overhead.
		$safe = (int) floor( $php_max * 0.45 );
		$safe = max( 256 * 1024, $safe );
		// Cap chunk size so each AJAX request stays reliable on shared hosts.
		$chunk = (int) min( 8 * 1024 * 1024, $safe );
		return (int) apply_filters( 'maca_backup_import_chunk_bytes', $chunk );
	}

	/**
	 * Largest single-request upload we will attempt before switching to chunks.
	 *
	 * @return int
	 */
	public static function direct_upload_limit(): int {
		$php_max = (int) wp_max_upload_size();
		if ( $php_max < 1 ) {
			return 1024 * 1024;
		}
		return (int) max( 256 * 1024, (int) floor( $php_max * 0.80 ) );
	}

	/**
	 * Start a chunked import session.
	 *
	 * @param string $filename Original filename.
	 * @param int    $size     Total bytes.
	 * @param int    $chunks   Expected chunk count.
	 * @return array{token:string,chunk_bytes:int}|\WP_Error
	 */
	public static function start_chunked( string $filename, int $size, int $chunks ) {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			$filename = 'backup.zip';
		}
		$lower = strtolower( $filename );
		if ( ! preg_match( '/\.(zip|enc)$/', $lower ) ) {
			return new WP_Error( 'type', __( 'Only .zip or .enc backup archives can be imported.', 'maca-backup' ) );
		}

		$size   = max( 0, $size );
		$chunks = max( 1, $chunks );
		$max    = self::max_import_bytes();
		if ( $size < 1 || $size > $max ) {
			return new WP_Error(
				'size',
				sprintf(
					/* translators: %s: max size */
					__( 'Import file is too large. Maximum is %s.', 'maca-backup' ),
					size_format( $max )
				)
			);
		}

		$chunk_bytes = self::recommended_chunk_bytes();
		$chunks      = max( 1, (int) ceil( $size / max( 1, $chunk_bytes ) ) );

		$dir = self::chunk_root();
		if ( '' === $dir ) {
			return new WP_Error( 'mkdir', __( 'Could not create import staging directory.', 'maca-backup' ) );
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$token = wp_generate_password( 32, false, false );
		}

		$session_dir = trailingslashit( $dir ) . $token;
		if ( ! wp_mkdir_p( $session_dir ) ) {
			return new WP_Error( 'mkdir', __( 'Could not create import staging directory.', 'maca-backup' ) );
		}

		$part_path = trailingslashit( $session_dir ) . 'upload.bin';
		$touch     = fopen( $part_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $touch ) {
			self::rrmdir( $session_dir );
			return new WP_Error( 'write', __( 'Could not create staging file for import.', 'maca-backup' ) );
		}
		fclose( $touch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$session = array(
			'user_id'  => get_current_user_id(),
			'name'     => $filename,
			'size'     => $size,
			'chunks'   => $chunks,
			'next'     => 0,
			'received' => 0,
			'path'     => $part_path,
			'dir'      => $session_dir,
			'started'  => time(),
		);
		set_transient( self::chunk_transient_key( $token ), $session, self::CHUNK_TTL );
		self::cleanup_stale_chunk_dirs();

		return array(
			'token'       => $token,
			'chunk_bytes' => $chunk_bytes,
			'chunks'      => $chunks,
		);
	}

	/**
	 * Append one uploaded chunk to the staging file.
	 *
	 * @param string               $token Upload token.
	 * @param int                  $index Zero-based chunk index.
	 * @param array<string, mixed> $file  $_FILES row.
	 * @return array{received:int,next:int,done:bool}|\WP_Error
	 */
	public static function receive_chunk( string $token, int $index, array $file ) {
		$session = self::get_chunk_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$index = max( 0, $index );
		if ( $index !== (int) $session['next'] ) {
			return new WP_Error(
				'order',
				sprintf(
					/* translators: 1: expected index, 2: received index */
					__( 'Import chunk out of order (expected %1$d, got %2$d).', 'maca-backup' ),
					(int) $session['next'],
					$index
				)
			);
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			return new WP_Error( 'upload', __( 'No chunk was uploaded.', 'maca-backup' ) );
		}
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'upload', self::upload_error_message( $error ) );
		}

		$chunk_path = (string) $file['tmp_name'];
		$chunk_size = is_readable( $chunk_path ) ? (int) filesize( $chunk_path ) : 0;
		if ( $chunk_size < 1 ) {
			return new WP_Error( 'empty', __( 'Uploaded chunk was empty.', 'maca-backup' ) );
		}

		$max_chunk = self::recommended_chunk_bytes() + ( 512 * 1024 ); // small slack
		if ( $chunk_size > $max_chunk ) {
			return new WP_Error( 'chunk', __( 'Chunk exceeds the allowed size.', 'maca-backup' ) );
		}

		$remaining = (int) $session['size'] - (int) $session['received'];
		if ( $chunk_size > $remaining ) {
			return new WP_Error( 'size', __( 'Chunk would exceed the declared file size.', 'maca-backup' ) );
		}

		$out = fopen( (string) $session['path'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			return new WP_Error( 'write', __( 'Could not write import chunk.', 'maca-backup' ) );
		}
		$in = fopen( $chunk_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $in ) {
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new WP_Error( 'read', __( 'Could not read uploaded chunk.', 'maca-backup' ) );
		}
		$written = stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( false === $written || (int) $written !== $chunk_size ) {
			return new WP_Error( 'write', __( 'Could not write import chunk.', 'maca-backup' ) );
		}

		$session['received'] = (int) $session['received'] + $chunk_size;
		$session['next']     = $index + 1;
		set_transient( self::chunk_transient_key( $token ), $session, self::CHUNK_TTL );

		$done = (int) $session['received'] >= (int) $session['size'];
		return array(
			'received' => (int) $session['received'],
			'next'     => (int) $session['next'],
			'done'     => $done,
			'size'     => (int) $session['size'],
		);
	}

	/**
	 * Finalize a chunked upload and register the backup.
	 *
	 * @param string $token Upload token.
	 * @return int|\WP_Error Backup ID.
	 */
	public static function finalize_chunked( string $token ) {
		$session = self::get_chunk_session( $token );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$path = (string) $session['path'];
		$dir  = (string) $session['dir'];
		$name = (string) $session['name'];
		$size = (int) $session['size'];

		if ( ! is_readable( $path ) ) {
			self::abort_chunked( $token );
			return new WP_Error( 'missing', __( 'Assembled import file is missing.', 'maca-backup' ) );
		}

		$actual = (int) filesize( $path );
		if ( $actual !== $size ) {
			self::abort_chunked( $token );
			return new WP_Error(
				'size',
				sprintf(
					/* translators: 1: expected bytes, 2: actual bytes */
					__( 'Import incomplete (expected %1$s, got %2$s).', 'maca-backup' ),
					size_format( $size ),
					size_format( $actual )
				)
			);
		}

		$result = self::from_path( $path, $name, false );
		self::abort_chunked( $token );

		return $result;
	}

	/**
	 * Abort and delete a chunked import session.
	 *
	 * @param string $token Upload token.
	 * @return true
	 */
	public static function abort_chunked( string $token ): bool {
		$token = preg_replace( '/[^a-f0-9]/i', '', $token ) ?? '';
		if ( '' === $token ) {
			return true;
		}
		$key     = self::chunk_transient_key( $token );
		$session = get_transient( $key );
		delete_transient( $key );
		if ( is_array( $session ) && ! empty( $session['dir'] ) ) {
			$dir = (string) $session['dir'];
			$root = self::chunk_root();
			if ( '' !== $root && str_starts_with( wp_normalize_path( $dir ), wp_normalize_path( $root ) ) ) {
				self::rrmdir( $dir );
			}
		}
		return true;
	}

	/**
	 * Load and authorize a chunk session for the current user.
	 *
	 * @param string $token Token.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function get_chunk_session( string $token ) {
		$token = preg_replace( '/[^a-f0-9]/i', '', $token ) ?? '';
		if ( strlen( $token ) < 16 ) {
			return new WP_Error( 'token', __( 'Invalid import upload token.', 'maca-backup' ) );
		}
		$session = get_transient( self::chunk_transient_key( $token ) );
		if ( ! is_array( $session ) || empty( $session['path'] ) ) {
			return new WP_Error( 'token', __( 'Import upload session expired. Please try again.', 'maca-backup' ) );
		}
		if ( (int) ( $session['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'auth', __( 'Import upload session does not belong to this user.', 'maca-backup' ) );
		}
		return $session;
	}

	/**
	 * Transient key for a chunk token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function chunk_transient_key( string $token ): string {
		return 'maca_bp_imp_' . $token;
	}

	/**
	 * Absolute staging root for chunked imports.
	 *
	 * @return string
	 */
	private static function chunk_root(): string {
		$base = Maca_Backup_Pro_Settings::local_backup_dir();
		if ( '' === $base ) {
			return '';
		}
		$dir = trailingslashit( $base ) . self::CHUNK_DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		Maca_Backup_Pro_Settings::protect_directory( $dir );
		return wp_normalize_path( untrailingslashit( $dir ) );
	}

	/**
	 * Remove abandoned chunk staging folders older than the session TTL.
	 *
	 * @return void
	 */
	private static function cleanup_stale_chunk_dirs(): void {
		$root = self::chunk_root();
		if ( '' === $root || ! is_dir( $root ) ) {
			return;
		}
		$cutoff = time() - self::CHUNK_TTL;
		$items  = glob( $root . '/*', GLOB_ONLYDIR );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $dir ) {
			$dir = (string) $dir;
			$mtime = (int) @filemtime( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $mtime > 0 && $mtime < $cutoff ) {
				self::rrmdir( $dir );
			}
		}
	}

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
			// Prefer rename for multi-GB chunked imports (avoids doubling disk use).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			$moved = @rename( $source, $dest );
			if ( ! $moved ) {
				$moved = copy( $source, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				if ( $moved ) {
					wp_delete_file( $source );
				}
			}
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

		$crc_result = self::resolve_import_crc( $parts, $manifest );
		if ( is_wp_error( $crc_result ) ) {
			self::rrmdir( $dir );
			return $crc_result;
		}
		$checksum = (string) $crc_result;
		$manifest['crc32'] = $checksum;

		// Ensure per-file CRC is available for Compare / Smart Restore after migration.
		$plain_parts = array();
		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( (string) $part );
			if ( ! is_wp_error( $ready ) && is_string( $ready ) && '' !== $ready ) {
				$plain_parts[] = $ready;
			}
		}
		$inventory = Maca_Backup_Pro_Checksum::enrich_inventory_crc_from_parts(
			is_array( $inventory ) ? $inventory : array(),
			$plain_parts
		);

		$type = sanitize_key( (string) ( $manifest['type'] ?? 'full' ) );
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
				'checksum'      => $checksum,
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
	 * Compute archive CRC32 and verify against manifest when present.
	 *
	 * @param string[]             $parts    Local part paths.
	 * @param array<string, mixed> $manifest Manifest (may include crc32 / part_crc32).
	 * @return string|\WP_Error 8-char lowercase hex CRC.
	 */
	private static function resolve_import_crc( array $parts, array $manifest ) {
		$actual_map = Maca_Backup_Pro_Checksum::crc32_map( $parts );
		if ( empty( $actual_map ) ) {
			return new WP_Error( 'crc', __( 'Could not compute CRC32 for the imported archive.', 'maca-backup' ) );
		}

		$actual = Maca_Backup_Pro_Checksum::crc32_parts( $parts );
		if ( '' === $actual ) {
			return new WP_Error( 'crc', __( 'Could not compute CRC32 for the imported archive.', 'maca-backup' ) );
		}

		$expected_parts = isset( $manifest['part_crc32'] ) && is_array( $manifest['part_crc32'] )
			? $manifest['part_crc32']
			: array();
		foreach ( $expected_parts as $base => $expected_crc ) {
			$base = (string) $base;
			$want = Maca_Backup_Pro_Checksum::normalize_crc32( (string) $expected_crc );
			if ( '' === $want ) {
				continue;
			}
			$got = Maca_Backup_Pro_Checksum::normalize_crc32( (string) ( $actual_map[ $base ] ?? '' ) );
			if ( '' === $got || ! hash_equals( $want, $got ) ) {
				return new WP_Error(
					'crc',
					sprintf(
						/* translators: %s: archive part basename */
						__( 'CRC32 mismatch for %s — the upload may be corrupted. Re-download and import again.', 'maca-backup' ),
						$base
					)
				);
			}
		}

		$expected = Maca_Backup_Pro_Checksum::normalize_crc32( (string) ( $manifest['crc32'] ?? '' ) );
		if ( '' !== $expected && ! hash_equals( $expected, $actual ) ) {
			return new WP_Error(
				'crc',
				sprintf(
					/* translators: 1: expected CRC, 2: actual CRC */
					__( 'Archive CRC32 mismatch (expected %1$s, got %2$s). The upload may be corrupted.', 'maca-backup' ),
					strtoupper( $expected ),
					strtoupper( $actual )
				)
			);
		}

		return $actual;
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
