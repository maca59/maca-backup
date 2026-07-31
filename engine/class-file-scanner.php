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
	 * List files relative to ABSPATH.
	 *
	 * @param string               $scope   full|wp-content|uploads|plugins|themes|custom.
	 * @param array<string, mixed> $options Extra options (path, excludes).
	 * @return array<int, string> Relative paths.
	 */
	public static function list_files( string $scope = 'full', array $options = array() ): array {
		$root = self::scope_root( $scope, $options );
		if ( ! is_dir( $root ) ) {
			return array();
		}

		$excludes = $options['excludes'] ?? Maca_Backup_Pro_Settings::get( 'exclude_paths', array() );
		if ( ! is_array( $excludes ) ) {
			$excludes = array();
		}

		$excludes = array_merge( $excludes, self::always_exclude_rules( $options ) );
		$excludes = array_values( array_unique( array_filter( array_map( 'strval', $excludes ) ) ) );

		$blocked_abs = self::blocked_absolute_dirs( $options );

		$inner = new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS );
		$filter = new RecursiveCallbackFilterIterator(
			$inner,
			static function ( $current ) use ( $excludes, $blocked_abs ) {
				/** @var SplFileInfo $current */
				$path = $current->getPathname();
				if ( self::is_under_blocked_dir( $path, $blocked_abs ) ) {
					return false;
				}
				$rel = self::relative_to_abspath( $path );
				if ( null === $rel ) {
					// Outside ABSPATH — still allow traversal for custom roots, but never backup dirs.
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

			$rel = self::relative_to_abspath( $path );
			if ( null === $rel ) {
				continue;
			}

			if ( self::is_excluded( $rel, $excludes ) ) {
				continue;
			}

			$files[] = $rel;
		}

		sort( $files );
		return $files;
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
			'wp-content/cache',
			'wp-content/upgrade',
		);

		$upload = wp_upload_dir();
		if ( ! empty( $upload['basedir'] ) ) {
			$rel = self::relative_to_abspath( trailingslashit( $upload['basedir'] ) . 'maca-backups' );
			if ( $rel ) {
				$rules[] = $rel;
			}
		}

		$backup_dir = Maca_Backup_Pro_Settings::local_backup_dir();
		$rel_backup = self::relative_to_abspath( $backup_dir );
		if ( $rel_backup ) {
			$rules[] = $rel_backup;
		}

		if ( ! empty( $options['work_dir'] ) ) {
			$rel_work = self::relative_to_abspath( (string) $options['work_dir'] );
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
	 * @param string   $path         Absolute path.
	 * @param string[] $blocked_abs  Normalized lowercase absolute dirs.
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
		return (bool) preg_match( '#/(?:maca-backups)(?:/|$)#i', '/' . ltrim( str_replace( '\\', '/', $path ), '/' ) );
	}

	/**
	 * Resolve absolute root for a scope.
	 *
	 * @param string               $scope   Scope.
	 * @param array<string, mixed> $options Options.
	 * @return string
	 */
	public static function scope_root( string $scope, array $options = array() ): string {
		return match ( $scope ) {
			'wp-content' => WP_CONTENT_DIR,
			'uploads'    => (string) ( wp_upload_dir()['basedir'] ?? WP_CONTENT_DIR . '/uploads' ),
			'plugins'    => WP_PLUGIN_DIR,
			'themes'     => get_theme_root(),
			'custom'     => isset( $options['path'] ) ? (string) $options['path'] : ABSPATH,
			default      => ABSPATH,
		};
	}

	/**
	 * Path relative to ABSPATH using forward slashes.
	 *
	 * @param string $absolute Absolute path.
	 * @return string|null
	 */
	public static function relative_to_abspath( string $absolute ): ?string {
		$abs  = wp_normalize_path( $absolute );
		$base = trailingslashit( wp_normalize_path( ABSPATH ) );

		// Case-insensitive compare for Windows hosts.
		if ( 0 !== strcasecmp( substr( $abs . '/', 0, strlen( $base ) ), $base ) && 0 !== strcasecmp( $abs, rtrim( $base, '/' ) ) ) {
			return null;
		}

		$rel = ltrim( substr( $abs, strlen( rtrim( $base, '/' ) ) ), '/' );
		return str_replace( '\\', '/', $rel );
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
		foreach ( $excludes as $rule ) {
			$rule = strtolower( trim( str_replace( '\\', '/', (string) $rule ), '/' ) );
			if ( '' === $rule ) {
				continue;
			}
			if ( $rel === $rule || str_starts_with( $rel, $rule . '/' ) ) {
				return true;
			}
			// Also match bare folder name anywhere (e.g. maca-backups).
			if ( ! str_contains( $rule, '/' ) && ( $rel === $rule || str_contains( '/' . $rel . '/', '/' . $rule . '/' ) ) ) {
				return true;
			}
		}
		return false;
	}
}
