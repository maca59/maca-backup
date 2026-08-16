<?php
/**
 * WordPress-aware filesystem path helpers.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves site / content paths via WP APIs (avoids hard-coded directory assumptions).
 */
class Maca_Backup_Pro_Paths {

	/**
	 * Live site root (trailing slash stripped), via get_home_path() when available.
	 *
	 * @return string
	 */
	public static function site_root(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = get_home_path();
		if ( ! is_string( $home ) || '' === $home ) {
			$home = (string) ABSPATH;
		}

		return wp_normalize_path( untrailingslashit( $home ) );
	}

	/**
	 * Uploads basedir from wp_upload_dir() (no hard-coded wp-content/uploads).
	 *
	 * @return string Absolute uploads basedir, or empty string on failure.
	 */
	public static function uploads_basedir(): string {
		$upload = wp_upload_dir( null, false );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return '';
		}

		return wp_normalize_path( untrailingslashit( (string) $upload['basedir'] ) );
	}

	/**
	 * Uploads baseurl from wp_upload_dir().
	 *
	 * @return string Uploads base URL (no trailing slash), or empty string on failure.
	 */
	public static function uploads_baseurl(): string {
		$upload = wp_upload_dir( null, false );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['baseurl'] ) ) {
			return '';
		}

		return untrailingslashit( (string) $upload['baseurl'] );
	}

	/**
	 * Default local backup directory: {uploads}/maca-backups.
	 *
	 * @return string
	 */
	public static function default_backup_dir(): string {
		$base = self::uploads_basedir();
		if ( '' === $base ) {
			return '';
		}

		return $base . '/maca-backups';
	}

	/**
	 * Whether $path is inside the uploads basedir (realpath-aware when possible).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_under_uploads( string $path ): bool {
		$uploads = self::uploads_basedir();
		if ( '' === $uploads ) {
			return false;
		}

		$path = wp_normalize_path( untrailingslashit( $path ) );
		$prefix = trailingslashit( $uploads );
		if ( str_starts_with( $path, $prefix ) || $path === $uploads ) {
			return true;
		}

		$real_path = realpath( $path );
		$real_up   = realpath( $uploads );
		if ( false === $real_path || false === $real_up ) {
			return false;
		}

		$real_path = wp_normalize_path( $real_path );
		$real_up   = wp_normalize_path( $real_up );
		return $real_path === $real_up || str_starts_with( $real_path, trailingslashit( $real_up ) );
	}

	/**
	 * Absolute directory for a scan/restore scope.
	 *
	 * @param string $scope uploads|plugins|themes|mu-plugins|wp-content|full|files|…
	 * @return string
	 */
	public static function scope_directory( string $scope ): string {
		return match ( $scope ) {
			'uploads'    => self::uploads_basedir(),
			'plugins'    => wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) ),
			'themes'     => wp_normalize_path( untrailingslashit( get_theme_root() ) ),
			'mu-plugins' => wp_normalize_path(
				untrailingslashit(
					defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ( WP_CONTENT_DIR . '/mu-plugins' )
				)
			),
			'wp-content' => wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) ),
			default      => self::site_root(),
		};
	}

	/**
	 * Resolve a backup-relative path to an absolute filesystem path using WP location APIs.
	 *
	 * @param string $relative Path as stored in archives (forward slashes, relative to site root).
	 * @return string Absolute path.
	 */
	public static function absolute( string $relative ): string {
		$rel = ltrim( str_replace( '\\', '/', $relative ), '/' );

		if ( '' === $rel ) {
			return self::site_root();
		}

		if ( 'wp-content' === $rel || str_starts_with( $rel, 'wp-content/' ) ) {
			$rest = ( 'wp-content' === $rel ) ? '' : substr( $rel, strlen( 'wp-content/' ) );

			if ( 'uploads' === $rest || str_starts_with( $rest, 'uploads/' ) ) {
				$base = self::uploads_basedir();
				$sub  = ( 'uploads' === $rest ) ? '' : substr( $rest, strlen( 'uploads/' ) );
				return '' === $sub ? $base : trailingslashit( $base ) . $sub;
			}

			if ( 'plugins' === $rest || str_starts_with( $rest, 'plugins/' ) ) {
				$base = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) );
				$sub  = ( 'plugins' === $rest ) ? '' : substr( $rest, strlen( 'plugins/' ) );
				return '' === $sub ? $base : trailingslashit( $base ) . $sub;
			}

			if ( 'themes' === $rest || str_starts_with( $rest, 'themes/' ) ) {
				$base = wp_normalize_path( untrailingslashit( get_theme_root() ) );
				$sub  = ( 'themes' === $rest ) ? '' : substr( $rest, strlen( 'themes/' ) );
				return '' === $sub ? $base : trailingslashit( $base ) . $sub;
			}

			if ( 'mu-plugins' === $rest || str_starts_with( $rest, 'mu-plugins/' ) ) {
				$base = self::scope_directory( 'mu-plugins' );
				$sub  = ( 'mu-plugins' === $rest ) ? '' : substr( $rest, strlen( 'mu-plugins/' ) );
				return '' === $sub ? $base : trailingslashit( $base ) . $sub;
			}

			$content = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
			return '' === $rest ? $content : trailingslashit( $content ) . $rest;
		}

		return trailingslashit( self::site_root() ) . $rel;
	}

	/**
	 * Convert a path to the OS-native directory separator.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function native( string $path ): string {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			return str_replace( '/', '\\', $path );
		}

		return str_replace( '\\', '/', $path );
	}

	/**
	 * Windows MAX_PATH workaround (\\?\ prefix). Other OS: unchanged.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function windows_long_path( string $path ): string {
		if ( '\\' !== DIRECTORY_SEPARATOR || '' === $path ) {
			return $path;
		}

		$path = self::native( $path );
		if ( str_starts_with( $path, '\\\\?\\' ) ) {
			return $path;
		}

		if ( str_starts_with( $path, '\\\\' ) ) {
			return '\\\\?\\UNC\\' . substr( $path, 2 );
		}

		return '\\\\?\\' . $path;
	}

	/**
	 * Whether $path is an existing readable regular file.
	 *
	 * Tries native separators and the Windows long-path prefix because
	 * is_readable() often fails on deep vendor trees (e.g. plugin-check).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_readable_file( string $path ): bool {
		return '' !== self::readable_path( $path );
	}

	/**
	 * Resolve a backup-relative path to a readable absolute filesystem path.
	 *
	 * @param string $relative Archive-relative path.
	 * @return string Absolute path (possibly \\?\ prefixed on Windows), or empty.
	 */
	public static function readable_absolute( string $relative ): string {
		return self::readable_path( self::absolute( $relative ) );
	}

	/**
	 * First candidate of $path that PHP can open as a regular file.
	 *
	 * @param string $path Absolute path.
	 * @return string Usable absolute path, or empty.
	 */
	public static function readable_path( string $path ): string {
		$path = wp_normalize_path( trim( $path ) );
		if ( '' === $path ) {
			return '';
		}

		foreach ( self::readable_candidates( $path ) as $candidate ) {
			if ( self::php_can_read_file( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Path variants to try when opening a file.
	 *
	 * @param string $path Normalized absolute path.
	 * @return string[]
	 */
	private static function readable_candidates( string $path ): array {
		$out    = array();
		$native = self::native( $path );
		foreach ( array( $path, $native ) as $base ) {
			if ( '' === $base || in_array( $base, $out, true ) ) {
				continue;
			}
			$out[] = $base;
		}

		foreach ( $out as $base ) {
			$real = realpath( $base );
			if ( is_string( $real ) && '' !== $real && ! in_array( $real, $out, true ) ) {
				$out[] = $real;
			}
		}

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$long = array();
			foreach ( $out as $base ) {
				$prefixed = self::windows_long_path( $base );
				if ( '' !== $prefixed && ! in_array( $prefixed, $out, true ) && ! in_array( $prefixed, $long, true ) ) {
					$long[] = $prefixed;
				}
			}
			$out = array_merge( $out, $long );
		}

		return $out;
	}

	/**
	 * Definitive readable-file check (is_readable() is unreliable on Windows ACLs).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function php_can_read_file( string $path ): bool {
		if ( '' === $path || ! is_file( $path ) ) {
			return false;
		}
		if ( is_readable( $path ) ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return true;
	}
}
