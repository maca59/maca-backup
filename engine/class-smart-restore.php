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
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup-pro' ) );
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
			$abs             = trailingslashit( ABSPATH ) . $rel;
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
		$scan_scopes = array( 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads', 'wp-content/mu-plugins' );
		foreach ( $scan_scopes as $prefix ) {
			$root = trailingslashit( ABSPATH ) . $prefix;
			if ( ! is_dir( $root ) ) {
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
			return new WP_Error( 'empty', __( 'Select files or the database to restore.', 'maca-backup-pro' ) );
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
				'note'     => __( 'No database dump in this backup.', 'maca-backup-pro' ),
			);
		}
		$stat = $zip->statIndex( $idx );
		return array(
			'included' => true,
			'size'     => (int) ( $stat['size'] ?? 0 ),
			'note'     => __( 'Database dump present. Table-level diff runs during restore preview.', 'maca-backup-pro' ),
		);
	}
}
