<?php
/**
 * Email notifications.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends backup/restore status emails (HTML + plain-text fallback).
 */
class Maca_Backup_Pro_Mailer {

	private const COLOR_NAVY  = '#050b24';
	private const COLOR_TEAL  = '#2ec4b6';
	private const COLOR_LIGHT = '#f4f7fb';
	private const COLOR_MUTED = '#5b6b7c';
	private const COLOR_FAIL  = '#f97066';

	/**
	 * Notify after backup.
	 *
	 * @param bool                 $success Whether backup succeeded.
	 * @param array<string, mixed> $data    Status payload (optional schedule_id for per-schedule prefs).
	 * @return bool
	 */
	public static function notify_backup( bool $success, array $data ): bool {
		$settings = Maca_Backup_Pro_Settings::all();
		if ( empty( $settings['email_enabled'] ) ) {
			return false;
		}

		$prefs = self::backup_email_prefs( $data, $settings );
		if ( $success && empty( $prefs['on_success'] ) ) {
			return false;
		}
		if ( ! $success && empty( $prefs['on_failure'] ) ) {
			return false;
		}

		if ( ! self::claim_notify_once( 'backup', $success, $data ) ) {
			return false;
		}

		$subject = $success
			? sprintf( '[%s] %s — maca BackUp', self::site_name(), __( 'Backup completed', 'maca-backup-pro' ) )
			: sprintf( '[%s] %s — maca BackUp', self::site_name(), __( 'Backup failed', 'maca-backup-pro' ) );

		return self::send( $subject, $success ? 'backup_ok' : 'backup_fail', $data );
	}

	/**
	 * Resolve success/failure toggles: schedule email_mode overrides Settings when set.
	 *
	 * @param array<string, mixed> $data     Notify payload.
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return array{on_success:bool,on_failure:bool}
	 */
	private static function backup_email_prefs( array $data, array $settings ): array {
		$on_success = ! empty( $settings['email_on_success'] );
		$on_failure = ! empty( $settings['email_on_failure'] );

		$schedule_id = sanitize_key( (string) ( $data['schedule_id'] ?? '' ) );
		if ( '' === $schedule_id || ! class_exists( 'Maca_Backup_Pro_Scheduler', false ) ) {
			return array(
				'on_success' => $on_success,
				'on_failure' => $on_failure,
			);
		}

		$entry = Maca_Backup_Pro_Scheduler::get_schedule( $schedule_id );
		if ( ! $entry ) {
			return array(
				'on_success' => $on_success,
				'on_failure' => $on_failure,
			);
		}

		switch ( sanitize_key( (string) ( $entry['email_mode'] ?? 'inherit' ) ) ) {
			case 'off':
				return array(
					'on_success' => false,
					'on_failure' => false,
				);
			case 'failure':
				return array(
					'on_success' => false,
					'on_failure' => true,
				);
			case 'success':
				return array(
					'on_success' => true,
					'on_failure' => false,
				);
			case 'both':
				return array(
					'on_success' => true,
					'on_failure' => true,
				);
			case 'inherit':
			default:
				return array(
					'on_success' => $on_success,
					'on_failure' => $on_failure,
				);
		}
	}

	/**
	 * Notify after restore.
	 *
	 * @param bool                 $success Whether restore succeeded.
	 * @param array<string, mixed> $data    Status payload.
	 * @return bool
	 */
	public static function notify_restore( bool $success, array $data ): bool {
		$settings = Maca_Backup_Pro_Settings::all();
		if ( empty( $settings['email_enabled'] ) ) {
			return false;
		}
		if ( $success && empty( $settings['email_on_restore_ok'] ) ) {
			return false;
		}
		if ( ! $success && empty( $settings['email_on_restore_fail'] ) ) {
			return false;
		}

		if ( ! self::claim_notify_once( 'restore', $success, $data ) ) {
			return false;
		}

		$subject = $success
			? sprintf( '[%s] %s — maca BackUp', self::site_name(), __( 'Restore completed', 'maca-backup-pro' ) )
			: sprintf( '[%s] %s — maca BackUp', self::site_name(), __( 'Restore failed', 'maca-backup-pro' ) );

		return self::send( $subject, $success ? 'restore_ok' : 'restore_fail', $data );
	}

	/**
	 * One-shot guard so concurrent finishers cannot send the same notification twice.
	 *
	 * @param string               $kind    backup|restore.
	 * @param bool                 $success Outcome.
	 * @param array<string, mixed> $data    Payload.
	 * @return bool True if this caller may send.
	 */
	private static function claim_notify_once( string $kind, bool $success, array $data ): bool {
		$job_id    = (int) ( $data['job_id'] ?? 0 );
		$backup_id = (int) ( $data['backup_id'] ?? 0 );

		// Need a stable job/backup identity; schedule-only start failures stay unguarded here.
		if ( $job_id < 1 && $backup_id < 1 ) {
			return true;
		}

		$key = 'maca_bp_mail_' . md5(
			$kind . '|' . ( $success ? 'ok' : 'fail' ) . '|' . $job_id . '|' . $backup_id
		);

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Send a test notification email (ignores event toggles; uses configured recipients).
	 *
	 * @return true|\WP_Error
	 */
	public static function send_test() {
		$settings = Maca_Backup_Pro_Settings::all();
		$raw      = (string) ( $settings['email_recipients'] ?? '' );
		$emails   = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( empty( $emails ) ) {
			$emails = array( (string) get_option( 'admin_email' ) );
		}

		$valid = array();
		foreach ( $emails as $email ) {
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}
		if ( empty( $valid ) ) {
			return new WP_Error(
				'no_recipient',
				__( 'No valid recipient email. Add one under Recipients or set the WordPress admin email.', 'maca-backup-pro' )
			);
		}

		$subject = sprintf(
			/* translators: %s: site name */
			'[%s] %s — maca BackUp',
			self::site_name(),
			__( 'Test email', 'maca-backup-pro' )
		);

		$data = array(
			'type'    => 'full',
			'storage' => (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' ),
		);

		$ok = self::send( $subject, 'test', $data );
		if ( ! $ok ) {
			return new WP_Error(
				'mail_failed',
				__( 'WordPress could not send the email. Check your site mail configuration (SMTP plugin or hosting mail).', 'maca-backup-pro' )
			);
		}

		return true;
	}

	/**
	 * Recipients that would receive notification emails.
	 *
	 * @return string[]
	 */
	public static function recipient_list(): array {
		$settings = Maca_Backup_Pro_Settings::all();
		$raw      = (string) ( $settings['email_recipients'] ?? '' );
		$emails   = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		if ( empty( $emails ) ) {
			$emails = array( (string) get_option( 'admin_email' ) );
		}

		$valid = array();
		foreach ( $emails as $email ) {
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}

		return $valid;
	}

	/**
	 * Send HTML + plain-text message to configured recipients.
	 *
	 * @param string               $subject Subject.
	 * @param string               $type    Event type key.
	 * @param array<string, mixed> $data    Payload.
	 * @return bool
	 */
	private static function send( string $subject, string $type, array $data ): bool {
		$settings = Maca_Backup_Pro_Settings::all();
		$raw      = (string) ( $settings['email_recipients'] ?? '' );
		$emails   = array_values(
			array_unique(
				array_filter(
					array_map( 'trim', explode( ',', $raw ) )
				)
			)
		);

		if ( empty( $emails ) ) {
			$emails = array( get_option( 'admin_email' ) );
		}

		$rows = self::detail_rows( $type, $data );
		$html = self::html_body( $type, $rows, $data );
		$text = self::text_body( $type, $rows, $data );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'X-Mailer: maca BackUp/' . MACA_BACKUP_PRO_VERSION,
		);

		$alt_body = static function ( $phpmailer ) use ( $text ): void {
			if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'AltBody' ) ) {
				$phpmailer->AltBody = $text;
			}
		};
		add_action( 'phpmailer_init', $alt_body );

		$ok = true;
		try {
			foreach ( $emails as $email ) {
				if ( ! is_email( $email ) ) {
					continue;
				}
				$sent = wp_mail( $email, $subject, $html, $headers );
				$ok   = $ok && $sent;
			}
		} finally {
			remove_action( 'phpmailer_init', $alt_body );
		}

		return $ok;
	}

	/**
	 * Detail rows for the email.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Payload.
	 * @return array<int, array{label:string,value:string}>
	 */
	private static function detail_rows( string $type, array $data ): array {
		$rows   = array();
		$rows[] = array(
			'label' => __( 'Website', 'maca-backup-pro' ),
			'value' => self::site_name() . ' (' . home_url( '/' ) . ')',
		);
		$rows[] = array(
			'label' => __( 'Time', 'maca-backup-pro' ),
			'value' => self::local_datetime(),
		);
		$rows[] = array(
			'label' => __( 'Event', 'maca-backup-pro' ),
			'value' => self::event_label( $type ),
		);

		$type_label = self::job_type_label( $type, $data );
		if ( '' !== $type_label ) {
			$rows[] = array(
				'label' => str_starts_with( $type, 'restore' )
					? __( 'Restore scope', 'maca-backup-pro' )
					: __( 'Backup type', 'maca-backup-pro' ),
				'value' => $type_label,
			);
		}

		if ( isset( $data['size'] ) && '' !== $data['size'] && null !== $data['size'] ) {
			$rows[] = array(
				'label' => __( 'Backup size', 'maca-backup-pro' ),
				'value' => size_format( (int) $data['size'] ),
			);
		}

		if ( ! empty( $data['storage'] ) ) {
			$rows[] = array(
				'label' => __( 'Storage', 'maca-backup-pro' ),
				'value' => self::storage_label( (string) $data['storage'] ),
			);
		}

		if ( isset( $data['duration'] ) ) {
			$rows[] = array(
				'label' => __( 'Duration', 'maca-backup-pro' ),
				'value' => Maca_Backup_Pro_Format::duration( (int) $data['duration'] ),
			);
		}

		if ( ! empty( $data['checksum'] ) ) {
			$crc = strtoupper( preg_replace( '/[^a-f0-9]/i', '', (string) $data['checksum'] ) ?? '' );
			if ( 8 === strlen( $crc ) ) {
				$rows[] = array(
					'label' => __( 'CRC32', 'maca-backup-pro' ),
					'value' => $crc,
				);
			}
		}

		if ( ! empty( $data['backup_id'] ) ) {
			$rows[] = array(
				'label' => __( 'Backup ID', 'maca-backup-pro' ),
				'value' => '#' . absint( $data['backup_id'] ),
			);
		}

		if ( ! empty( $data['error'] ) ) {
			$rows[] = array(
				'label' => __( 'Reason', 'maca-backup-pro' ),
				'value' => (string) $data['error'],
			);
		}

		$rows[] = array(
			'label' => __( 'Plugin', 'maca-backup-pro' ),
			'value' => 'maca BackUp v' . MACA_BACKUP_PRO_VERSION,
		);

		return $rows;
	}

	/**
	 * HTML email body.
	 *
	 * @param string                                       $type Event type.
	 * @param array<int, array{label:string,value:string}> $rows Detail rows.
	 * @param array<string, mixed>                         $data Payload.
	 * @return string
	 */
	private static function html_body( string $type, array $rows, array $data ): string {
		$success    = in_array( $type, array( 'backup_ok', 'restore_ok', 'test' ), true );
		$status     = esc_html( self::event_label( $type ) );
		$admin_url  = esc_url( self::admin_url( $type, $data ) );
		$cta        = esc_html__( 'Open maca BackUp', 'maca-backup-pro' );
		if ( 'test' === $type ) {
			$intro = esc_html__( 'This is a test message from maca BackUp. If you received it, email notifications are working.', 'maca-backup-pro' );
		} else {
			$intro = $success
				? esc_html__( 'Good news — the job finished successfully.', 'maca-backup-pro' )
				: esc_html__( 'Something went wrong. Details are below.', 'maca-backup-pro' );
		}
		$badge_col  = $success ? self::COLOR_TEAL : self::COLOR_FAIL;

		$navy = self::COLOR_NAVY;
		$teal = self::COLOR_TEAL;
		$bg   = self::COLOR_LIGHT;
		$mute = self::COLOR_MUTED;

		$rows_html = '';
		foreach ( $rows as $row ) {
			$rows_html .= '<tr>'
				. '<td style="padding:10px 12px;border-bottom:1px solid #e6edf5;color:' . $mute . ';font-size:13px;width:34%;vertical-align:top;">'
				. esc_html( $row['label'] )
				. '</td>'
				. '<td style="padding:10px 12px;border-bottom:1px solid #e6edf5;color:' . $navy . ';font-size:14px;font-weight:600;vertical-align:top;word-break:break-word;">'
				. esc_html( $row['value'] )
				. '</td>'
				. '</tr>';
		}

		$footer_links = self::cross_sell_links();
		$footer_html  = '';
		foreach ( $footer_links as $link ) {
			$footer_html .= '<a href="' . esc_url( $link['url'] ) . '" style="color:' . $teal . ';text-decoration:none;margin:0 8px;">'
				. esc_html( $link['label'] )
				. '</a>';
		}

		$home = esc_url( 'https://maca.se/' );

		return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:' . $bg . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $bg . ';padding:24px 12px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;border:1px solid #d9e2ec;">'
			. '<tr><td style="background:' . $navy . ';padding:22px 24px;">'
			. '<div style="font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.7);">WORDPRESS PLUGIN</div>'
			. '<div style="font-size:22px;font-weight:700;color:#ffffff;margin-top:4px;">maca <span style="color:' . $teal . ';">BackUp</span></div>'
			. '<div style="margin-top:14px;display:inline-block;background:rgba(255,255,255,0.08);color:' . $badge_col . ';border:1px solid ' . $badge_col . ';border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700;">'
			. $status
			. '</div>'
			. '</td></tr>'
			. '<tr><td style="padding:24px;">'
			. '<p style="margin:0 0 8px;font-size:16px;color:' . $navy . ';font-weight:700;">' . $status . '</p>'
			. '<p style="margin:0 0 18px;font-size:14px;line-height:1.5;color:' . $mute . ';">' . $intro . '</p>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e6edf5;border-radius:10px;overflow:hidden;">'
			. $rows_html
			. '</table>'
			. '<div style="margin-top:22px;text-align:center;">'
			. '<a href="' . $admin_url . '" style="display:inline-block;background:' . $teal . ';color:' . $navy . ';text-decoration:none;font-weight:700;font-size:14px;padding:12px 22px;border-radius:8px;">'
			. $cta
			. '</a>'
			. '</div>'
			. '</td></tr>'
			. '<tr><td style="background:#0b1224;padding:18px 20px;text-align:center;">'
			. '<div style="font-size:12px;color:rgba(255,255,255,0.7);margin-bottom:8px;">'
			. esc_html__( 'More from maca on WordPress.org', 'maca-backup-pro' )
			. '</div>'
			. '<div style="font-size:12px;line-height:1.8;">' . $footer_html . '</div>'
			. '<div style="margin-top:14px;font-size:11px;color:rgba(255,255,255,0.45);">'
			. '<a href="' . $home . '" style="color:rgba(255,255,255,0.7);text-decoration:none;">maca.se</a>'
			. '</div>'
			. '</td></tr>'
			. '</table>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	/**
	 * Plain-text fallback body.
	 *
	 * @param string                                       $type Event type.
	 * @param array<int, array{label:string,value:string}> $rows Detail rows.
	 * @param array<string, mixed>                         $data Payload.
	 * @return string
	 */
	private static function text_body( string $type, array $rows, array $data ): string {
		$lines   = array();
		$lines[] = 'maca BackUp';
		$lines[] = self::event_label( $type );
		$lines[] = str_repeat( '-', 40 );
		foreach ( $rows as $row ) {
			$lines[] = $row['label'] . ': ' . $row['value'];
		}
		$lines[] = '';
		$lines[] = __( 'Open maca BackUp', 'maca-backup-pro' ) . ': ' . self::admin_url( $type, $data );
		$lines[] = '';
		$lines[] = __( 'More from maca on WordPress.org', 'maca-backup-pro' ) . ':';
		foreach ( self::cross_sell_links() as $link ) {
			$lines[] = '- ' . $link['label'] . ': ' . $link['url'];
		}
		$lines[] = 'maca.se: https://maca.se/';

		return implode( "\n", $lines );
	}

	/**
	 * Admin deep-link for the CTA.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Payload.
	 * @return string
	 */
	private static function admin_url( string $type, array $data ): string {
		$tab = str_starts_with( $type, 'restore' ) ? 'restore' : 'backups';
		$args = array();
		if ( ! empty( $data['backup_id'] ) ) {
			$args['backup_id'] = absint( $data['backup_id'] );
		}
		if ( class_exists( 'Maca_Backup_Pro_Admin', false ) ) {
			return Maca_Backup_Pro_Admin::tab_url( $tab, $args );
		}
		return add_query_arg(
			array_merge(
				array(
					'page' => 'maca-backup',
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * WordPress.org plugin links for the email footer (no maca.se product upsell).
	 *
	 * @return array<int, array{label:string,url:string}>
	 */
	private static function cross_sell_links(): array {
		$links = array(
			array(
				'label' => 'maca Tjatt',
				'url'   => 'https://wordpress.org/plugins/maca-tjatt/',
			),
			array(
				'label' => 'maca Njuvs',
				'url'   => 'https://wordpress.org/plugins/maca-njuvs/',
			),
			array(
				'label' => 'maca DownList',
				'url'   => 'https://wordpress.org/plugins/maca-downlist/',
			),
			array(
				'label' => 'maca Restu',
				'url'   => 'https://wordpress.org/plugins/maca-restu/',
			),
		);

		/**
		 * Filter maca BackUp notification email footer links.
		 *
		 * @param array<int, array{label:string,url:string}> $links Footer links.
		 */
		$filtered = apply_filters( 'maca_backup_email_cross_sell_links', $links );
		return is_array( $filtered ) ? $filtered : $links;
	}

	/**
	 * Human label for event type keys.
	 *
	 * @param string $type Event key.
	 * @return string
	 */
	private static function event_label( string $type ): string {
		return match ( $type ) {
			'backup_ok'    => __( 'Backup completed', 'maca-backup-pro' ),
			'backup_fail'  => __( 'Backup failed', 'maca-backup-pro' ),
			'restore_ok'   => __( 'Restore completed', 'maca-backup-pro' ),
			'restore_fail' => __( 'Restore failed', 'maca-backup-pro' ),
			'test'         => __( 'Test email', 'maca-backup-pro' ),
			default        => $type,
		};
	}

	/**
	 * Backup/restore type label from payload.
	 *
	 * @param string               $type Event type.
	 * @param array<string, mixed> $data Payload.
	 * @return string
	 */
	private static function job_type_label( string $type, array $data ): string {
		$key = '';
		if ( str_starts_with( $type, 'restore' ) ) {
			$key = sanitize_key( (string) ( $data['scope'] ?? $data['type'] ?? '' ) );
		} else {
			$key = sanitize_key( (string) ( $data['type'] ?? '' ) );
		}
		if ( '' === $key ) {
			return '';
		}

		$labels = array(
			'full'     => __( 'Full site', 'maca-backup-pro' ),
			'database' => __( 'Database', 'maca-backup-pro' ),
			'files'    => __( 'Files', 'maca-backup-pro' ),
			'path'     => __( 'Selected paths', 'maca-backup-pro' ),
			'smart'    => __( 'Smart Restore', 'maca-backup-pro' ),
		);

		return $labels[ $key ] ?? $key;
	}

	/**
	 * Resolve storage provider label.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private static function storage_label( string $provider ): string {
		$provider = sanitize_key( $provider );
		if ( class_exists( 'Maca_Backup_Pro_Storage_Registry', false ) ) {
			$obj = Maca_Backup_Pro_Storage_Registry::instance()->get( $provider );
			if ( $obj && method_exists( $obj, 'label' ) ) {
				return (string) $obj->label();
			}
		}
		return $provider;
	}

	/**
	 * Local site datetime string.
	 *
	 * @return string
	 */
	private static function local_datetime(): string {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		return wp_date( $format ) . ' (' . wp_timezone_string() . ')';
	}

	/**
	 * Site label for subjects.
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
