<?php
/**
 * Scans WordPress files for backup inclusion.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recursive file listing with excludes.
 */
class Maca_Backup_Pro_File_Scanner {

	/**
	 * List files relative to the site (logical WP paths).
	 *
	 * @param string               $scope   full|wp-content|uploads|plugins|themes|custom.
	 * @param array<string, mixed> $options Extra options (path, excludes, complete).
	 * @return array<int, string> Relative paths.
	 */
	public static function list_files( string $scope = 'full', array $options = array() ): array {
		$scope = sanitize_key( $scope );
		if ( '' === $scope ) {
			$scope = 'full';
		}

		// Full-site backups must cover every WP root (home, ABSPATH, content, uploads…),
		// not only get_home_path() — otherwise migration/import restores are incomplete.
		if ( in_array( $scope, array( 'full', 'files' ), true ) ) {
			$files = array();
			foreach ( self::full_scan_roots() as $root ) {
				$files = array_merge( $files, self::scan_tree( $root, $options ) );
			}
			$files = array_values( array_unique( $files ) );
			sort( $files );
			return $files;
		}

		$root = self::scope_root( $scope, $options );
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$files = self::scan_tree( $root, $options );
		sort( $files );
		return $files;
	}

	/**
	 * Absolute roots that together make a complete site tree.
	 *
	 * @return string[]
	 */
	public static function full_scan_roots(): array {
		$roots = array(
			Maca_Backup_Pro_Paths::site_root(),
			wp_normalize_path( untrailingslashit( (string) ABSPATH ) ),
			wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ),
			wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) ),
			wp_normalize_path( untrailingslashit( get_theme_root() ) ),
			Maca_Backup_Pro_Paths::scope_directory( 'mu-plugins' ),
			Maca_Backup_Pro_Paths::uploads_basedir(),
		);

		$out = array();
		foreach ( $roots as $root ) {
			$root = wp_normalize_path( untrailingslashit( (string) $root ) );
			if ( '' === $root || ! is_dir( $root ) ) {
				continue;
			}
			// Skip a root that is already covered by a parent we scan.
			$covered = false;
			foreach ( $out as $existing ) {
				if ( $root === $existing || str_starts_with( $root . '/', $existing . '/' ) ) {
					$covered = true;
					break;
				}
			}
			if ( $covered ) {
				continue;
			}
			// Drop existing entries that are children of this new root.
			$out = array_values(
				array_filter(
					$out,
					static function ( string $existing ) use ( $root ): bool {
						return ! str_starts_with( $existing . '/', $root . '/' );
					}
				)
			);
			$out[] = $root;
		}

		return $out;
	}

	/**
	 * Scan one absolute directory tree into logical backup-relative paths.
	 *
	 * @param string               $root    Absolute directory.
	 * @param array<string, mixed> $options Options.
	 * @return string[]
	 */
	private static function scan_tree( string $root, array $options ): array {
		$root = wp_normalize_path( untrailingslashit( $root ) );
		if ( '' === $root || ! is_dir( $root ) ) {
			return array();
		}

		$complete = ! empty( $options['complete'] );
		$excludes = array();
		if ( ! $complete ) {
			$excludes = $options['excludes'] ?? Maca_Backup_Pro_Settings::get( 'exclude_paths', array() );
			if ( ! is_array( $excludes ) ) {
				$excludes = array();
			}
		}
		$excludes = array_merge( $excludes, self::always_exclude_rules( $options ) );
		$excludes = self::sanitize_exclude_rules( $excludes );
		$excludes = array_values( array_unique( array_filter( array_map( 'strval', $excludes ) ) ) );

		$blocked_abs = self::blocked_absolute_dirs( $options );

		$inner  = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
		$filter = new RecursiveCallbackFilterIterator(
			$inner,
			static function ( $current ) use ( $excludes, $blocked_abs ) {
				/** @var SplFileInfo $current */
				$path = $current->getPathname();
				if ( self::is_under_blocked_dir( $path, $blocked_abs ) ) {
					return false;
				}
				$rel = self::logical_relative( $path );
				if ( null === $rel ) {
					return ! self::path_has_backup_segment( wp_normalize_path( $path ) );
				}
				if ( $current->isDir() && self::is_excluded( $rel, $excludes ) ) {
					return false;
				}
				return true;
			}
		);

		$files    = array();
		$iterator = new RecursiveIteratorIterator( $filter, RecursiveIteratorIterator::SELF_FIRST );

		foreach ( $iterator as $fileinfo ) {
			/** @var SplFileInfo $fileinfo */
			if ( ! $fileinfo->isFile() ) {
				continue;
			}

			$path = $fileinfo->getPathname();
			if ( self::is_under_blocked_dir( $path, $blocked_abs ) ) {
				continue;
			}

			if ( ! $fileinfo->isReadable() && ! Maca_Backup_Pro_Paths::is_readable_file( $path ) ) {
				continue;
			}

			$rel = self::logical_relative( $path );
			if ( null === $rel ) {
				continue;
			}

			if ( self::is_excluded( $rel, $excludes ) ) {
				continue;
			}

			$files[] = $rel;
		}

		return $files;
	}

	/**
	 * Map an absolute path to the logical archive path (wp-content/… etc.).
	 *
	 * @param string $absolute Absolute filesystem path.
	 * @return string|null
	 */
	public static function logical_relative( string $absolute ): ?string {
		$abs = wp_normalize_path( $absolute );

		$map = array(
			array( Maca_Backup_Pro_Paths::uploads_basedir(), 'wp-content/uploads' ),
			array( wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) ), 'wp-content/plugins' ),
			array( wp_normalize_path( untrailingslashit( get_theme_root() ) ), 'wp-content/themes' ),
			array( Maca_Backup_Pro_Paths::scope_directory( 'mu-plugins' ), 'wp-content/mu-plugins' ),
			array( wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ), 'wp-content' ),
		);

		foreach ( $map as $pair ) {
			$base = untrailingslashit( (string) $pair[0] );
			$pref = (string) $pair[1];
			if ( '' === $base ) {
				continue;
			}
			if ( 0 === strcasecmp( $abs, $base ) ) {
				return $pref;
			}
			$prefix = trailingslashit( $base );
			if ( 0 === strcasecmp( substr( $abs . '/', 0, strlen( $prefix ) ), $prefix ) ) {
				$rest = ltrim( substr( $abs, strlen( rtrim( $base, '/' ) ) ), '/' );
				return '' === $rest ? $pref : $pref . '/' . str_replace( '\\', '/', $rest );
			}
		}

		return self::relative_to_abspath( $abs );
	}

	/**
	 * Built-in exclude rules that must never be removed by settings.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return string[]
	 */
	private static function always_exclude_rules( array $options = array() ): array {
		$rules = array(
			'wp-content/maca-backups',
			'wp-content/uploads/maca-backups',
			'wp-content/uploads/maca-backup-dl',
			'wp-content/cache',
			'wp-content/upgrade',
		);

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$rel = self::logical_relative( trailingslashit( $upload['basedir'] ) . 'maca-backups' );
			if ( $rel ) {
				$rules[] = $rel;
			}
			$rel_dl = self::logical_relative( trailingslashit( $upload['basedir'] ) . 'maca-backup-dl' );
			if ( $rel_dl ) {
				$rules[] = $rel_dl;
			}
		}

		$backup_dir = Maca_Backup_Pro_Settings::local_backup_dir();
		$rel_backup = self::logical_relative( $backup_dir );
		if ( $rel_backup ) {
			$rules[] = $rel_backup;
		}

		if ( ! empty( $options['work_dir'] ) ) {
			$rel_work = self::logical_relative( (string) $options['work_dir'] );
			if ( $rel_work ) {
				$rules[] = $rel_work;
			}
		}

		return $rules;
	}

	/**
	 * Absolute directories that must never be scanned (realpath when possible).
	 *
	 * @param array<string, mixed> $options Options.
	 * @return string[]
	 */
	private static function blocked_absolute_dirs( array $options = array() ): array {
		$dirs = array(
			Maca_Backup_Pro_Settings::local_backup_dir(),
		);

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$dirs[] = trailingslashit( $upload['basedir'] ) . 'maca-backups';
			$dirs[] = trailingslashit( $upload['basedir'] ) . 'maca-backup-dl';
		}

		if ( ! empty( $options['work_dir'] ) ) {
			$dirs[] = (string) $options['work_dir'];
		}

		$out = array();
		foreach ( $dirs as $dir ) {
			$dir = wp_normalize_path( untrailingslashit( (string) $dir ) );
			if ( '' === $dir ) {
				continue;
			}
			$real = realpath( $dir );
			$out[] = strtolower( $real ? wp_normalize_path( $real ) : $dir );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether path is inside a blocked absolute directory.
	 *
	 * @param string   $path        Absolute path.
	 * @param string[] $blocked_abs Normalized lowercase absolute dirs.
	 * @return bool
	 */
	private static function is_under_blocked_dir( string $path, array $blocked_abs ): bool {
		$norm = strtolower( wp_normalize_path( $path ) );
		$real = realpath( $path );
		if ( $real ) {
			$norm = strtolower( wp_normalize_path( $real ) );
		}

		if ( self::path_has_backup_segment( $norm ) ) {
			return true;
		}

		foreach ( $blocked_abs as $dir ) {
			if ( $norm === $dir || str_starts_with( $norm, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when path contains a maca-backups directory segment.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	private static function path_has_backup_segment( string $path ): bool {
		return (bool) preg_match( '#/(?:maca-backups|maca-backup-dl)(?:/|$)#i', '/' . ltrim( str_replace( '\\', '/', $path ), '/' ) );
	}

	/**
	 * Resolve absolute root for a scope.
	 *
	 * @param string               $scope   Scope.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function scope_root( string $scope, array $options = array() ): string {
		if ( 'custom' === $scope && ! empty( $options['path'] ) ) {
			$custom = wp_normalize_path( (string) $options['path'] );
			$site = trailingslashit( Maca_Backup_Pro_Paths::site_root() );
			if ( str_starts_with( $custom, $site ) || Maca_Backup_Pro_Paths::is_under_uploads( $custom ) ) {
				return untrailingslashit( $custom );
			}
			return Maca_Backup_Pro_Paths::site_root();
		}

		$map = array(
			'wp-content' => 'wp-content',
			'uploads'    => 'uploads',
			'plugins'    => 'plugins',
			'themes'     => 'themes',
			'mu-plugins' => 'mu-plugins',
		);
		$key = $map[ $scope ] ?? 'full';
		return Maca_Backup_Pro_Paths::scope_directory( $key );
	}

	/**
	 * Path relative to the site root using forward slashes.
	 *
	 * @param string $absolute Absolute path.
	 * @return string|null
	 */
	public static function relative_to_abspath( string $absolute ): ?string {
		$abs  = wp_normalize_path( $absolute );
		$base = trailingslashit( Maca_Backup_Pro_Paths::site_root() );

		// Case-insensitive compare for Windows hosts.
		if ( 0 !== strcasecmp( substr( $abs . '/', 0, strlen( $base ) ), $base ) && 0 !== strcasecmp( $abs, rtrim( $base, '/' ) ) ) {
			// Also accept ABSPATH when it differs from home path.
			$absp = trailingslashit( wp_normalize_path( untrailingslashit( (string) ABSPATH ) ) );
			if ( 0 === strcasecmp( substr( $abs . '/', 0, strlen( $absp ) ), $absp ) || 0 === strcasecmp( $abs, rtrim( $absp, '/' ) ) ) {
				$rel = ltrim( substr( $abs, strlen( rtrim( $absp, '/' ) ) ), '/' );
				return str_replace( '\\', '/', $rel );
			}
			return null;
		}

		$rel = ltrim( substr( $abs, strlen( rtrim( $base, '/' ) ) ), '/' );
		return str_replace( '\\', '/', $rel );
	}

	/**
	 * Drop exclude rules that would strip the Media Library (uploads) from backups.
	 *
	 * @param string[] $excludes Raw exclude rules.
	 * @return string[]
	 */
	private static function sanitize_exclude_rules( array $excludes ): array {
		$out = array();
		foreach ( $excludes as $rule ) {
			$norm = strtolower( trim( str_replace( '\\', '/', (string) $rule ), '/' ) );
			if ( '' === $norm ) {
				continue;
			}
			// Never exclude the whole uploads tree — media must travel with full/site backups.
			if ( in_array( $norm, array( 'uploads', 'wp-content/uploads', 'media' ), true ) ) {
				continue;
			}
			if ( str_starts_with( $norm, 'wp-content/uploads/' ) ) {
				// Allow only plugin backup staging folders under uploads.
				if ( ! preg_match( '#^wp-content/uploads/(?:maca-backups|maca-backup-dl)(?:/|$)#', $norm ) ) {
					continue;
				}
			}
			$out[] = (string) $rule;
		}
		return $out;
	}

	/**
	 * Whether a relative path matches an exclude rule.
	 *
	 * @param string   $rel      Relative path.
	 * @param string[] $excludes Exclude prefixes/paths.
	 * @return bool
	 */
	private static function is_excluded( string $rel, array $excludes ): bool {
		$rel = strtolower( str_replace( '\\', '/', $rel ) );

		// Media Library files are never excluded (only maca backup staging under uploads).
		if ( self::is_media_library_path( $rel ) ) {
			return false;
		}

		foreach ( $excludes as $rule ) {
			$rule = strtolower( trim( str_replace( '\\', '/', (string) $rule ), '/' ) );
			if ( '' === $rule ) {
				continue;
			}
			if ( $rel === $rule || str_starts_with( $rel, $rule . '/' ) ) {
				return true;
			}
			// Bare folder names only for known cache/backup dirs — not "uploads"/"themes".
			if ( ! str_contains( $rule, '/' ) && in_array( $rule, array( 'maca-backups', 'maca-backup-dl', 'cache', 'upgrade' ), true ) ) {
				if ( $rel === $rule || str_contains( '/' . $rel . '/', '/' . $rule . '/' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * True for Media Library paths that must always be backed up.
	 *
	 * @param string $rel Relative path (any case).
	 * @return bool
	 */
	private static function is_media_library_path( string $rel ): bool {
		$rel = strtolower( str_replace( '\\', '/', $rel ) );
		if ( ! str_starts_with( $rel, 'wp-content/uploads/' ) && 'wp-content/uploads' !== $rel ) {
			return false;
		}
		// Staging folders created by this plugin are not media.
		if ( preg_match( '#^wp-content/uploads/(?:maca-backups|maca-backup-dl)(?:/|$)#', $rel ) ) {
			return false;
		}
		return true;
	}
}
