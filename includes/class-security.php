<?php
/**
 * Security helpers.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capability and nonce helpers.
 */
class Maca_Backup_Pro_Security {

	public const CAPABILITY = 'manage_options';
	public const NONCE_ACTION = 'maca_backup_pro_admin';

	/**
	 * Whether current user can manage the plugin.
	 *
	 * @return bool
	 */
	public static function can_manage(): bool {
		return current_user_can( self::CAPABILITY );
	}

	/**
	 * Verify REST/admin permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public static function rest_permission() {
		if ( ! self::can_manage() ) {
			return new WP_Error(
				'maca_backup_pro_forbidden',
				__( 'You do not have permission to manage maca BackUp.', 'maca-backup' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verify admin nonce or die.
	 *
	 * @param string $action Nonce action.
	 * @param string $field  Request field name.
	 * @return void
	 */
	public static function verify_admin_nonce( string $action = self::NONCE_ACTION, string $field = '_wpnonce' ): void {
		if ( ! isset( $_REQUEST[ $field ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ), $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'maca-backup' ), 403 );
		}

		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage maca BackUp.', 'maca-backup' ), 403 );
		}
	}

	/**
	 * Verify AJAX nonce and capability.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	public static function verify_ajax( string $action = 'maca_backup_pro_ajax' ): void {
		check_ajax_referer( $action, 'nonce' );

		if ( ! self::can_manage() ) {
			wp_send_json_error(
				array( 'message' => __( 'Permission denied.', 'maca-backup' ) ),
				403
			);
		}
	}

	/**
	 * Normalize a ZIP entry name and reject path traversal / absolute paths.
	 *
	 * @param string $name Archive entry name.
	 * @return string|false Relative path with forward slashes, or false if unsafe.
	 */
	public static function safe_zip_entry_path( string $name ) {
		$name = str_replace( '\\', '/', $name );
		$name = ltrim( $name, '/' );

		if ( '' === $name || str_ends_with( $name, '/' ) ) {
			return false;
		}

		// Reject Windows drive / UNC style and null bytes.
		if ( preg_match( '/^[a-zA-Z]:/', $name ) || str_starts_with( $name, '//' ) || str_contains( $name, "\0" ) ) {
			return false;
		}

		$parts = array();
		foreach ( explode( '/', $name ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return false;
			}
			$parts[] = $segment;
		}

		if ( empty( $parts ) ) {
			return false;
		}

		return implode( '/', $parts );
	}

	/**
	 * Resolve a relative path under a base directory (blocks escape via realpath).
	 *
	 * @param string $base     Absolute base directory.
	 * @param string $relative Relative path (already sanitized preferred).
	 * @return string|false Absolute path under $base, or false.
	 */
	public static function path_under_directory( string $base, string $relative ) {
		$relative = self::safe_zip_entry_path( $relative );
		if ( false === $relative ) {
			return false;
		}

		$base = wp_normalize_path( untrailingslashit( $base ) );
		$dest = wp_normalize_path( $base . '/' . $relative );

		// Prefix check before the path exists (realpath may fail for new files).
		$base_prefix = trailingslashit( $base );
		if ( ! str_starts_with( $dest, $base_prefix ) && $dest !== $base ) {
			return false;
		}

		$parent = dirname( $dest );
		if ( is_dir( $parent ) ) {
			$real_parent = realpath( $parent );
			$real_base   = realpath( $base );
			if ( false === $real_parent || false === $real_base ) {
				return false;
			}
			$real_parent = wp_normalize_path( $real_parent );
			$real_base   = wp_normalize_path( $real_base );
			$prefix      = trailingslashit( $real_base );
			if ( ! str_starts_with( $real_parent, $prefix ) && $real_parent !== $real_base ) {
				return false;
			}
		}

		return $dest;
	}
}
