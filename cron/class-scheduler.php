<?php
/**
 * WP-Cron scheduler.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schedules automatic backups and job processing.
 * Multiple schedule entries; times stored in UTC, UI shows local time.
 */
class Maca_Backup_Pro_Scheduler {

	public const HOOK_SCHEDULED = 'maca_backup_pro_run_scheduled';
	public const HOOK_PROCESS   = 'maca_backup_pro_process_job';
	public const HOOK_WATCHDOG  = 'maca_backup_pro_watchdog';

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hook cron callbacks + custom schedules.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) );
		add_action( self::HOOK_SCHEDULED, array( $this, 'run_scheduled' ) );
		add_action( self::HOOK_PROCESS, array( $this, 'process_jobs' ) );
		add_action( self::HOOK_WATCHDOG, array( $this, 'watchdog' ) );
		add_action( 'admin_init', array( $this, 'maybe_kick_jobs' ), 20 );
		add_action( 'init', array( $this, 'maybe_kick_jobs' ), 99 );
	}

	/**
	 * Register custom intervals.
	 *
	 * @param array<string, array<string, mixed>> $schedules Schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function schedules( array $schedules ): array {
		$schedules['maca_hourly'] = array(
			'interval' => HOUR_IN_SECONDS,
			'display'  => __( 'Every hour (maca BackUp)', 'maca-backup-pro' ),
		);
		$schedules['maca_daily_check'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (maca BackUp schedule check)', 'maca-backup-pro' ),
		);
		$schedules['maca_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly (maca BackUp)', 'maca-backup-pro' ),
		);
		$schedules['maca_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once monthly (maca BackUp)', 'maca-backup-pro' ),
		);
		$schedules['maca_watchdog'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (maca BackUp job watchdog)', 'maca-backup-pro' ),
		);
		return $schedules;
	}

	/**
	 * All schedule entries (migrates legacy single schedule once).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all_schedules(): array {
		$stored = Maca_Backup_Pro_Settings::get( 'backup_schedules', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$out = array();
		foreach ( $stored as $row ) {
			if ( is_array( $row ) ) {
				$out[] = self::normalize_entry( $row );
			}
		}

		if ( ! empty( $out ) ) {
			return $out;
		}

		// Migrate legacy single-schedule settings once.
		$legacy_freq   = (string) Maca_Backup_Pro_Settings::get( 'schedule', 'manual' );
		$migrated_flag = (bool) get_option( 'maca_backup_pro_schedules_migrated', false );
		if ( $migrated_flag || 'manual' === $legacy_freq || '' === $legacy_freq ) {
			return $out;
		}

		$migrated = array(
			self::normalize_entry(
				array(
					'id'          => 'legacy_' . wp_generate_password( 6, false, false ),
					'label'       => __( 'Default schedule', 'maca-backup-pro' ),
					'enabled'     => true,
					'frequency'   => $legacy_freq,
					'time_utc'    => (string) Maca_Backup_Pro_Settings::get( 'schedule_time_utc', '03:00' ),
					'weekday'     => (int) Maca_Backup_Pro_Settings::get( 'schedule_weekday', 1 ),
					'dom'         => (int) Maca_Backup_Pro_Settings::get( 'schedule_dom', 1 ),
					'custom_cron' => (string) Maca_Backup_Pro_Settings::get( 'custom_cron', '' ),
					'backup_type' => (string) Maca_Backup_Pro_Settings::get( 'backup_type', 'full' ),
				)
			),
		);

		Maca_Backup_Pro_Settings::update( array( 'backup_schedules' => $migrated ) );
		update_option( 'maca_backup_pro_schedules_migrated', 1, false );
		return $migrated;
	}

	/**
	 * Enabled schedules only.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function enabled_schedules(): array {
		return array_values(
			array_filter(
				self::all_schedules(),
				static fn( $s ) => ! empty( $s['enabled'] ) && 'manual' !== ( $s['frequency'] ?? '' )
			)
		);
	}

	/**
	 * Get one schedule by ID.
	 *
	 * @param string $id Schedule ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_schedule( string $id ): ?array {
		foreach ( self::all_schedules() as $row ) {
			if ( (string) ( $row['id'] ?? '' ) === $id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Normalize a schedule entry.
	 *
	 * @param array<string, mixed> $raw Raw entry.
	 * @return array<string, mixed>
	 */
	public static function normalize_entry( array $raw ): array {
		$freq = sanitize_key( (string) ( $raw['frequency'] ?? $raw['schedule'] ?? 'daily' ) );
		if ( ! in_array( $freq, array( 'hourly', 'every_hours', 'daily', 'weekly', 'monthly', 'custom' ), true ) ) {
			$freq = 'daily';
		}

		$time = (string) ( $raw['time_utc'] ?? $raw['schedule_time_utc'] ?? '03:00' );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			$time = '03:00';
		} else {
			$time = sprintf( '%02d:%02d', max( 0, min( 23, (int) $m[1] ) ), max( 0, min( 59, (int) $m[2] ) ) );
		}

		$id = (string) ( $raw['id'] ?? '' );
		if ( '' === $id ) {
			$id = 'sch_' . wp_generate_password( 8, false, false );
		}

		$interval = self::sanitize_interval_hours( (int) ( $raw['interval_hours'] ?? 4 ) );

		return array(
			'id'             => sanitize_key( $id ),
			'label'          => sanitize_text_field( (string) ( $raw['label'] ?? '' ) ),
			'enabled'        => ! empty( $raw['enabled'] ),
			'frequency'      => $freq,
			'time_utc'       => $time,
			'interval_hours' => $interval,
			'weekday'        => absint( $raw['weekday'] ?? $raw['schedule_weekday'] ?? 1 ) % 7,
			'dom'            => max( 1, min( 28, absint( $raw['dom'] ?? $raw['schedule_dom'] ?? 1 ) ) ),
			'custom_cron'    => sanitize_text_field( (string) ( $raw['custom_cron'] ?? '' ) ),
			'backup_type'    => sanitize_key( (string) ( $raw['backup_type'] ?? 'full' ) ) ?: 'full',
		);
	}

	/**
	 * Allowed hour-interval choices.
	 *
	 * @return int[]
	 */
	public static function allowed_interval_hours(): array {
		return array( 2, 3, 4, 6, 8, 12 );
	}

	/**
	 * Sanitize interval hours to an allowed value.
	 *
	 * @param int $hours Raw hours.
	 * @return int
	 */
	public static function sanitize_interval_hours( int $hours ): int {
		$allowed = self::allowed_interval_hours();
		return in_array( $hours, $allowed, true ) ? $hours : 4;
	}

	/**
	 * Interval hours from an entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return int
	 */
	public static function entry_interval_hours( array $entry ): int {
		return self::sanitize_interval_hours( (int) ( $entry['interval_hours'] ?? 4 ) );
	}

	/**
	 * Create or update a schedule entry.
	 *
	 * @param array<string, mixed> $raw Entry data.
	 * @return array<string, mixed>
	 */
	public static function upsert_schedule( array $raw ): array {
		$entry = self::normalize_entry( $raw );
		$list  = self::all_schedules();
		$found = false;
		foreach ( $list as $i => $row ) {
			if ( (string) $row['id'] === (string) $entry['id'] ) {
				$list[ $i ] = $entry;
				$found       = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( '' === $entry['label'] ) {
				$entry['label'] = self::default_label( $entry );
			}
			$list[] = $entry;
		} elseif ( '' === $entry['label'] ) {
			$entry['label'] = self::default_label( $entry );
			foreach ( $list as $i => $row ) {
				if ( (string) $row['id'] === (string) $entry['id'] ) {
					$list[ $i ] = $entry;
				}
			}
		}

		Maca_Backup_Pro_Settings::update( array( 'backup_schedules' => array_values( $list ) ) );
		self::instance()->reschedule();
		return $entry;
	}

	/**
	 * Delete a schedule.
	 *
	 * @param string $id Schedule ID.
	 * @return bool
	 */
	public static function delete_schedule( string $id ): bool {
		$list  = self::all_schedules();
		$new   = array();
		$found = false;
		foreach ( $list as $row ) {
			if ( (string) $row['id'] === $id ) {
				$found = true;
				continue;
			}
			$new[] = $row;
		}
		if ( ! $found ) {
			return false;
		}
		Maca_Backup_Pro_Settings::update( array( 'backup_schedules' => $new ) );
		self::instance()->reschedule();
		return true;
	}

	/**
	 * Toggle enabled flag.
	 *
	 * @param string $id      Schedule ID.
	 * @param bool   $enabled Enabled.
	 * @return array<string, mixed>|null
	 */
	public static function set_enabled( string $id, bool $enabled ): ?array {
		$entry = self::get_schedule( $id );
		if ( ! $entry ) {
			return null;
		}
		$entry['enabled'] = $enabled;
		return self::upsert_schedule( $entry );
	}

	/**
	 * Default label from frequency/type.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string
	 */
	public static function default_label( array $entry ): string {
		$type = (string) ( $entry['backup_type'] ?? 'full' );
		$freq = (string) ( $entry['frequency'] ?? 'daily' );
		if ( 'every_hours' === $freq ) {
			return sprintf(
				/* translators: 1: hours, 2: backup type */
				__( 'Every %1$d hours %2$s', 'maca-backup-pro' ),
				self::entry_interval_hours( $entry ),
				$type
			);
		}
		return ucfirst( $freq ) . ' ' . $type;
	}

	/**
	 * Human-readable run time for the schedule list (local).
	 * Hourly schedules only use the minute past each hour.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string
	 */
	public static function format_entry_time_local( array $entry ): string {
		$freq = (string) ( $entry['frequency'] ?? 'daily' );
		[ $uh, $um ] = self::entry_utc_parts( $entry );
		$local       = self::utc_to_local( $uh, $um, (int) ( $entry['weekday'] ?? 1 ), (int) ( $entry['dom'] ?? 1 ), $freq );

		if ( 'hourly' === $freq ) {
			return sprintf(
				/* translators: %s: minutes past the hour, e.g. 00 */
				__( 'Every hour at :%s', 'maca-backup-pro' ),
				sprintf( '%02d', $local['minute'] )
			);
		}

		if ( 'every_hours' === $freq ) {
			return sprintf(
				/* translators: 1: hours interval, 2: local time HH:MM */
				__( 'Every %1$d hours from %2$s', 'maca-backup-pro' ),
				self::entry_interval_hours( $entry ),
				sprintf( '%02d:%02d', $local['hour'], $local['minute'] )
			);
		}

		if ( 'custom' === $freq ) {
			$cron = trim( (string) ( $entry['custom_cron'] ?? '' ) );
			return '' !== $cron ? $cron : '—';
		}

		return sprintf( '%02d:%02d', $local['hour'], $local['minute'] );
	}

	/**
	 * Human frequency label.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string
	 */
	public static function frequency_label( array $entry ): string {
		$freq = (string) ( $entry['frequency'] ?? 'daily' );
		$labels = array(
			'hourly'      => __( 'Hourly', 'maca-backup-pro' ),
			'every_hours' => __( 'Every N hours', 'maca-backup-pro' ),
			'daily'       => __( 'Daily', 'maca-backup-pro' ),
			'weekly'      => __( 'Weekly', 'maca-backup-pro' ),
			'monthly'     => __( 'Monthly', 'maca-backup-pro' ),
			'custom'      => __( 'Custom', 'maca-backup-pro' ),
		);
		$base = $labels[ $freq ] ?? $freq;

		[ $uh, $um ] = self::entry_utc_parts( $entry );
		$local       = self::utc_to_local( $uh, $um, (int) ( $entry['weekday'] ?? 1 ), (int) ( $entry['dom'] ?? 1 ), $freq );

		if ( 'every_hours' === $freq ) {
			$base = sprintf(
				/* translators: %d: hours */
				__( 'Every %d hours', 'maca-backup-pro' ),
				self::entry_interval_hours( $entry )
			);
		} elseif ( 'weekly' === $freq ) {
			$days = array(
				__( 'Sunday', 'maca-backup-pro' ),
				__( 'Monday', 'maca-backup-pro' ),
				__( 'Tuesday', 'maca-backup-pro' ),
				__( 'Wednesday', 'maca-backup-pro' ),
				__( 'Thursday', 'maca-backup-pro' ),
				__( 'Friday', 'maca-backup-pro' ),
				__( 'Saturday', 'maca-backup-pro' ),
			);
			$base .= ' · ' . ( $days[ $local['weekday'] ] ?? '' );
		} elseif ( 'monthly' === $freq ) {
			$base .= ' · ' . sprintf(
				/* translators: %d: day of month */
				__( 'day %d', 'maca-backup-pro' ),
				$local['dom']
			);
		} elseif ( 'custom' === $freq && ! empty( $entry['custom_cron'] ) ) {
			$base .= ' · ' . (string) $entry['custom_cron'];
		}

		return $base;
	}

	/**
	 * Clear and re-register based on settings.
	 *
	 * @return void
	 */
	public function reschedule(): void {
		$this->clear();

		if ( empty( self::enabled_schedules() ) ) {
			return;
		}

		$first = self::next_occurrence_timestamp();
		if ( ! $first ) {
			$first = time() + MINUTE_IN_SECONDS;
		}

		if ( ! wp_next_scheduled( self::HOOK_SCHEDULED ) ) {
			wp_schedule_event( min( $first, time() + MINUTE_IN_SECONDS ), 'maca_daily_check', self::HOOK_SCHEDULED );
		}
	}

	/**
	 * Clear automatic backup schedule (keeps in-flight process jobs).
	 *
	 * @return void
	 */
	public function clear(): void {
		wp_clear_scheduled_hook( self::HOOK_SCHEDULED );
	}

	/**
	 * Clear pending process events.
	 *
	 * @return void
	 */
	public function clear_process(): void {
		wp_clear_scheduled_hook( self::HOOK_PROCESS );
	}

	/**
	 * Clear schedule + process events (plugin deactivate).
	 *
	 * @return void
	 */
	public function clear_all(): void {
		$this->clear();
		$this->clear_process();
		$this->clear_watchdog();
	}

	/**
	 * Queue processing ASAP and poke a background loopback.
	 *
	 * @return void
	 */
	public function schedule_process(): void {
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_single_event( time() + 1, self::HOOK_PROCESS );
		}
		$this->ensure_watchdog();
		spawn_cron();
		$this->spawn_loopback();
	}

	/**
	 * Keep a recurring cron alive while backup/restore jobs are active.
	 * WP-Cron otherwise only runs when the site is visited.
	 *
	 * @return void
	 */
	public function ensure_watchdog(): void {
		if ( ! wp_next_scheduled( self::HOOK_WATCHDOG ) ) {
			wp_schedule_event( time() + 30, 'maca_watchdog', self::HOOK_WATCHDOG );
		}
	}

	/**
	 * Clear watchdog when idle.
	 *
	 * @return void
	 */
	public function clear_watchdog(): void {
		wp_clear_scheduled_hook( self::HOOK_WATCHDOG );
	}

	/**
	 * Minute tick: advance stuck jobs without relying on page views.
	 *
	 * @return void
	 */
	public function watchdog(): void {
		Maca_Backup_Pro_Jobs_Table::reap_stale();

		$has = Maca_Backup_Pro_Jobs_Table::active( 'backup' ) || Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		if ( ! $has ) {
			$this->clear_watchdog();
			return;
		}

		$this->process_jobs();
		$this->ensure_watchdog();
		spawn_cron();
		$this->spawn_loopback();
	}

	/**
	 * Nudge processing when an admin (or front-end) request hits WP.
	 *
	 * @return void
	 */
	public function maybe_kick_jobs(): void {
		if ( get_transient( 'maca_backup_pro_kick_cool' ) ) {
			return;
		}
		if ( ! Maca_Backup_Pro_Jobs_Table::active( 'backup' ) && ! Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) {
			return;
		}
		set_transient( 'maca_backup_pro_kick_cool', 1, 15 );
		$this->schedule_process();
	}

	/**
	 * Shared-secret token for unauthenticated background process pings.
	 *
	 * @return string
	 */
	public static function process_token(): string {
		return hash_hmac( 'sha256', 'maca_backup_pro_bg_process', wp_salt( 'auth' ) );
	}

	/**
	 * Fire a non-blocking request so jobs keep moving without a browser tab.
	 *
	 * @return void
	 */
	public function spawn_loopback(): void {
		if ( get_transient( 'maca_backup_pro_loopback_cool' ) ) {
			return;
		}
		set_transient( 'maca_backup_pro_loopback_cool', 1, 2 );

		$url = admin_url( 'admin-ajax.php' );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'body'      => array(
					'action' => 'maca_backup_pro_bg_process',
					'token'  => self::process_token(),
				),
			)
		);
	}

	/**
	 * Run matching scheduled backups (UTC windows).
	 *
	 * @return void
	 */
	public function run_scheduled(): void {
		// Prevent overlapping cron ticks from starting multiple jobs.
		if ( get_transient( 'maca_backup_pro_sched_lock' ) ) {
			return;
		}
		set_transient( 'maca_backup_pro_sched_lock', 1, 2 * MINUTE_IN_SECONDS );

		try {
			foreach ( self::enabled_schedules() as $entry ) {
				if ( ! self::entry_should_run_now( $entry ) ) {
					continue;
				}

				$id   = (string) ( $entry['id'] ?? '' );
				$slot = self::entry_slot_key( $entry );
				if ( '' === $id || self::has_run_slot( $id, $slot ) ) {
					continue;
				}

				if ( get_transient( 'maca_backup_pro_sched_fail_' . $id ) ) {
					continue;
				}

				$type   = (string) ( $entry['backup_type'] ?? 'full' );
				$result = Maca_Backup_Pro_Backup_Engine::start(
					$type,
					array(
						'schedule_id' => $id,
					)
				);

				if ( is_wp_error( $result ) ) {
					$code = $result->get_error_code();
					Maca_Backup_Pro_Logger::error(
						sprintf(
							/* translators: 1: schedule label, 2: error */
							__( 'Scheduled backup “%1$s” failed to start: %2$s', 'maca-backup-pro' ),
							(string) ( $entry['label'] ?: $id ),
							$result->get_error_message()
						),
						array( 'schedule_id' => $id )
					);

					if ( 'busy' === $code ) {
						// Another schedule may still start if resources do not overlap.
						continue;
					}

					// Avoid a tight retry loop on hard failures.
					set_transient( 'maca_backup_pro_sched_fail_' . $id, 1, 5 * MINUTE_IN_SECONDS );
					continue;
				}

				// Claim the slot only after a successful start (durable + transient).
				self::mark_slot_ran( $id, $slot );

				Maca_Backup_Pro_Logger::info(
					sprintf(
						/* translators: %s: schedule label */
						__( 'Scheduled backup “%s” started.', 'maca-backup-pro' ),
						(string) ( $entry['label'] ?: $id )
					),
					array(
						'schedule_id' => $id,
						'job_id'      => (int) ( $result['job_id'] ?? 0 ),
						'slot'        => $slot,
					)
				);
				// Keep going — complementary schedules (e.g. database + files) may also start.
			}
		} finally {
			delete_transient( 'maca_backup_pro_sched_lock' );
		}
	}

	/**
	 * Whether this schedule already ran for the given slot.
	 *
	 * @param string $id   Schedule ID.
	 * @param string $slot Slot key.
	 * @return bool
	 */
	private static function has_run_slot( string $id, string $slot ): bool {
		$runs = get_option( 'maca_backup_pro_schedule_runs', array() );
		if ( ! is_array( $runs ) ) {
			$runs = array();
		}
		$prev = (string) ( $runs[ $id ]['slot'] ?? '' );
		if ( $prev === $slot ) {
			return true;
		}

		$tkey = 'maca_backup_pro_ran_' . md5( $id . '|' . $slot );
		return (bool) get_transient( $tkey );
	}

	/**
	 * Persist that a schedule slot has been started.
	 *
	 * @param string $id   Schedule ID.
	 * @param string $slot Slot key.
	 * @return void
	 */
	private static function mark_slot_ran( string $id, string $slot ): void {
		$runs = get_option( 'maca_backup_pro_schedule_runs', array() );
		if ( ! is_array( $runs ) ) {
			$runs = array();
		}
		$runs[ $id ] = array(
			'slot' => $slot,
			'at'   => time(),
		);
		// Keep the map from growing forever.
		if ( count( $runs ) > 50 ) {
			uasort(
				$runs,
				static function ( $a, $b ) {
					return (int) ( $a['at'] ?? 0 ) <=> (int) ( $b['at'] ?? 0 );
				}
			);
			$runs = array_slice( $runs, -40, null, true );
		}
		update_option( 'maca_backup_pro_schedule_runs', $runs, false );

		$ttl = DAY_IN_SECONDS + HOUR_IN_SECONDS;
		set_transient( 'maca_backup_pro_ran_' . md5( $id . '|' . $slot ), 1, $ttl );
	}

	/**
	 * Whether an entry matches the current UTC window.
	 *
	 * @param array<string, mixed> $entry Schedule entry.
	 * @return bool
	 */
	public static function entry_should_run_now( array $entry ): bool {
		$freq = (string) ( $entry['frequency'] ?? '' );
		if ( 'custom' === $freq ) {
			return self::cron_matches_now_utc( (string) ( $entry['custom_cron'] ?? '' ) );
		}

		[ $hour, $minute ] = self::entry_utc_parts( $entry );
		$now_h = (int) gmdate( 'G' );
		$now_m = (int) gmdate( 'i' );

		$target    = ( $hour * 60 ) + $minute;
		$current   = ( $now_h * 60 ) + $now_m;
		$in_window = ( $current >= $target && $current < $target + 15 );

		if ( 'hourly' === $freq ) {
			return $now_m >= $minute && $now_m < $minute + 15;
		}
		if ( 'every_hours' === $freq ) {
			$interval = self::entry_interval_hours( $entry );
			$phase    = ( $now_h - $hour + 24 ) % 24;
			if ( 0 !== ( $phase % $interval ) ) {
				return false;
			}
			return $now_m >= $minute && $now_m < $minute + 15;
		}
		if ( ! $in_window ) {
			return false;
		}
		if ( 'daily' === $freq ) {
			return true;
		}
		if ( 'weekly' === $freq ) {
			return (int) gmdate( 'w' ) === (int) ( $entry['weekday'] ?? 1 );
		}
		if ( 'monthly' === $freq ) {
			return (int) gmdate( 'j' ) === max( 1, min( 28, (int) ( $entry['dom'] ?? 1 ) ) );
		}
		return false;
	}

	/**
	 * Slot key for de-duplication.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return string
	 */
	private static function entry_slot_key( array $entry ): string {
		$id = (string) ( $entry['id'] ?? 'x' );
		[ $hour, $minute ] = self::entry_utc_parts( $entry );
		$freq = (string) ( $entry['frequency'] ?? 'daily' );
		// Floor minutes to 15-minute buckets so custom/hourly windows cannot re-fire every tick.
		$bucket = (int) ( floor( $minute / 15 ) * 15 );
		$base   = match ( $freq ) {
			'hourly'      => gmdate( 'Y-m-d-H' ) . '-' . sprintf( '%02d', $bucket ),
			'every_hours' => gmdate( 'Y-m-d-H' ) . '-' . sprintf( '%02d', $bucket ),
			'daily'       => gmdate( 'Y-m-d' ) . '-' . sprintf( '%02d%02d', $hour, $bucket ),
			'weekly'      => gmdate( 'o-W' ) . '-' . sprintf( '%02d%02d', $hour, $bucket ),
			'monthly'     => gmdate( 'Y-m' ) . '-' . sprintf( '%02d%02d', $hour, $bucket ),
			'custom'      => gmdate( 'Y-m-d-H' ) . '-' . sprintf( '%02d', (int) ( floor( (int) gmdate( 'i' ) / 15 ) * 15 ) ),
			default       => gmdate( 'Y-m-d-H' ) . '-' . sprintf( '%02d', (int) ( floor( (int) gmdate( 'i' ) / 15 ) * 15 ) ),
		};
		return $id . '_' . $base;
	}

	/**
	 * UTC hour/minute from an entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return array{0:int,1:int}
	 */
	public static function entry_utc_parts( array $entry ): array {
		$time = (string) ( $entry['time_utc'] ?? '03:00' );
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			return array( 3, 0 );
		}
		return array(
			max( 0, min( 23, (int) $m[1] ) ),
			max( 0, min( 59, (int) $m[2] ) ),
		);
	}

	/**
	 * Format stored UTC time as local HH:MM.
	 *
	 * @param int $hour   UTC hour.
	 * @param int $minute UTC minute.
	 * @return string
	 */
	public static function format_local_from_utc( int $hour, int $minute ): string {
		$local = self::utc_to_local( $hour, $minute, 1, 1, 'daily' );
		return sprintf( '%02d:%02d', $local['hour'], $local['minute'] );
	}

	/**
	 * Convert local clock (and weekday/DOM when relevant) to UTC storage fields.
	 *
	 * @param int    $hour    Local hour 0–23.
	 * @param int    $minute  Local minute 0–59.
	 * @param int    $weekday Local weekday 0–6 (Sun–Sat).
	 * @param int    $dom     Local day of month 1–28.
	 * @param string $freq    Frequency key.
	 * @return array{hour:int,minute:int,weekday:int,dom:int}
	 */
	public static function local_to_utc( int $hour, int $minute, int $weekday, int $dom, string $freq ): array {
		$hour    = max( 0, min( 23, $hour ) );
		$minute  = max( 0, min( 59, $minute ) );
		$weekday = absint( $weekday ) % 7;
		$dom     = max( 1, min( 28, $dom ) );

		// Hourly: only the minute past each hour matters — convert via noon to avoid DST edges.
		if ( 'hourly' === $freq ) {
			$hour = 12;
		}

		try {
			$tz  = wp_timezone();
			$utc = new DateTimeZone( 'UTC' );
			$ref = self::reference_local_datetime( $hour, $minute, $weekday, $dom, $freq, $tz );
			$ref->setTimezone( $utc );
			$out = array(
				'hour'    => (int) $ref->format( 'G' ),
				'minute'  => (int) $ref->format( 'i' ),
				'weekday' => (int) $ref->format( 'w' ),
				'dom'     => max( 1, min( 28, (int) $ref->format( 'j' ) ) ),
			);
			if ( 'hourly' === $freq ) {
				$out['hour'] = 0;
			}
			return $out;
		} catch ( Exception $e ) {
			return array(
				'hour'    => 'hourly' === $freq ? 0 : $hour,
				'minute'  => $minute,
				'weekday' => $weekday,
				'dom'     => $dom,
			);
		}
	}

	/**
	 * Convert stored UTC fields back to local clock for the editor/labels.
	 *
	 * @param int    $hour    UTC hour.
	 * @param int    $minute  UTC minute.
	 * @param int    $weekday UTC weekday.
	 * @param int    $dom     UTC day of month.
	 * @param string $freq    Frequency key.
	 * @return array{hour:int,minute:int,weekday:int,dom:int}
	 */
	public static function utc_to_local( int $hour, int $minute, int $weekday, int $dom, string $freq ): array {
		$hour    = max( 0, min( 23, $hour ) );
		$minute  = max( 0, min( 59, $minute ) );
		$weekday = absint( $weekday ) % 7;
		$dom     = max( 1, min( 28, $dom ) );

		if ( 'hourly' === $freq ) {
			$hour = 12;
		}

		try {
			$tz  = wp_timezone();
			$utc = new DateTimeZone( 'UTC' );
			$ref = self::reference_local_datetime( $hour, $minute, $weekday, $dom, $freq, $utc );
			$ref->setTimezone( $tz );
			$out = array(
				'hour'    => (int) $ref->format( 'G' ),
				'minute'  => (int) $ref->format( 'i' ),
				'weekday' => (int) $ref->format( 'w' ),
				'dom'     => max( 1, min( 28, (int) $ref->format( 'j' ) ) ),
			);
			if ( 'hourly' === $freq ) {
				// Editor only needs the minute; hour is unused.
				$out['hour'] = 0;
			}
			return $out;
		} catch ( Exception $e ) {
			return array(
				'hour'    => 'hourly' === $freq ? 0 : $hour,
				'minute'  => $minute,
				'weekday' => $weekday,
				'dom'     => $dom,
			);
		}
	}

	/**
	 * Build a concrete DateTime in $tz that matches hour/minute (+ weekday/DOM).
	 *
	 * @param int            $hour    Hour.
	 * @param int            $minute  Minute.
	 * @param int            $weekday Weekday 0–6.
	 * @param int            $dom     Day of month.
	 * @param string         $freq    Frequency.
	 * @param DateTimeZone   $tz      Timezone for the wall clock.
	 * @return DateTime
	 */
	private static function reference_local_datetime( int $hour, int $minute, int $weekday, int $dom, string $freq, DateTimeZone $tz ): DateTime {
		$dt = new DateTime( 'now', $tz );
		$dt->setTime( $hour, $minute, 0 );

		if ( 'weekly' === $freq ) {
			$current_w = (int) $dt->format( 'w' );
			$delta     = ( $weekday - $current_w + 7 ) % 7;
			if ( $delta > 0 ) {
				$dt->modify( '+' . $delta . ' days' );
				$dt->setTime( $hour, $minute, 0 );
			}
			return $dt;
		}

		if ( 'monthly' === $freq ) {
			$y = (int) $dt->format( 'Y' );
			$m = (int) $dt->format( 'n' );
			$dt->setDate( $y, $m, min( $dom, (int) $dt->format( 't' ) ) );
			$dt->setTime( $hour, $minute, 0 );
			return $dt;
		}

		return $dt;
	}

	/**
	 * Next occurrence for one entry.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @return int|null
	 */
	public static function next_occurrence_for_entry( array $entry ): ?int {
		$freq = (string) ( $entry['frequency'] ?? '' );
		if ( 'custom' === $freq ) {
			// Approximate: next 15-min tick that matches cron — scan ahead.
			$now = time();
			for ( $i = 0; $i < 96 * 14; $i++ ) {
				$ts = $now + ( $i * 15 * MINUTE_IN_SECONDS );
				if ( self::cron_matches_at_utc( (string) ( $entry['custom_cron'] ?? '' ), $ts ) ) {
					return $ts;
				}
			}
			return null;
		}

		[ $hour, $minute ] = self::entry_utc_parts( $entry );
		$weekday = (int) ( $entry['weekday'] ?? 1 );
		$dom     = max( 1, min( 28, (int) ( $entry['dom'] ?? 1 ) ) );
		$now     = time();

		for ( $i = 0; $i < 370; $i++ ) {
			$ts = $now + ( $i * 15 * MINUTE_IN_SECONDS );
			$h  = (int) gmdate( 'G', $ts );
			$m  = (int) gmdate( 'i', $ts );
			$w  = (int) gmdate( 'w', $ts );
			$d  = (int) gmdate( 'j', $ts );

			if ( 'hourly' === $freq ) {
				if ( $m >= $minute && $m < $minute + 15 ) {
					return gmmktime( $h, $minute, 0, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
				}
				continue;
			}

			if ( 'every_hours' === $freq ) {
				$interval = self::entry_interval_hours( $entry );
				$phase    = ( $h - $hour + 24 ) % 24;
				if ( 0 === ( $phase % $interval ) && $m >= $minute && $m < $minute + 15 ) {
					return gmmktime( $h, $minute, 0, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
				}
				continue;
			}

			if ( $h !== $hour || $m < $minute || $m >= $minute + 15 ) {
				continue;
			}

			if ( 'daily' === $freq ) {
				return gmmktime( $hour, $minute, 0, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
			}
			if ( 'weekly' === $freq && $w === $weekday ) {
				return gmmktime( $hour, $minute, 0, (int) gmdate( 'n', $ts ), (int) gmdate( 'j', $ts ), (int) gmdate( 'Y', $ts ) );
			}
			if ( 'monthly' === $freq && $d === $dom ) {
				return gmmktime( $hour, $minute, 0, (int) gmdate( 'n', $ts ), $dom, (int) gmdate( 'Y', $ts ) );
			}
		}

		return null;
	}

	/**
	 * Earliest next run across enabled schedules.
	 *
	 * @return int|null Unix timestamp.
	 */
	public static function next_occurrence_timestamp(): ?int {
		$soonest = null;
		foreach ( self::enabled_schedules() as $entry ) {
			$ts = self::next_occurrence_for_entry( $entry );
			if ( $ts && ( null === $soonest || $ts < $soonest ) ) {
				$soonest = $ts;
			}
		}
		return $soonest;
	}

	/**
	 * Process active backup/restore jobs in a time-budgeted loop.
	 *
	 * @return void
	 */
	public function process_jobs(): void {
		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			$budget = new Maca_Backup_Pro_Chunk_Processor( 25 );
			$ran    = false;

			do {
				$backups = Maca_Backup_Pro_Jobs_Table::active_all( 'backup' );
				if ( ! empty( $backups ) ) {
					foreach ( $backups as $backup ) {
						Maca_Backup_Pro_Backup_Engine::process( (int) $backup->id );
						$ran = true;
						if ( $budget->should_yield() || $budget->memory_pressure() ) {
							break 2;
						}
					}
					// Re-check after a full round; stop if nothing left.
					if ( empty( Maca_Backup_Pro_Jobs_Table::active_all( 'backup' ) ) && ! Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) {
						break;
					}
					continue;
				}

				$restore = Maca_Backup_Pro_Jobs_Table::active( 'restore' );
				if ( ! $restore ) {
					break;
				}
				$result = Maca_Backup_Pro_Restore_Engine::process( (int) $restore->id );
				$ran    = true;
				if ( ! empty( $result['done'] ) ) {
					break;
				}

				if ( $budget->should_yield() || $budget->memory_pressure() ) {
					break;
				}
			} while ( true );

			if ( $ran && ( Maca_Backup_Pro_Jobs_Table::active( 'backup' ) || Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) ) {
				$this->clear_process();
				wp_schedule_single_event( time() + 1, self::HOOK_PROCESS );
				$this->ensure_watchdog();
				spawn_cron();
				$this->spawn_loopback();
			} elseif ( ! Maca_Backup_Pro_Jobs_Table::active( 'backup' ) && ! Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) {
				$this->clear_watchdog();
			}
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Prevent overlapping processors.
	 *
	 * @return bool
	 */
	private function acquire_lock(): bool {
		$lock = 'maca_backup_pro_process_lock';
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, 90 );
		return true;
	}

	/**
	 * Release process lock.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		delete_transient( 'maca_backup_pro_process_lock' );
	}

	/**
	 * Next scheduled timestamp (soonest enabled schedule).
	 *
	 * @return int|null
	 */
	public function next_run(): ?int {
		return self::next_occurrence_timestamp();
	}

	/**
	 * Cron matcher at a specific UTC timestamp.
	 *
	 * @param string $expr Cron expression.
	 * @param int    $ts   Timestamp.
	 * @return bool
	 */
	public static function cron_matches_at_utc( string $expr, int $ts ): bool {
		$expr = trim( $expr );
		if ( '' === $expr ) {
			return false;
		}
		$parts = preg_split( '/\s+/', $expr );
		if ( ! is_array( $parts ) || 5 !== count( $parts ) ) {
			return false;
		}
		$now = array(
			(int) gmdate( 'i', $ts ),
			(int) gmdate( 'G', $ts ),
			(int) gmdate( 'j', $ts ),
			(int) gmdate( 'n', $ts ),
			(int) gmdate( 'w', $ts ),
		);
		foreach ( $parts as $i => $field ) {
			if ( ! self::cron_field_matches( $field, $now[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Minimal 5-field cron matcher against current UTC.
	 *
	 * @param string $expr Cron expression.
	 * @return bool
	 */
	public static function cron_matches_now_utc( string $expr ): bool {
		return self::cron_matches_at_utc( $expr, time() );
	}

	/**
	 * Backward-compatible alias (UTC).
	 *
	 * @param string $expr Cron expression.
	 * @return bool
	 */
	public static function cron_matches_now( string $expr ): bool {
		return self::cron_matches_now_utc( $expr );
	}

	/**
	 * Match a single cron field.
	 *
	 * @param string $field Field.
	 * @param int    $value Current value.
	 * @return bool
	 */
	private static function cron_field_matches( string $field, int $value ): bool {
		if ( '*' === $field ) {
			return true;
		}
		foreach ( explode( ',', $field ) as $piece ) {
			if ( str_contains( $piece, '/' ) ) {
				[ $range, $step ] = array_pad( explode( '/', $piece, 2 ), 2, '1' );
				$step = max( 1, (int) $step );
				if ( '*' === $range ) {
					if ( 0 === ( $value % $step ) ) {
						return true;
					}
					continue;
				}
			}
			if ( str_contains( $piece, '-' ) ) {
				[ $a, $b ] = array_map( 'intval', explode( '-', $piece, 2 ) );
				if ( $value >= $a && $value <= $b ) {
					return true;
				}
				continue;
			}
			if ( (int) $piece === $value ) {
				return true;
			}
		}
		return false;
	}
}
