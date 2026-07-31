<?php
/**
 * Support ticket client (maca.se Fluent Support via REST API).
 *
 * Mirrors maca DownList Pro / maca Restu Pro: POST JSON to
 * https://maca.se/wp-json/maca-backup/v1/support, with e-mail fallback.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remote support ticket submission for maca BackUp.
 */
class Maca_Backup_Pro_Support {

	/**
	 * Support API URL on maca.se (Fluent Support ticket intake).
	 *
	 * @return string
	 */
	public static function get_api_url(): string {
		return untrailingslashit(
			(string) apply_filters(
				'maca_backup_support_api_url',
				'https://maca.se/wp-json/maca-backup/v1/support'
			)
		);
	}

	/**
	 * Optional shared secret (must match server-side support secret on maca.se).
	 *
	 * @return string
	 */
	public static function get_shared_secret(): string {
		if ( defined( 'MACA_BACKUP_SUPPORT_SECRET' ) ) {
			return (string) MACA_BACKUP_SUPPORT_SECRET;
		}

		return (string) apply_filters( 'maca_backup_support_secret', '' );
	}

	/**
	 * Build diagnostic footer appended to ticket messages.
	 *
	 * @return string
	 */
	public static function build_system_info_block(): string {
		global $wp_version;

		$lines = array(
			'---',
			'Site: ' . untrailingslashit( home_url() ),
			'Site title: ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'Plugin: maca BackUp ' . ( defined( 'MACA_BACKUP_PRO_VERSION' ) ? MACA_BACKUP_PRO_VERSION : '' ),
			'WordPress: ' . (string) $wp_version,
			'PHP: ' . PHP_VERSION,
			'Locale: ' . get_locale(),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Submit a support ticket to maca.se.
	 *
	 * @param array<string, string> $args subject, message, email, name.
	 * @return true|WP_Error
	 */
	public static function submit_ticket( array $args ) {
		$subject = isset( $args['subject'] ) ? sanitize_text_field( $args['subject'] ) : '';
		$message = isset( $args['message'] ) ? sanitize_textarea_field( $args['message'] ) : '';
		$email   = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$name    = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';

		if ( '' === $subject || '' === $message || '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Subject, message, and a valid email are required.', 'maca-backup-pro' )
			);
		}

		$body = array(
			'subject'  => $subject,
			'message'  => $message,
			'email'    => $email,
			'name'     => $name,
			'site_url' => untrailingslashit( home_url() ),
			'product'  => 'maca-backup',
			'form_id'  => (int) Maca_Backup_Pro_Legal::FLUENT_FORM_ID,
		);

		$secret = self::get_shared_secret();
		if ( '' !== $secret ) {
			$body['support_secret'] = $secret;
		}

		$response = wp_remote_post(
			self::get_api_url(),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::submit_ticket_via_email( $args );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		// Endpoint not deployed yet, or server error — fall back to e-mail.
		if ( 404 === $code || $code >= 500 ) {
			$email_result = self::submit_ticket_via_email( $args );
			if ( ! is_wp_error( $email_result ) ) {
				return true;
			}
		}

		$message_text = is_array( $data ) && ! empty( $data['message'] )
			? (string) $data['message']
			: __( 'Support request failed. Please try again or email support@maca.se.', 'maca-backup-pro' );

		return new WP_Error( 'support_failed', $message_text, array( 'status' => $code ) );
	}

	/**
	 * Send support request directly by e-mail (fallback when maca.se API is unavailable).
	 *
	 * @param array<string, string> $args subject, message, email, name.
	 * @return true|WP_Error
	 */
	public static function submit_ticket_via_email( array $args ) {
		$subject  = isset( $args['subject'] ) ? sanitize_text_field( $args['subject'] ) : '';
		$message  = isset( $args['message'] ) ? sanitize_textarea_field( $args['message'] ) : '';
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		$name     = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		$site_url = untrailingslashit( home_url() );

		if ( '' === $subject || '' === $message || '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'invalid_input',
				__( 'Subject, message, and a valid email are required.', 'maca-backup-pro' )
			);
		}

		$to = sanitize_email(
			(string) apply_filters( 'maca_backup_support_fallback_email', Maca_Backup_Pro_Legal::SUPPORT_EMAIL )
		);
		if ( ! is_email( $to ) ) {
			$to = 'support@maca.se';
		}

		$lines = array(
			__( 'New support request from maca BackUp', 'maca-backup-pro' ),
			'',
			sprintf(
				/* translators: 1: sender name, 2: sender email */
				__( 'From: %1$s <%2$s>', 'maca-backup-pro' ),
				'' !== $name ? $name : $email,
				$email
			),
			sprintf(
				/* translators: %s: site URL */
				__( 'Site: %s', 'maca-backup-pro' ),
				$site_url
			),
			'',
			__( 'Message:', 'maca-backup-pro' ),
			$message,
			'',
			'---',
			sprintf(
				/* translators: %s: date and time */
				__( 'Sent via maca BackUp support form at %s', 'maca-backup-pro' ),
				wp_date( 'Y-m-d H:i:s' )
			),
		);

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'Reply-To: ' . ( '' !== $name ? sprintf( '%1$s <%2$s>', $name, $email ) : $email ),
		);

		if ( ! wp_mail( $to, '[maca BackUp] ' . $subject, implode( "\n", $lines ), $headers ) ) {
			return new WP_Error(
				'email_failed',
				__( 'Could not send support e-mail. Please contact support@maca.se directly.', 'maca-backup-pro' )
			);
		}

		return true;
	}
}
