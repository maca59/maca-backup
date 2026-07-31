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
				__( 'You do not have permission to manage maca BackUp.', 'maca-backup-pro' ),
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
			wp_die( esc_html__( 'Security check failed.', 'maca-backup-pro' ), 403 );
		}

		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to manage maca BackUp.', 'maca-backup-pro' ), 403 );
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
				array( 'message' => __( 'Permission denied.', 'maca-backup-pro' ) ),
				403
			);
		}
	}
}
