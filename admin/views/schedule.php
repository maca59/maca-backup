<?php
/**
 * Schedule view — list + create/edit.
 *
 * @package Maca_Backup_Pro
 *
 * @var array      $schedules
 * @var array|null $editing
 * @var bool       $from_onboarding
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$schedules       = is_array( $schedules ?? null ) ? $schedules : array();
$editing         = is_array( $editing ?? null ) ? $editing : null;
$is_edit         = null !== $editing;
$from_onboarding = ! empty( $from_onboarding );

$freq = (string) ( $editing['frequency'] ?? 'daily' );
$time_utc = (string) ( $editing['time_utc'] ?? '03:00' );
if ( ! preg_match( '/^\d{2}:\d{2}$/', $time_utc ) ) {
	$time_utc = '03:00';
}
[ $utc_h, $utc_m ] = array_map( 'intval', explode( ':', $time_utc ) );
$weekday_utc       = (int) ( $editing['weekday'] ?? 1 );
$dom_utc           = max( 1, min( 28, (int) ( $editing['dom'] ?? 1 ) ) );

// Editor works in site-local time; UTC is only for storage.
$local = Maca_Backup_Pro_Scheduler::utc_to_local( $utc_h, $utc_m, $weekday_utc, $dom_utc, $freq );
$local_h = $local['hour'];
$local_m = (int) ( round( $local['minute'] / 5 ) * 5 ) % 60;
$weekday = $local['weekday'];
$dom     = $local['dom'];

$enabled     = $is_edit ? ! empty( $editing['enabled'] ) : true;
$label       = (string) ( $editing['label'] ?? '' );
$backup_type = (string) ( $editing['backup_type'] ?? 'full' );
$custom_cron = (string) ( $editing['custom_cron'] ?? '' );
$schedule_id = (string) ( $editing['id'] ?? '' );
$interval_h  = Maca_Backup_Pro_Scheduler::sanitize_interval_hours( (int) ( $editing['interval_hours'] ?? 4 ) );
$email_mode  = (string) ( $editing['email_mode'] ?? 'inherit' );
if ( ! in_array( $email_mode, array( 'inherit', 'off', 'failure', 'success', 'both' ), true ) ) {
	$email_mode = 'inherit';
}

$tz      = wp_timezone();
$tz_name = $tz->getName();

$weekdays = array(
	0 => __( 'Sunday', 'maca-backup' ),
	1 => __( 'Monday', 'maca-backup' ),
	2 => __( 'Tuesday', 'maca-backup' ),
	3 => __( 'Wednesday', 'maca-backup' ),
	4 => __( 'Thursday', 'maca-backup' ),
	5 => __( 'Friday', 'maca-backup' ),
	6 => __( 'Saturday', 'maca-backup' ),
);

$schedule_url = Maca_Backup_Pro_Admin::tab_url( 'schedule' );
?>
<section class="maca-bp-panel">
	<div class="maca-bp-panel__head">
		<h2><?php esc_html_e( 'Scheduled backups', 'maca-backup' ); ?></h2>
		<?php if ( $is_edit ) : ?>
			<a class="button" href="<?php echo esc_url( $schedule_url ); ?>"><?php esc_html_e( 'Add new', 'maca-backup' ); ?></a>
		<?php endif; ?>
	</div>
	<?php
	$tz_label = $tz_name;
	if ( preg_match( '/^[+-]\d{2}:\d{2}$/', $tz_name ) ) {
		$tz_label = 'UTC' . $tz_name;
	}
	?>
	<p class="maca-bp-muted">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: WordPress site timezone name or UTC offset */
				__( 'Times below use this site’s timezone (%s) from Settings → General — not the server clock. A missed minute still runs later the same day when WP-Cron next fires (needs site traffic, or a real cron hitting wp-cron.php).', 'maca-backup' ),
				$tz_label
			)
		);
		?>
	</p>

	<?php if ( empty( $schedules ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No schedules yet. Create one below.', 'maca-backup' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table maca-bp-schedule-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Frequency', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Time', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Type', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Email', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Next run', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Status', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'maca-backup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $schedules as $row ) : ?>
					<?php
					$local_time = Maca_Backup_Pro_Scheduler::format_entry_time_local( $row );
					$next       = ! empty( $row['enabled'] ) ? Maca_Backup_Pro_Scheduler::next_occurrence_for_entry( $row ) : null;
					?>
					<tr class="<?php echo empty( $row['enabled'] ) ? 'maca-bp-schedule-row--disabled' : ''; ?>">
						<td><strong><?php echo esc_html( (string) ( $row['label'] ?: $row['id'] ) ); ?></strong></td>
						<td><?php echo esc_html( Maca_Backup_Pro_Scheduler::frequency_label( $row ) ); ?></td>
						<td>
							<span class="maca-bp-schedule-time"><?php echo esc_html( $local_time ); ?></span>
							<?php if ( 'hourly' !== (string) ( $row['frequency'] ?? '' ) && 'custom' !== (string) ( $row['frequency'] ?? '' ) ) : ?>
								<span class="maca-bp-muted"><?php esc_html_e( 'local', 'maca-backup' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) $row['backup_type'] ); ?></td>
						<td><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( (string) ( $row['email_mode'] ?? 'inherit' ) ) ); ?></td>
						<td>
							<?php if ( $next ) : ?>
								<?php echo esc_html( wp_date( 'Y-m-d H:i', $next ) ); ?>
							<?php else : ?>
								<span class="maca-bp-muted">—</span>
							<?php endif; ?>
						</td>
						<td>
							<span class="maca-bp-pill maca-bp-pill--<?php echo ! empty( $row['enabled'] ) ? 'completed' : 'cancelled'; ?>">
								<?php echo ! empty( $row['enabled'] ) ? esc_html__( 'Enabled', 'maca-backup' ) : esc_html__( 'Disabled', 'maca-backup' ); ?>
							</span>
						</td>
						<td class="maca-bp-row-actions">
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', (string) $row['id'], $schedule_url ) ); ?>"><?php esc_html_e( 'Edit', 'maca-backup' ); ?></a>
							<form method="post" class="maca-bp-inline-form">
								<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
								<input type="hidden" name="maca_backup_pro_action" value="toggle_schedule" />
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
								<input type="hidden" name="schedule_enabled" value="<?php echo empty( $row['enabled'] ) ? '1' : '0'; ?>" />
								<button type="submit" class="button button-small"><?php echo empty( $row['enabled'] ) ? esc_html__( 'Enable', 'maca-backup' ) : esc_html__( 'Disable', 'maca-backup' ); ?></button>
							</form>
							<form method="post" class="maca-bp-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this schedule?', 'maca-backup' ) ); ?>');">
								<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
								<input type="hidden" name="maca_backup_pro_action" value="delete_schedule" />
								<input type="hidden" name="schedule_id" value="<?php echo esc_attr( (string) $row['id'] ); ?>" />
								<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'maca-backup' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>

<section class="maca-bp-panel<?php echo $from_onboarding ? ' maca-bp-panel--onboarding' : ''; ?>" id="maca-bp-schedule-editor">
	<h2><?php echo $is_edit ? esc_html__( 'Edit schedule', 'maca-backup' ) : esc_html__( 'Add schedule', 'maca-backup' ); ?></h2>
	<?php if ( $from_onboarding && ! $is_edit ) : ?>
		<p class="maca-bp-onboarding-tip">
			<?php esc_html_e( 'Create a schedule here anytime. You can also set one up from the dashboard wizard on first run.', 'maca-backup' ); ?>
		</p>
	<?php endif; ?>

	<form method="post" id="maca-bp-schedule-form">
		<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
		<input type="hidden" name="maca_backup_pro_action" value="save_schedule" />
		<input type="hidden" name="schedule_id" value="<?php echo esc_attr( $schedule_id ); ?>" />

		<div class="maca-bp-schedule">
			<div class="maca-bp-schedule__meta maca-bp-schedule__meta--top">
				<label class="maca-bp-field">
					<span><?php esc_html_e( 'Name', 'maca-backup' ); ?></span>
					<input type="text" name="schedule_label" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Nightly full backup', 'maca-backup' ); ?>" />
				</label>
				<label class="maca-bp-check maca-bp-schedule__enabled">
					<input type="checkbox" name="schedule_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enabled', 'maca-backup' ); ?>
				</label>
			</div>

			<div class="maca-bp-schedule__freqs" role="radiogroup" aria-label="<?php esc_attr_e( 'Frequency', 'maca-backup' ); ?>">
				<?php
				$freqs = array(
					'hourly'      => array( __( 'Hourly', 'maca-backup' ), __( 'Every hour at :MM', 'maca-backup' ) ),
					'every_hours' => array( __( 'Every N hours', 'maca-backup' ), __( 'e.g. every 4 hours', 'maca-backup' ) ),
					'daily'       => array( __( 'Daily', 'maca-backup' ), __( 'Once per day', 'maca-backup' ) ),
					'weekly'      => array( __( 'Weekly', 'maca-backup' ), __( 'Once per week', 'maca-backup' ) ),
					'monthly'     => array( __( 'Monthly', 'maca-backup' ), __( 'Once per month', 'maca-backup' ) ),
					'custom'      => array( __( 'Custom', 'maca-backup' ), __( 'Advanced cron', 'maca-backup' ) ),
				);
				foreach ( $freqs as $value => $meta ) :
					?>
					<label class="maca-bp-schedule__freq<?php echo $freq === $value ? ' is-active' : ''; ?>">
						<input type="radio" name="schedule" value="<?php echo esc_attr( $value ); ?>" <?php checked( $freq, $value ); ?> />
						<span class="maca-bp-schedule__freq-title"><?php echo esc_html( $meta[0] ); ?></span>
						<span class="maca-bp-schedule__freq-hint"><?php echo esc_html( $meta[1] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>

			<div class="maca-bp-schedule__box" id="maca-bp-schedule-time-box" <?php echo 'custom' === $freq ? 'hidden' : ''; ?>
				data-title-hourly="<?php echo esc_attr__( 'Minute past each hour (local)', 'maca-backup' ); ?>"
				data-title-every="<?php echo esc_attr__( 'Start time (local)', 'maca-backup' ); ?>"
				data-title-default="<?php echo esc_attr__( 'Run time (local)', 'maca-backup' ); ?>"
				data-preview-hourly="<?php echo esc_attr__( 'Runs every hour at', 'maca-backup' ); ?>"
				data-preview-every="<?php echo esc_attr__( 'Repeats from', 'maca-backup' ); ?>"
				data-preview-default="<?php echo esc_attr( sprintf( /* translators: %s: timezone */ __( 'Local (%s)', 'maca-backup' ), $tz_label ) ); ?>">
				<div class="maca-bp-schedule__box-head">
					<strong id="maca-bp-schedule-time-title">
						<?php
						if ( 'hourly' === $freq ) {
							esc_html_e( 'Minute past each hour (local)', 'maca-backup' );
						} elseif ( 'every_hours' === $freq ) {
							esc_html_e( 'Start time (local)', 'maca-backup' );
						} else {
							esc_html_e( 'Run time (local)', 'maca-backup' );
						}
						?>
					</strong>
					<span class="maca-bp-schedule__tz"><?php echo esc_html( sprintf( /* translators: %s: timezone name */ __( 'Site timezone: %s', 'maca-backup' ), $tz_label ) ); ?></span>
				</div>

				<div class="maca-bp-schedule__time-row">
					<div class="maca-bp-schedule__clock" aria-hidden="true" id="maca-bp-schedule-clock">
						<span class="maca-bp-schedule__clock-face">
							<span class="maca-bp-schedule__clock-hand maca-bp-schedule__clock-hand--hour" id="maca-bp-clock-hour"></span>
							<span class="maca-bp-schedule__clock-hand maca-bp-schedule__clock-hand--minute" id="maca-bp-clock-minute"></span>
						</span>
					</div>

					<div class="maca-bp-schedule__inputs">
						<label class="maca-bp-schedule__time-field" id="maca-bp-schedule-hour-wrap" <?php echo 'hourly' === $freq ? 'hidden' : ''; ?>>
							<span><?php esc_html_e( 'Hour', 'maca-backup' ); ?></span>
							<select name="schedule_hour_local" id="maca-bp-schedule-hour">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>" <?php selected( $local_h, $h ); ?>><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
						</label>
						<span class="maca-bp-schedule__colon" id="maca-bp-schedule-colon" <?php echo 'hourly' === $freq ? 'hidden' : ''; ?>>:</span>
						<label class="maca-bp-schedule__time-field">
							<span><?php esc_html_e( 'Minute', 'maca-backup' ); ?></span>
							<select name="schedule_minute_local" id="maca-bp-schedule-minute">
								<?php for ( $m = 0; $m < 60; $m += 5 ) : ?>
									<option value="<?php echo esc_attr( sprintf( '%02d', $m ) ); ?>" <?php selected( $local_m, $m ); ?>><?php echo esc_html( sprintf( '%02d', $m ) ); ?></option>
								<?php endfor; ?>
							</select>
						</label>

						<label class="maca-bp-schedule__extra" id="maca-bp-schedule-interval-wrap" <?php echo 'every_hours' !== $freq ? 'hidden' : ''; ?>>
							<span><?php esc_html_e( 'Every', 'maca-backup' ); ?></span>
							<select name="interval_hours" id="maca-bp-schedule-interval">
								<?php foreach ( Maca_Backup_Pro_Scheduler::allowed_interval_hours() as $ih ) : ?>
									<option value="<?php echo esc_attr( (string) $ih ); ?>" <?php selected( $interval_h, $ih ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: hours */
												_n( '%d hour', '%d hours', $ih, 'maca-backup' ),
												$ih
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="maca-bp-schedule__extra" id="maca-bp-schedule-weekday-wrap" <?php echo 'weekly' !== $freq ? 'hidden' : ''; ?>>
							<span><?php esc_html_e( 'Weekday', 'maca-backup' ); ?></span>
							<select name="schedule_weekday" id="maca-bp-schedule-weekday">
								<?php foreach ( $weekdays as $num => $day_label ) : ?>
									<option value="<?php echo esc_attr( (string) $num ); ?>" <?php selected( $weekday, $num ); ?>><?php echo esc_html( $day_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="maca-bp-schedule__extra" id="maca-bp-schedule-dom-wrap" <?php echo 'monthly' !== $freq ? 'hidden' : ''; ?>>
							<span><?php esc_html_e( 'Day of month', 'maca-backup' ); ?></span>
							<select name="schedule_dom" id="maca-bp-schedule-dom">
								<?php for ( $d = 1; $d <= 28; $d++ ) : ?>
									<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( $dom, $d ); ?>><?php echo esc_html( (string) $d ); ?></option>
								<?php endfor; ?>
							</select>
						</label>
					</div>
				</div>

				<div class="maca-bp-schedule__preview" id="maca-bp-schedule-preview">
					<div class="maca-bp-schedule__preview-card maca-bp-schedule__preview-card--local">
						<span class="maca-bp-schedule__preview-label" id="maca-bp-preview-label">
							<?php
							echo 'hourly' === $freq
								? esc_html__( 'Runs every hour at', 'maca-backup' )
								: esc_html( sprintf( /* translators: %s: timezone */ __( 'Local (%s)', 'maca-backup' ), $tz_label ) );
							?>
						</span>
						<strong class="maca-bp-schedule__preview-value" id="maca-bp-preview-local">
							<?php
							echo 'hourly' === $freq
								? esc_html( sprintf( ':%02d', $local_m ) )
								: esc_html( sprintf( '%02d:%02d', $local_h, $local_m ) );
							?>
						</strong>
					</div>
				</div>
			</div>

			<div class="maca-bp-schedule__box" id="maca-bp-schedule-custom-box" <?php echo 'custom' !== $freq ? 'hidden' : ''; ?>>
				<label class="maca-bp-field">
					<span><?php esc_html_e( 'Cron expression — min hour dom month dow (evaluated in UTC)', 'maca-backup' ); ?></span>
					<input type="text" name="custom_cron" value="<?php echo esc_attr( $custom_cron ); ?>" placeholder="0 3 * * *" class="maca-bp-schedule__cron" />
				</label>
				<p class="maca-bp-muted"><?php esc_html_e( 'Advanced: prefer “Every N hours” above. Cron fields use UTC.', 'maca-backup' ); ?></p>
			</div>

			<div class="maca-bp-schedule__meta">
				<label>
					<span><?php esc_html_e( 'Backup type', 'maca-backup' ); ?></span>
					<select name="backup_type">
						<option value="full" <?php selected( $backup_type, 'full' ); ?>><?php esc_html_e( 'Full', 'maca-backup' ); ?></option>
						<option value="incremental" <?php selected( $backup_type, 'incremental' ); ?>><?php esc_html_e( 'Incremental', 'maca-backup' ); ?></option>
						<option value="differential" <?php selected( $backup_type, 'differential' ); ?>><?php esc_html_e( 'Differential', 'maca-backup' ); ?></option>
						<option value="database" <?php selected( $backup_type, 'database' ); ?>><?php esc_html_e( 'Database', 'maca-backup' ); ?></option>
						<option value="files" <?php selected( $backup_type, 'files' ); ?>><?php esc_html_e( 'Files', 'maca-backup' ); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Email notifications', 'maca-backup' ); ?></span>
					<select name="schedule_email_mode">
						<option value="inherit" <?php selected( $email_mode, 'inherit' ); ?>><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( 'inherit' ) ); ?></option>
						<option value="off" <?php selected( $email_mode, 'off' ); ?>><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( 'off' ) ); ?></option>
						<option value="failure" <?php selected( $email_mode, 'failure' ); ?>><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( 'failure' ) ); ?></option>
						<option value="success" <?php selected( $email_mode, 'success' ); ?>><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( 'success' ) ); ?></option>
						<option value="both" <?php selected( $email_mode, 'both' ); ?>><?php echo esc_html( Maca_Backup_Pro_Scheduler::email_mode_label( 'both' ) ); ?></option>
					</select>
				</label>
			</div>
			<p class="maca-bp-muted"><?php esc_html_e( 'Site default comes from Settings → Email notifications. Use “Failures only” for frequent schedules (e.g. every 4 hours) to avoid inbox noise.', 'maca-backup' ); ?></p>
		</div>

		<p class="submit">
			<button type="submit" class="button button-primary">
				<?php echo $is_edit ? esc_html__( 'Update schedule', 'maca-backup' ) : esc_html__( 'Add schedule', 'maca-backup' ); ?>
			</button>
			<?php if ( $is_edit ) : ?>
				<a class="button" href="<?php echo esc_url( $schedule_url ); ?>"><?php esc_html_e( 'Cancel', 'maca-backup' ); ?></a>
			<?php endif; ?>
		</p>
	</form>
</section>
