<?php
/**
 * Smart Restore — compare live site with backup.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diffs current installation against a backup for selective restore.
 */
class Maca_Backup_Pro_Smart_Restore {

	/**
	 * Compare site vs backup and return a structured diff.
	 *
	 * @param int $backup_id Backup ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compare( int $backup_id ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup' ) );
		}

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$backup_files = array();
		$inventory    = self::load_inventory( $backup, $parts );

		if ( ! empty( $inventory ) ) {
			foreach ( $inventory as $name => $meta ) {
				if ( in_array( $name, array( 'manifest.json', 'database.sql', 'files.json' ), true ) ) {
					continue;
				}
				$backup_files[ $name ] = array(
					'size'  => (int) ( $meta['size'] ?? 0 ),
					'crc'   => (int) ( $meta['crc'] ?? 0 ),
					'mtime' => (int) ( $meta['mtime'] ?? 0 ),
				);
			}
		} else {
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
					if ( in_array( $name, array( 'manifest.json', 'database.sql', 'files.json' ), true ) ) {
						continue;
					}
					$stat = $zip->statIndex( $i );
					$backup_files[ $name ] = array(
						'size'  => (int) ( $stat['size'] ?? 0 ),
						'crc'   => (int) ( $stat['crc'] ?? 0 ),
						'mtime' => (int) ( $stat['mtime'] ?? 0 ),
					);
				}
				$zip->close();
			}
		}

		$new       = array();
		$changed   = array();
		$unchanged = array();
		$checked   = array();

		foreach ( $backup_files as $rel => $meta ) {
			$abs             = Maca_Backup_Pro_Paths::absolute( (string) $rel );
			$checked[ $rel ] = true;

			if ( ! file_exists( $abs ) ) {
				$new[] = array(
					'path'   => $rel,
					'size'   => $meta['size'],
					'action' => 'create',
				);
				continue;
			}

			$live_size  = (int) filesize( $abs );
			$bak_crc    = (int) ( $meta['crc'] ?? 0 );
			$bak_mtime  = (int) ( $meta['mtime'] ?? 0 );
			$changed_ok = false;

			if ( $bak_crc > 0 ) {
				$live_crc   = self::file_crc32( $abs );
				$changed_ok = ( $live_size !== $meta['size'] || $live_crc !== $bak_crc );
			} else {
				$live_mtime = (int) filemtime( $abs );
				$changed_ok = ( $live_size !== $meta['size'] || ( $bak_mtime > 0 && $live_mtime !== $bak_mtime ) );
			}

			if ( $changed_ok ) {
				$changed[] = array(
					'path'      => $rel,
					'live_size' => $live_size,
					'bak_size'  => $meta['size'],
					'action'    => 'overwrite',
				);
			} else {
				$unchanged[] = $rel;
			}
		}

		$deleted     = array();
		$scan_scopes = array(
			'plugins'    => Maca_Backup_Pro_Paths::scope_directory( 'plugins' ),
			'themes'     => Maca_Backup_Pro_Paths::scope_directory( 'themes' ),
			'uploads'    => Maca_Backup_Pro_Paths::scope_directory( 'uploads' ),
			'mu-plugins' => Maca_Backup_Pro_Paths::scope_directory( 'mu-plugins' ),
		);
		foreach ( $scan_scopes as $root ) {
			if ( '' === $root || ! is_dir( $root ) ) {
				continue;
			}
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $fileinfo ) {
				if ( ! $fileinfo->isFile() ) {
					continue;
				}
				$rel = Maca_Backup_Pro_File_Scanner::relative_to_abspath( $fileinfo->getPathname() );
				if ( null === $rel || isset( $checked[ $rel ] ) || isset( $backup_files[ $rel ] ) ) {
					continue;
				}
				$deleted[] = array(
					'path'   => $rel,
					'action' => 'missing_in_backup',
				);
				if ( count( $deleted ) >= 500 ) {
					break 2;
				}
			}
		}

		$plugin_diff = array();
		$theme_diff  = array();
		$db_summary  = array( 'included' => false );

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}
			if ( empty( $plugin_diff ) ) {
				$plugin_diff = self::version_diff( 'plugins', $zip );
			}
			if ( empty( $theme_diff ) ) {
				$theme_diff = self::version_diff( 'themes', $zip );
			}
			$db_summary = self::database_summary( $zip );
			$zip->close();
			if ( ! empty( $db_summary['included'] ) ) {
				break;
			}
		}

		return array(
			'backup_id'       => $backup_id,
			'summary'         => array(
				'new'       => count( $new ),
				'changed'   => count( $changed ),
				'unchanged' => count( $unchanged ),
				'deleted'   => count( $deleted ),
			),
			'new_files'       => array_slice( $new, 0, 500 ),
			'changed_files'   => array_slice( $changed, 0, 500 ),
			'deleted_files'   => $deleted,
			'plugin_versions' => $plugin_diff,
			'theme_versions'  => $theme_diff,
			'database'        => $db_summary,
			'used_inventory'  => ! empty( $inventory ),
		);
	}

	/**
	 * Compare two backups (inventory / archive contents).
	 *
	 * @param int $backup_id_a First backup ID.
	 * @param int $backup_id_b Second backup ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function compare_backups( int $backup_id_a, int $backup_id_b ) {
		if ( $backup_id_a < 1 || $backup_id_b < 1 || $backup_id_a === $backup_id_b ) {
			return new WP_Error( 'invalid', __( 'Select two different backups to compare.', 'maca-backup' ) );
		}

		$a = Maca_Backup_Pro_Backups_Table::get( $backup_id_a );
		$b = Maca_Backup_Pro_Backups_Table::get( $backup_id_b );
		if ( ! $a || ! $b ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup' ) );
		}

		$files_a = self::backup_file_map( $a );
		if ( is_wp_error( $files_a ) ) {
			return $files_a;
		}
		$files_b = self::backup_file_map( $b );
		if ( is_wp_error( $files_b ) ) {
			return $files_b;
		}

		$only_a    = array();
		$only_b    = array();
		$mismatch  = array();
		$same      = 0;
		$bytes_a   = 0;
		$bytes_b   = 0;

		foreach ( $files_a as $path => $meta ) {
			$bytes_a += (int) ( $meta['size'] ?? 0 );
			if ( ! isset( $files_b[ $path ] ) ) {
				$only_a[] = array(
					'path' => $path,
					'size' => (int) ( $meta['size'] ?? 0 ),
				);
				continue;
			}
			$size_a = (int) ( $meta['size'] ?? 0 );
			$size_b = (int) ( $files_b[ $path ]['size'] ?? 0 );
			$crc_a  = (int) ( $meta['crc'] ?? 0 );
			$crc_b  = (int) ( $files_b[ $path ]['crc'] ?? 0 );
			if ( $size_a !== $size_b || ( $crc_a > 0 && $crc_b > 0 && $crc_a !== $crc_b ) ) {
				$mismatch[] = array(
					'path'   => $path,
					'size_a' => $size_a,
					'size_b' => $size_b,
					'crc_a'  => $crc_a,
					'crc_b'  => $crc_b,
				);
			} else {
				++$same;
			}
		}

		foreach ( $files_b as $path => $meta ) {
			$bytes_b += (int) ( $meta['size'] ?? 0 );
			if ( ! isset( $files_a[ $path ] ) ) {
				$only_b[] = array(
					'path' => $path,
					'size' => (int) ( $meta['size'] ?? 0 ),
				);
			}
		}

		usort(
			$only_a,
			static fn( $x, $y ) => ( (int) $y['size'] ) <=> ( (int) $x['size'] )
		);
		usort(
			$only_b,
			static fn( $x, $y ) => ( (int) $y['size'] ) <=> ( (int) $x['size'] )
		);
		usort(
			$mismatch,
			static fn( $x, $y ) => abs( (int) $y['size_b'] - (int) $y['size_a'] ) <=> abs( (int) $x['size_b'] - (int) $x['size_a'] )
		);

		$count_a = count( $files_a );
		$count_b = count( $files_b );
		$arch_a  = (int) ( $a->size_bytes ?? 0 );
		$arch_b  = (int) ( $b->size_bytes ?? 0 );
		$ratio_a = $bytes_a > 0 ? ( $arch_a / $bytes_a ) : 0;
		$ratio_b = $bytes_b > 0 ? ( $arch_b / $bytes_b ) : 0;

		$verdict = '';
		if ( empty( $only_a ) && empty( $only_b ) && empty( $mismatch ) ) {
			$verdict = __( 'Same file set — archive sizes may still differ due to ZIP compression.', 'maca-backup' );
		} elseif (
			$bytes_a > 50 * MB_IN_BYTES
			&& $bytes_b > 50 * MB_IN_BYTES
			&& abs( $bytes_a - $bytes_b ) / max( $bytes_a, $bytes_b ) < 0.15
			&& max( $arch_a, $arch_b ) > 0
			&& min( $arch_a, $arch_b ) > 0
			&& ( max( $arch_a, $arch_b ) / min( $arch_a, $arch_b ) ) >= 3
		) {
			// Same ~content, wildly different ZIP size: compression/bloat/incomplete pack.
			if ( $ratio_a > 0.7 && $ratio_b < 0.35 ) {
				$verdict = __( 'Same amount of file content, but A’s ZIP is almost uncompressed while B is far smaller. B may be incomplete inside the archive, or A may be bloated (duplicate ZIP entries / STORE). Prefer a new full backup after updating; do not trust size alone.', 'maca-backup' );
			} elseif ( $ratio_b > 0.7 && $ratio_a < 0.35 ) {
				$verdict = __( 'Same amount of file content, but B’s ZIP is almost uncompressed while A is far smaller. A may be incomplete inside the archive, or B may be bloated (duplicate ZIP entries / STORE). Prefer a new full backup after updating; do not trust size alone.', 'maca-backup' );
			} else {
				$verdict = __( 'File lists are similar but archive sizes differ a lot — likely ZIP packing/compression issue, not that the site grew overnight.', 'maca-backup' );
			}
		} elseif ( $count_a > 0 && ( $count_b / $count_a ) >= 4 && count( $only_b ) > count( $only_a ) ) {
			$verdict = __( 'Backup B contains far more files than A — A may be incomplete, or B may include extra paths (e.g. duplicated ZIP entries).', 'maca-backup' );
		} elseif ( $count_b > 0 && ( $count_a / max( 1, $count_b ) ) >= 4 && count( $only_a ) > count( $only_b ) ) {
			$verdict = __( 'Backup A contains far more files than B — B may be incomplete.', 'maca-backup' );
		} else {
			$verdict = __( 'Backups differ. Review paths only in one archive and size mismatches below.', 'maca-backup' );
		}

		$cap = 40;

		return array(
			'a'       => self::backup_summary_row( $a, $count_a, $bytes_a ),
			'b'       => self::backup_summary_row( $b, $count_b, $bytes_b ),
			'summary' => array(
				'files_a'        => $count_a,
				'files_b'        => $count_b,
				'bytes_a'        => $bytes_a,
				'bytes_b'        => $bytes_b,
				'same'           => $same,
				'only_in_a'      => count( $only_a ),
				'only_in_b'      => count( $only_b ),
				'size_mismatch'  => count( $mismatch ),
				'archive_size_a' => (int) ( $a->size_bytes ?? 0 ),
				'archive_size_b' => (int) ( $b->size_bytes ?? 0 ),
			),
			'verdict' => $verdict,
			'only_in_a' => array_slice( $only_a, 0, $cap ),
			'only_in_b' => array_slice( $only_b, 0, $cap ),
			'size_mismatch' => array_slice( $mismatch, 0, $cap ),
			'truncated' => array(
				'only_in_a'     => max( 0, count( $only_a ) - $cap ),
				'only_in_b'     => max( 0, count( $only_b ) - $cap ),
				'size_mismatch' => max( 0, count( $mismatch ) - $cap ),
			),
		);
	}

	/**
	 * Summary card for one backup in a compare result.
	 *
	 * @param object $backup    Backup row.
	 * @param int    $files     Inventory file count.
	 * @param int    $bytes     Uncompressed inventory bytes.
	 * @return array<string, mixed>
	 */
	private static function backup_summary_row( object $backup, int $files, int $bytes ): array {
		$when = ! empty( $backup->finished_at ) ? (string) $backup->finished_at : (string) ( $backup->created_at ?? '' );
		$crc  = Maca_Backup_Pro_Format::backup_checksum_label( $backup );
		return array(
			'id'            => (int) $backup->id,
			'type'          => (string) ( $backup->type ?? '' ),
			'date'          => Maca_Backup_Pro_Format::datetime_local( $when ),
			'archive_size'  => (int) ( $backup->size_bytes ?? 0 ),
			'file_count'    => $files,
			'content_bytes' => $bytes,
			'parts'         => (int) ( $backup->parts ?? 1 ),
			'crc32'         => ( '—' === $crc ) ? '' : $crc,
		);
	}

	/**
	 * Map of relative path => size/crc for a backup (skips plugin meta files).
	 *
	 * @param object $backup Backup row.
	 * @return array<string, array{size:int,crc:int,mtime:int}>|\WP_Error
	 */
	private static function backup_file_map( object $backup ) {
		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$inventory = self::load_inventory( $backup, $parts );
		$out       = array();
		$skip      = array( 'manifest.json', 'database.sql', 'files.json' );

		if ( ! empty( $inventory ) ) {
			$needs_crc = false;
			foreach ( $inventory as $meta ) {
				if ( is_array( $meta ) && empty( $meta['crc'] ) ) {
					$needs_crc = true;
					break;
				}
			}
			if ( $needs_crc ) {
				$plain = array();
				foreach ( $parts as $part ) {
					$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( (string) $part );
					if ( ! is_wp_error( $ready ) && is_string( $ready ) && '' !== $ready ) {
						$plain[] = $ready;
					}
				}
				$inventory = Maca_Backup_Pro_Checksum::enrich_inventory_crc_from_parts( $inventory, $plain );
			}
			foreach ( $inventory as $name => $meta ) {
				$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
				if ( '' === $name || in_array( $name, $skip, true ) || str_ends_with( $name, '/' ) ) {
					continue;
				}
				$out[ $name ] = array(
					'size'  => (int) ( is_array( $meta ) ? ( $meta['size'] ?? 0 ) : 0 ),
					'crc'   => (int) ( is_array( $meta ) ? ( $meta['crc'] ?? 0 ) : 0 ),
					'mtime' => (int) ( is_array( $meta ) ? ( $meta['mtime'] ?? 0 ) : 0 ),
				);
			}
			return $out;
		}

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
				$name = ltrim( str_replace( '\\', '/', (string) $name ), '/' );
				if ( '' === $name || in_array( $name, $skip, true ) ) {
					continue;
				}
				$stat = $zip->statIndex( $i );
				// Keep first occurrence; duplicates inflate size and would hide the issue.
				if ( isset( $out[ $name ] ) ) {
					continue;
				}
				$out[ $name ] = array(
					'size'  => (int) ( $stat['size'] ?? 0 ),
					'crc'   => (int) ( $stat['crc'] ?? 0 ),
					'mtime' => (int) ( $stat['mtime'] ?? 0 ),
				);
			}
			$zip->close();
		}

		if ( empty( $out ) ) {
			return new WP_Error(
				'empty',
				sprintf(
					/* translators: %d: backup ID */
					__( 'Could not read file list for backup #%d.', 'maca-backup' ),
					(int) $backup->id
				)
			);
		}

		return $out;
	}

	/**
	 * Load inventory from DB column or files.json inside archive.
	 *
	 * @param object   $backup Backup row.
	 * @param string[] $parts  Local archive paths.
	 * @return array<string, array<string, mixed>>
	 */
	private static function load_inventory( object $backup, array $parts ): array {
		if ( ! empty( $backup->inventory ) ) {
			$decoded = json_decode( (string) $backup->inventory, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				continue;
			}
			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}
			$raw = $zip->getFromName( 'files.json' );
			$zip->close();
			if ( is_string( $raw ) && '' !== $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		return array();
	}

	/**
	 * Start a selective restore from Smart Restore selections.
	 *
	 * @param int      $backup_id Backup ID.
	 * @param string[] $files     Relative paths to restore.
	 * @param bool     $database  Whether to restore DB.
	 * @return array{job_id:int}|\WP_Error
	 */
	public static function restore_selected( int $backup_id, array $files, bool $database = false ) {
		$files = array_values(
			array_filter(
				array_map(
					static fn( $p ) => ltrim( str_replace( '\\', '/', (string) $p ), '/' ),
					$files
				)
			)
		);

		if ( $database && empty( $files ) ) {
			return Maca_Backup_Pro_Restore_Engine::start( $backup_id, 'database' );
		}

		if ( empty( $files ) && ! $database ) {
			return new WP_Error( 'empty', __( 'Select files or the database to restore.', 'maca-backup' ) );
		}

		return Maca_Backup_Pro_Restore_Engine::start(
			$backup_id,
			'path',
			array(
				'selected_files'   => $files,
				'restore_database' => $database,
			)
		);
	}

	/**
	 * CRC32 of a file (matches ZipArchive crc).
	 *
	 * @param string $path File path.
	 * @return int
	 */
	private static function file_crc32( string $path ): int {
		$hash = hash_file( 'crc32b', $path );
		if ( false === $hash ) {
			return 0;
		}
		return (int) hexdec( $hash );
	}

	/**
	 * Compare plugin/theme versions using headers in backup vs live.
	 *
	 * @param string     $type plugins|themes.
	 * @param ZipArchive $zip  Open archive.
	 * @return array<int, array<string, mixed>>
	 */
	private static function version_diff( string $type, ZipArchive $zip ): array {
		$prefix = 'plugins' === $type ? 'wp-content/plugins/' : 'wp-content/themes/';
		$found  = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name || ! str_starts_with( $name, $prefix ) ) {
				continue;
			}
			$parts = explode( '/', substr( $name, strlen( $prefix ) ) );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$slug = $parts[0];
			if ( isset( $found[ $slug ] ) ) {
				continue;
			}

			$candidate = '';
			if ( 'plugins' === $type ) {
				$candidate = $prefix . $slug . '/' . $slug . '.php';
			} else {
				$candidate = $prefix . $slug . '/style.css';
			}

			$idx = $zip->locateName( $candidate );
			if ( false === $idx ) {
				$found[ $slug ] = array(
					'slug'           => $slug,
					'backup_version' => null,
					'live_version'   => self::live_version( $type, $slug ),
				);
				continue;
			}

			$content = $zip->getFromIndex( $idx );
			$bak_ver = is_string( $content ) ? self::parse_version_header( $content ) : null;
			$found[ $slug ] = array(
				'slug'           => $slug,
				'backup_version' => $bak_ver,
				'live_version'   => self::live_version( $type, $slug ),
				'changed'        => ( $bak_ver && self::live_version( $type, $slug ) && $bak_ver !== self::live_version( $type, $slug ) ),
			);
		}

		return array_values( $found );
	}

	/**
	 * Live plugin/theme version.
	 *
	 * @param string $type plugins|themes.
	 * @param string $slug Slug.
	 * @return string|null
	 */
	private static function live_version( string $type, string $slug ): ?string {
		if ( 'themes' === $type ) {
			$theme = wp_get_theme( $slug );
			if ( $theme->exists() ) {
				return (string) $theme->get( 'Version' );
			}
			return null;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		foreach ( $plugins as $file => $data ) {
			if ( str_starts_with( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return (string) ( $data['Version'] ?? '' );
			}
		}
		return null;
	}

	/**
	 * Parse Version: header from file contents.
	 *
	 * @param string $content File content.
	 * @return string|null
	 */
	private static function parse_version_header( string $content ): ?string {
		if ( preg_match( '/^\s*\*?\s*Version:\s*(.+)$/mi', $content, $m ) ) {
			return trim( $m[1] );
		}
		return null;
	}

	/**
	 * High-level DB presence info from archive.
	 *
	 * @param ZipArchive $zip Archive.
	 * @return array<string, mixed>
	 */
	private static function database_summary( ZipArchive $zip ): array {
		$idx = $zip->locateName( 'database.sql' );
		if ( false === $idx ) {
			return array(
				'included' => false,
				'note'     => __( 'No database dump in this backup.', 'maca-backup' ),
			);
		}
		$stat = $zip->statIndex( $idx );
		return array(
			'included' => true,
			'size'     => (int) ( $stat['size'] ?? 0 ),
			'note'     => __( 'Database dump present. Table-level diff runs during restore preview.', 'maca-backup' ),
		);
	}
}
