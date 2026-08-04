<?php
/**
 * Deactivation feedback (Plugins screen) and api.maca.se reporting.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

require_once MACA_BACKUP_PRO_PATH . 'includes/maca-api.php';

/**
 * Allowed deactivation feedback reason keys.
 *
 * @return array<string, string>
 */
function maca_backup_pro_deactivation_feedback_reasons() {
	return array(
		'missing_feature' => __( 'Saknar en funktion jag behöver', 'maca-backup' ),
		'hard_to_use'     => __( 'För svårt att konfigurera eller använda', 'maca-backup' ),
		'bug'             => __( 'Något fungerar inte som det ska', 'maca-backup' ),
		'too_slow'        => __( 'Backup tar för lång tid / timeout', 'maca-backup' ),
		'storage'         => __( 'Problem med lagring eller molnkoppling', 'maca-backup' ),
		'switching'       => __( 'Byter till ett annat backupplugin', 'maca-backup' ),
		'temporary'       => __( 'Behövde bara tillfälligt / testar', 'maca-backup' ),
		'other'           => __( 'Annat', 'maca-backup' ),
	);
}

/**
 * English reason labels for API telemetry.
 *
 * @return array<string, string>
 */
function maca_backup_pro_deactivation_feedback_api_labels() {
	return array(
		'missing_feature' => 'Missing a feature I need',
		'hard_to_use'     => 'Too difficult to set up or use',
		'bug'             => 'Something is broken or not working',
		'too_slow'        => 'Backup too slow / timeouts',
		'storage'         => 'Storage or cloud connection problems',
		'switching'       => 'Switching to another backup plugin',
		'temporary'       => 'Only needed temporarily / testing',
		'other'           => 'Other',
	);
}

/**
 * Handle AJAX deactivation report before redirect to deactivate URL.
 *
 * @return void
 */
function maca_backup_pro_handle_deactivation_feedback() {
	check_ajax_referer( 'maca_backup_pro_deactivation_feedback', 'nonce' );

	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}

	$reasons = maca_backup_pro_deactivation_feedback_api_labels();
	$reason  = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
	$details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';
	$extra   = array();

	if ( '' !== $reason && isset( $reasons[ $reason ] ) ) {
		$extra['reason']       = $reason;
		$extra['reason_label'] = $reasons[ $reason ];
		if ( '' !== $details ) {
			$extra['details'] = $details;
		}
	}

	if ( maca_backup_pro_api_send_event( 'deactivated', $extra, true ) ) {
		maca_backup_pro_api_mark_deactivated_reported();
	}

	wp_send_json_success();
}

add_action( 'wp_ajax_maca_backup_pro_deactivation_feedback', 'maca_backup_pro_handle_deactivation_feedback' );
