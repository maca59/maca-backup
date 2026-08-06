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
}
