<?php
/**
 * Outbound telemetry to api.maca.se (maca BackUp).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

define( 'MACA_BACKUP_PRO_API_EVENTS_URL', 'https://api.maca.se/v1/backup-pro/events.php' );
define( 'MACA_BACKUP_PRO_API_PLUGIN_SLUG', 'maca-backup' );
define( 'MACA_BACKUP_PRO_API_REPORTED_TRANSIENT', 'maca_backup_pro_api_deactivated_reported' );
define( 'MACA_BACKUP_PRO_API_PENDING_OPTION', 'maca_backup_pro_pending_telemetry' );
define( 'MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK', 'maca_backup_pro_flush_telemetry' );

/**
 * Whether outbound hub/telemetry to api.maca.se is allowed (opt-in).
 *
 * Default off for wordpress.org compliance. Enable under Settings → maca Hub.
 *
 * @return bool
 */
function maca_backup_pro_api_is_enabled() {
	if ( class_exists( 'Maca_Backup_Pro_Settings', false ) ) {
		return (bool) Maca_Backup_Pro_Settings::get( 'hub_enabled', false );
	}

	$option_key = defined( 'MACA_BACKUP_PRO_OPTION_KEY' ) ? MACA_BACKUP_PRO_OPTION_KEY : 'maca_backup_pro_settings';
	$settings   = get_option( $option_key, array() );

	return is_array( $settings ) && ! empty( $settings['hub_enabled'] );
}

/**
 * Plugin version for API payloads.
 *
 * @return string
 */
function maca_backup_pro_api_plugin_version() {
	if ( defined( 'MACA_BACKUP_PRO_VERSION' ) ) {
		return MACA_BACKUP_PRO_VERSION;
	}

	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugin_file = defined( 'MACA_BACKUP_PRO_FILE' )
		? MACA_BACKUP_PRO_FILE
		: ( defined( 'MACA_BACKUP_PRO_PATH' ) ? MACA_BACKUP_PRO_PATH . 'maca-backup-pro.php' : '' );

	if ( '' === $plugin_file || ! is_readable( $plugin_file ) ) {
		return '';
	}

	$data = get_plugin_data( $plugin_file, false, false );

	return isset( $data['Version'] ) ? (string) $data['Version'] : '';
}

/**
 * Normalize a site URL for telemetry (aligned with api.maca.se).
 *
 * @param string $url Raw URL.
 * @return string
 */
function maca_backup_pro_api_normalize_site_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}

	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = 'https://' . ltrim( $url, '/' );
	}

	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}

	$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}

	$host = strtolower( (string) $parts['host'] );
	$path = (string) ( $parts['path'] ?? '/' );
	if ( '' === $path ) {
		$path = '/';
	} elseif ( '/' !== $path ) {
		$path = untrailingslashit( $path );
	}

	return $scheme . '://' . $host . $path;
}

/**
 * @param string $host Hostname or IP.
 * @return string
 */
function maca_backup_pro_api_parse_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( '' === $host ) {
		return '';
	}

	if ( strpos( $host, ':' ) !== false ) {
		$host = (string) strtok( $host, ':' );
	}

	return $host;
}

/**
 * @param string $host Hostname or IP.
 * @return bool
 */
function maca_backup_pro_api_is_loopback_host( $host ) {
	$host = maca_backup_pro_api_parse_host( $host );
	if ( '' === $host ) {
		return false;
	}

	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0:0:0:0:0:0:0:1', '0.0.0.0' ), true ) ) {
		return true;
	}

	if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		return strpos( $host, '127.' ) === 0;
	}

	return false;
}

/**
 * @param string $host Hostname or IP.
 * @return bool
 */
function maca_backup_pro_api_is_private_host( $host ) {
	$host = maca_backup_pro_api_parse_host( $host );
	if ( '' === $host || maca_backup_pro_api_is_loopback_host( $host ) ) {
		return false;
	}

	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false;
	}

	return (bool) preg_match( '/\.(local|test|localhost)$/', $host );
}

/**
 * @param string $host Hostname.
 * @return bool
 */
function maca_backup_pro_api_is_valid_hostname( $host ) {
	$host = maca_backup_pro_api_parse_host( $host );

	return '' !== $host
		&& strlen( $host ) <= 253
		&& (bool) preg_match( '/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $host );
}

/**
 * Detect request scheme, including common reverse-proxy headers.
 *
 * @return string http|https
 */
function maca_backup_pro_api_detect_request_scheme() {
	if ( function_exists( 'is_ssl' ) && is_ssl() ) {
		return 'https';
	}

	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
		$forwarded = strtolower( trim( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) ) );
		if ( strpos( $forwarded, ',' ) !== false ) {
			$forwarded = trim( (string) strtok( $forwarded, ',' ) );
		}
		if ( 'https' === $forwarded ) {
			return 'https';
		}
	}

	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) ) ) {
		return 'https';
	}

	if ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTPS'] ) ) ) ) {
		return 'https';
	}

	if ( ! empty( $_SERVER['SERVER_PORT'] ) && '443' === sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_PORT'] ) ) ) {
		return 'https';
	}

	return 'http';
}

/**
 * Collect candidate public site URLs from WordPress and the current request.
 *
 * @return array<int, string>
 */
function maca_backup_pro_api_site_url_candidates() {
	$candidates = array();

	if ( function_exists( 'home_url' ) ) {
		$candidates[] = home_url( '/' );
	}
	if ( function_exists( 'site_url' ) ) {
		$candidates[] = site_url( '/' );
	}

	$home = get_option( 'home' );
	if ( is_string( $home ) && '' !== $home ) {
		$candidates[] = trailingslashit( $home );
	}

	$siteurl = get_option( 'siteurl' );
	if ( is_string( $siteurl ) && '' !== $siteurl ) {
		$candidates[] = trailingslashit( $siteurl );
	}

	$request_hosts = array();
	foreach ( array( 'HTTP_X_FORWARDED_HOST', 'HTTP_HOST', 'SERVER_NAME' ) as $server_key ) {
		if ( empty( $_SERVER[ $server_key ] ) ) {
			continue;
		}

		$raw = sanitize_text_field( wp_unslash( (string) $_SERVER[ $server_key ] ) );
		foreach ( explode( ',', $raw ) as $part ) {
			$host = maca_backup_pro_api_parse_host( $part );
			if ( '' !== $host ) {
				$request_hosts[] = $host;
			}
		}
	}

	$request_hosts = array_values( array_unique( $request_hosts ) );
	$scheme        = maca_backup_pro_api_detect_request_scheme();

	foreach ( $request_hosts as $host ) {
		if ( ! maca_backup_pro_api_is_valid_hostname( $host ) ) {
			continue;
		}

		$candidates[] = $scheme . '://' . $host . '/';
		if ( 'http' === $scheme ) {
			$candidates[] = 'https://' . $host . '/';
		}
	}

	/**
	 * Filter candidate site URLs before resolution.
	 *
	 * @param array<int, string> $candidates Candidate URLs.
	 */
	return apply_filters( 'maca_backup_pro_api_site_url_candidates', $candidates );
}

/**
 * Score a candidate URL â€” higher is better.
 *
 * @param string $url Normalized URL.
 * @return int
 */
function maca_backup_pro_api_score_site_url( $url ) {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return PHP_INT_MIN;
	}

	$host   = maca_backup_pro_api_parse_host( (string) $parts['host'] );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
	$score  = 0;

	if ( 'https' === $scheme ) {
		$score += 20;
	}

	if ( maca_backup_pro_api_is_loopback_host( $host ) ) {
		$score -= 200;
	} elseif ( maca_backup_pro_api_is_private_host( $host ) ) {
		$score -= 60;
	} else {
		$score += 100;
	}

	if ( strpos( $host, '.' ) !== false ) {
		$score += 10;
	}

	return $score;
}

/**
 * Resolve the best public site URL for telemetry.
 *
 * @return string
 */
function maca_backup_pro_api_resolve_site_url() {
	$ranked = array();

	foreach ( maca_backup_pro_api_site_url_candidates() as $candidate ) {
		$normalized = maca_backup_pro_api_normalize_site_url( $candidate );
		if ( '' === $normalized ) {
			continue;
		}

		$score = maca_backup_pro_api_score_site_url( $normalized );
		if ( ! isset( $ranked[ $normalized ] ) || $score > $ranked[ $normalized ] ) {
			$ranked[ $normalized ] = $score;
		}
	}

	if ( array() === $ranked ) {
		return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
	}

	arsort( $ranked, SORT_NUMERIC );
	$resolved = (string) array_key_first( $ranked );

	/**
	 * Filter resolved site URL sent to api.maca.se.
	 *
	 * @param string             $resolved Resolved URL.
	 * @param array<string, int> $ranked   Scored candidates.
	 */
	return (string) apply_filters( 'maca_backup_pro_api_site_url', $resolved, $ranked );
}

/**
 * Site URL for telemetry payloads.
 *
 * Prefers a resolved public URL, but keeps raw/unknown values so api.maca.se
 * can register installations even when WordPress has no proper site address yet.
 *
 * @return string
 */
function maca_backup_pro_api_telemetry_site_url() {
	$resolved = maca_backup_pro_api_resolve_site_url();
	if ( '' !== $resolved ) {
		return $resolved;
	}

	foreach ( maca_backup_pro_api_site_url_candidates() as $candidate ) {
		$candidate = trim( (string) $candidate );
		if ( '' !== $candidate ) {
			return $candidate;
		}
	}

	return 'unknown';
}

/**
 * Base payload shared by all events.
 *
 * @return array<string, string>
 */
function maca_backup_pro_api_base_payload() {
	return array(
		'plugin'      => MACA_BACKUP_PRO_API_PLUGIN_SLUG,
		'version'     => maca_backup_pro_api_plugin_version(),
		'site_url'    => maca_backup_pro_api_telemetry_site_url(),
		'wp_version'  => get_bloginfo( 'version' ),
		'php_version' => PHP_VERSION,
		'locale'      => get_locale(),
	);
}

/**
 * Send an installation lifecycle event to api.maca.se.
 *
 * @param string               $event    activated|deactivated|uninstalled
 * @param array<string, mixed> $extra    Optional fields (reason, reason_label, details).
 * @param bool                 $blocking Wait for HTTP response.
 * @return bool True when the API accepted the request (2xx).
 */
function maca_backup_pro_api_send_event( $event, array $extra = array(), $blocking = true ) {
	if ( ! maca_backup_pro_api_is_enabled() ) {
		return false;
	}

	$allowed = array( 'activated', 'deactivated', 'uninstalled', 'heartbeat', 'backup_status' );
	if ( ! in_array( $event, $allowed, true ) ) {
		return false;
	}

	$body = array_merge(
		maca_backup_pro_api_base_payload(),
		array( 'event' => $event ),
		$extra
	);

	$response = wp_remote_post(
		MACA_BACKUP_PRO_API_EVENTS_URL,
		array(
			'timeout'  => 5,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type' => 'application/json; charset=utf-8',
				'Accept'       => 'application/json',
				'User-Agent'   => MACA_BACKUP_PRO_API_PLUGIN_SLUG . '/' . maca_backup_pro_api_plugin_version(),
			),
			'body'     => wp_json_encode( $body ),
		)
	);

	if ( is_wp_error( $response ) ) {
		update_option( 'maca_backup_pro_api_last_error', $response->get_error_message(), false );
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		$response_body = wp_remote_retrieve_body( $response );
		update_option(
			'maca_backup_pro_api_last_error',
			'HTTP ' . $code . ( '' !== $response_body ? ': ' . substr( $response_body, 0, 200 ) : '' ),
			false
		);
		return false;
	}

	delete_option( 'maca_backup_pro_api_last_error' );

	return true;
}

/**
 * Mark that deactivation was already reported (avoids duplicate with deactivate hook).
 *
 * @return void
 */
function maca_backup_pro_api_mark_deactivated_reported() {
	set_transient( MACA_BACKUP_PRO_API_REPORTED_TRANSIENT, 1, MINUTE_IN_SECONDS );
}

/**
 * Whether deactivation was reported in the last minute (e.g. via AJAX modal).
 *
 * @return bool
 */
function maca_backup_pro_api_was_deactivated_reported() {
	return (bool) get_transient( MACA_BACKUP_PRO_API_REPORTED_TRANSIENT );
}

/**
 * Report plugin activation to api.maca.se.
 *
 * Sets a pending flag and schedules WP-Cron delivery so activation never blocks
 * on a slow api.maca.se response (common on some shared hosts).
 *
 * @return void
 */
function maca_backup_pro_api_on_activate() {
	if ( ! maca_backup_pro_api_is_enabled() ) {
		delete_option( MACA_BACKUP_PRO_API_PENDING_OPTION );
		return;
	}

	update_option( MACA_BACKUP_PRO_API_PENDING_OPTION, 'activated', false );
	maca_backup_pro_api_schedule_flush();
}

/**
 * Schedule a cron event to deliver pending telemetry.
 *
 * @return void
 */
function maca_backup_pro_api_schedule_flush() {
	if ( ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK ) ) {
		wp_schedule_single_event( time(), MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK );
	}

	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
}

/**
 * Deliver pending telemetry in cron context (blocking, outside web requests).
 *
 * @return void
 */
function maca_backup_pro_api_flush_pending_telemetry() {
	$pending = get_option( MACA_BACKUP_PRO_API_PENDING_OPTION, '' );
	if ( ! is_string( $pending ) || '' === $pending ) {
		return;
	}

	if ( ! maca_backup_pro_api_is_enabled() ) {
		delete_option( MACA_BACKUP_PRO_API_PENDING_OPTION );
		return;
	}

	$allowed = array( 'activated', 'deactivated', 'uninstalled' );
	if ( ! in_array( $pending, $allowed, true ) ) {
		delete_option( MACA_BACKUP_PRO_API_PENDING_OPTION );
		return;
	}

	if ( maca_backup_pro_api_send_event( $pending, array(), true ) ) {
		delete_option( MACA_BACKUP_PRO_API_PENDING_OPTION );
	}
}

/**
 * Report plugin deactivation to api.maca.se (bulk deactivate, etc.).
 *
 * @return void
 */
function maca_backup_pro_api_on_deactivate() {
	static $scheduled = false;

	delete_option( MACA_BACKUP_PRO_API_PENDING_OPTION );
	wp_clear_scheduled_hook( MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK );

	if ( ! maca_backup_pro_api_is_enabled() ) {
		return;
	}

	if ( $scheduled ) {
		return;
	}

	if ( maca_backup_pro_api_was_deactivated_reported() ) {
		return;
	}

	$scheduled = true;
	add_action( 'shutdown', 'maca_backup_pro_api_send_deactivate_event', 1 );
}

/**
 * Send deactivation telemetry at end of request (non-blocking, best-effort).
 *
 * @return void
 */
function maca_backup_pro_api_send_deactivate_event() {
	static $sent = false;

	if ( $sent ) {
		return;
	}

	$sent = true;
	maca_backup_pro_api_send_event( 'deactivated', array(), false );
}

/**
 * Report plugin uninstall to api.maca.se.
 *
 * @return void
 */
function maca_backup_pro_api_on_uninstall() {
	maca_backup_pro_api_send_event( 'uninstalled', array(), true );
}

/**
 * Hub heartbeat: latest backup, storage, errors for multi-site monitoring.
 *
 * @param bool $blocking Blocking request.
 * @return bool
 */
function maca_backup_pro_api_hub_heartbeat( $blocking = false ) {
	if ( ! maca_backup_pro_api_is_enabled() ) {
		return false;
	}

	$latest = class_exists( 'Maca_Backup_Pro_Backups_Table' )
		? Maca_Backup_Pro_Backups_Table::latest_completed()
		: null;
	$job    = class_exists( 'Maca_Backup_Pro_Jobs_Table' )
		? ( Maca_Backup_Pro_Jobs_Table::active( 'backup' ) ?: Maca_Backup_Pro_Jobs_Table::active( 'restore' ) )
		: null;

	$extra = array(
		'hub' => array(
			'latest_backup' => $latest ? array(
				'id'          => (int) $latest->id,
				'type'        => (string) $latest->type,
				'status'      => (string) $latest->status,
				'size_bytes'  => (int) $latest->size_bytes,
				'finished_at' => (string) $latest->finished_at,
				'storage'     => (string) $latest->storage,
			) : null,
			'backup_count'  => class_exists( 'Maca_Backup_Pro_Backups_Table' )
				? Maca_Backup_Pro_Backups_Table::count_completed()
				: 0,
			'total_size'    => class_exists( 'Maca_Backup_Pro_Backups_Table' )
				? Maca_Backup_Pro_Backups_Table::total_size()
				: 0,
			'storage'       => (string) Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' ),
			'active_job'    => $job ? array(
				'id'       => (int) $job->id,
				'type'     => (string) $job->job_type,
				'status'   => (string) $job->status,
				'progress' => (int) $job->progress,
				'step'     => (string) $job->step,
			) : null,
			'last_error'    => (string) get_option( 'maca_backup_pro_api_last_error', '' ),
		),
	);

	$ok = maca_backup_pro_api_send_event( 'heartbeat', $extra, $blocking );
	if ( $ok ) {
		update_option( 'maca_backup_pro_hub_last_heartbeat', time(), false );
	}
	return $ok;
}

/**
 * Schedule recurring Hub heartbeat (daily).
 *
 * @return void
 */
function maca_backup_pro_api_schedule_hub_heartbeat() {
	$hook = 'maca_backup_pro_hub_heartbeat';

	if ( ! maca_backup_pro_api_is_enabled() ) {
		wp_clear_scheduled_hook( $hook );
		return;
	}

	if ( ! wp_next_scheduled( $hook ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $hook );
	}
}
