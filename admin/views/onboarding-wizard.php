<?php
/**
 * First-run onboarding wizard (dashboard).
 *
 * @package Maca_Backup_Pro
 *
 * @var string $current_storage
 * @var array  $providers       List of { id, label, configured }
 * @var string $storage_url
 * @var array  $schedule_defaults Hour/minute/weekday in site-local time
 * @var string $tz_label
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$current_storage    = (string) ( $current_storage ?? 'local' );
$providers          = is_array( $providers ?? null ) ? $providers : array();
$storage_url        = (string) ( $storage_url ?? Maca_Backup_Pro_Admin::tab_url( 'storage' ) );
$schedule_defaults  = is_array( $schedule_defaults ?? null ) ? $schedule_defaults : array();
$local_h            = isset( $schedule_defaults['hour'] ) ? (int) $schedule_defaults['hour'] : 3;
$local_m            = isset( $schedule_defaults['minute'] ) ? (int) $schedule_defaults['minute'] : 0;
$weekday            = isset( $schedule_defaults['weekday'] ) ? (int) $schedule_defaults['weekday'] : 1;
$tz_label           = (string) ( $tz_label ?? wp_timezone()->getName() );

$weekdays = array(
	0 => __( 'Sunday', 'maca-backup-pro' ),
	1 => __( 'Monday', 'maca-backup-pro' ),
	2 => __( 'Tuesday', 'maca-backup-pro' ),
	3 => __( 'Wednesday', 'maca-backup-pro' ),
	4 => __( 'Thursday', 'maca-backup-pro' ),
	5 => __( 'Friday', 'maca-backup-pro' ),
	6 => __( 'Saturday', 'maca-backup-pro' ),
);

$type_labels = array(
	'full'     => __( 'Full backup', 'maca-backup-pro' ),
	'database' => __( 'Database only', 'maca-backup-pro' ),
	'files'    => __( 'Files only', 'maca-backup-pro' ),
);
?>
<section class="maca-bp-panel maca-bp-onboarding" id="maca-bp-onboarding" data-storage-url="<?php echo esc_url( $storage_url ); ?>">
	<div class="maca-bp-onboarding__body">
		<p class="maca-bp-onboarding__eyebrow"><?php esc_html_e( 'Getting started', 'maca-backup-pro' ); ?></p>
		<h2 class="maca-bp-onboarding__title"><?php esc_html_e( 'Welcome to maca BackUp', 'maca-backup-pro' ); ?></h2>
		<p class="maca-bp-onboarding__copy">
			<?php esc_html_e( 'A few quick steps to protect your site — choose what to back up, where to store it, and when to run.', 'maca-backup-pro' ); ?>
			<?php
			echo ' ';
			echo wp_kses(
				sprintf(
					/* translators: %s: Help tab URL */
					__( 'Full walkthrough: <a href="%s">Help</a>.', 'maca-backup-pro' ),
					esc_url( Maca_Backup_Pro_Admin::tab_url( 'help' ) )
				),
				array( 'a' => array( 'href' => true ) )
			);
			?>
		</p>

		<ul class="maca-bp-wizard__steps" aria-label="<?php esc_attr_e( 'Setup steps', 'maca-backup-pro' ); ?>">
			<li class="maca-bp-wizard__step is-active" data-step-indicator="1"><span class="maca-bp-wizard__step-num" aria-hidden="true">1</span><span class="maca-bp-wizard__step-label"><?php esc_html_e( 'Type', 'maca-backup-pro' ); ?></span></li>
			<li class="maca-bp-wizard__step" data-step-indicator="2"><span class="maca-bp-wizard__step-num" aria-hidden="true">2</span><span class="maca-bp-wizard__step-label"><?php esc_html_e( 'Storage', 'maca-backup-pro' ); ?></span></li>
			<li class="maca-bp-wizard__step" data-step-indicator="3"><span class="maca-bp-wizard__step-num" aria-hidden="true">3</span><span class="maca-bp-wizard__step-label"><?php esc_html_e( 'When', 'maca-backup-pro' ); ?></span></li>
			<li class="maca-bp-wizard__step" data-step-indicator="4"><span class="maca-bp-wizard__step-num" aria-hidden="true">4</span><span class="maca-bp-wizard__step-label"><?php esc_html_e( 'Confirm', 'maca-backup-pro' ); ?></span></li>
		</ul>

		<div class="maca-bp-wizard" id="maca-bp-wizard">
			<!-- Step 1: Backup type -->
			<div class="maca-bp-wizard__panel is-active" data-step="1" role="group" aria-labelledby="maca-bp-wiz-type-title">
				<h3 id="maca-bp-wiz-type-title" class="maca-bp-wizard__heading"><?php esc_html_e( 'What should we back up?', 'maca-backup-pro' ); ?></h3>
				<p class="maca-bp-wizard__hint"><?php esc_html_e( 'Full backups include the database and files. You can change this later.', 'maca-backup-pro' ); ?></p>
				<div class="maca-bp-wizard__choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Backup type', 'maca-backup-pro' ); ?>">
					<label class="maca-bp-wizard__choice is-active">
						<input type="radio" name="maca_ob_type" value="full" checked />
						<span class="maca-bp-wizard__choice-title"><?php echo esc_html( $type_labels['full'] ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Database + files — recommended', 'maca-backup-pro' ); ?></span>
					</label>
					<label class="maca-bp-wizard__choice">
						<input type="radio" name="maca_ob_type" value="database" />
						<span class="maca-bp-wizard__choice-title"><?php echo esc_html( $type_labels['database'] ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Tables and content only', 'maca-backup-pro' ); ?></span>
					</label>
					<label class="maca-bp-wizard__choice">
						<input type="radio" name="maca_ob_type" value="files" />
						<span class="maca-bp-wizard__choice-title"><?php echo esc_html( $type_labels['files'] ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Themes, plugins, uploads', 'maca-backup-pro' ); ?></span>
					</label>
				</div>
			</div>

			<!-- Step 2: Storage -->
			<div class="maca-bp-wizard__panel" data-step="2" role="group" aria-labelledby="maca-bp-wiz-storage-title" hidden>
				<h3 id="maca-bp-wiz-storage-title" class="maca-bp-wizard__heading"><?php esc_html_e( 'Where should backups be stored?', 'maca-backup-pro' ); ?></h3>
				<p class="maca-bp-wizard__hint"><?php esc_html_e( 'Local storage works out of the box. Cloud destinations must be configured first.', 'maca-backup-pro' ); ?></p>
				<div class="maca-bp-wizard__choices maca-bp-wizard__choices--storage" role="radiogroup" aria-label="<?php esc_attr_e( 'Storage provider', 'maca-backup-pro' ); ?>">
					<?php foreach ( $providers as $prov ) : ?>
						<?php
						$pid         = (string) ( $prov['id'] ?? '' );
						$plabel      = (string) ( $prov['label'] ?? $pid );
						$configured  = ! empty( $prov['configured'] );
						$is_current  = $pid === $current_storage;
						$needs_setup = ! $configured && 'local' !== $pid;
						?>
						<label class="maca-bp-wizard__choice<?php echo $is_current ? ' is-active' : ''; ?><?php echo $needs_setup ? ' maca-bp-wizard__choice--needs-setup' : ''; ?>">
							<input
								type="radio"
								name="maca_ob_storage"
								value="<?php echo esc_attr( $pid ); ?>"
								data-configured="<?php echo $configured ? '1' : '0'; ?>"
								<?php checked( $is_current ); ?>
							/>
							<span class="maca-bp-wizard__choice-title"><?php echo esc_html( $plabel ); ?></span>
							<span class="maca-bp-wizard__choice-hint">
								<?php
								if ( $configured || 'local' === $pid ) {
									esc_html_e( 'Ready to use', 'maca-backup-pro' );
								} else {
									esc_html_e( 'Not configured yet', 'maca-backup-pro' );
								}
								?>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="maca-bp-wizard__storage-hint" id="maca-bp-wiz-storage-hint" hidden>
					<?php esc_html_e( 'This destination is not configured yet.', 'maca-backup-pro' ); ?>
					<a href="<?php echo esc_url( $storage_url ); ?>"><?php esc_html_e( 'Open Storage settings', 'maca-backup-pro' ); ?></a>
				</p>
			</div>

			<!-- Step 3: Schedule / run now -->
			<div class="maca-bp-wizard__panel" data-step="3" role="group" aria-labelledby="maca-bp-wiz-when-title" hidden>
				<h3 id="maca-bp-wiz-when-title" class="maca-bp-wizard__heading"><?php esc_html_e( 'When should we run?', 'maca-backup-pro' ); ?></h3>
				<p class="maca-bp-wizard__hint"><?php esc_html_e( 'Run a backup now, set a simple schedule, or do both.', 'maca-backup-pro' ); ?></p>
				<div class="maca-bp-wizard__choices" role="radiogroup" aria-label="<?php esc_attr_e( 'Run mode', 'maca-backup-pro' ); ?>">
					<label class="maca-bp-wizard__choice is-active">
						<input type="radio" name="maca_ob_mode" value="now" checked />
						<span class="maca-bp-wizard__choice-title"><?php esc_html_e( 'Run once now', 'maca-backup-pro' ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Start a backup immediately', 'maca-backup-pro' ); ?></span>
					</label>
					<label class="maca-bp-wizard__choice">
						<input type="radio" name="maca_ob_mode" value="schedule" />
						<span class="maca-bp-wizard__choice-title"><?php esc_html_e( 'Schedule only', 'maca-backup-pro' ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Automatic backups on a schedule', 'maca-backup-pro' ); ?></span>
					</label>
					<label class="maca-bp-wizard__choice">
						<input type="radio" name="maca_ob_mode" value="both" />
						<span class="maca-bp-wizard__choice-title"><?php esc_html_e( 'Schedule + run now', 'maca-backup-pro' ); ?></span>
						<span class="maca-bp-wizard__choice-hint"><?php esc_html_e( 'Save a schedule and start immediately', 'maca-backup-pro' ); ?></span>
					</label>
				</div>

				<div class="maca-bp-wizard__schedule" id="maca-bp-wiz-schedule" hidden>
					<div class="maca-bp-wizard__schedule-row">
						<label class="maca-bp-field">
							<span><?php esc_html_e( 'Frequency', 'maca-backup-pro' ); ?></span>
							<select name="maca_ob_frequency" id="maca-bp-wiz-frequency">
								<option value="daily"><?php esc_html_e( 'Daily', 'maca-backup-pro' ); ?></option>
								<option value="weekly"><?php esc_html_e( 'Weekly', 'maca-backup-pro' ); ?></option>
							</select>
						</label>
						<label class="maca-bp-field" id="maca-bp-wiz-weekday-wrap" hidden>
							<span><?php esc_html_e( 'Day', 'maca-backup-pro' ); ?></span>
							<select name="maca_ob_weekday" id="maca-bp-wiz-weekday">
								<?php foreach ( $weekdays as $w => $wlabel ) : ?>
									<option value="<?php echo esc_attr( (string) $w ); ?>" <?php selected( $weekday, $w ); ?>><?php echo esc_html( $wlabel ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="maca-bp-field">
							<span><?php esc_html_e( 'Hour', 'maca-backup-pro' ); ?></span>
							<select name="maca_ob_hour" id="maca-bp-wiz-hour">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo esc_attr( sprintf( '%02d', $h ) ); ?>" <?php selected( $local_h, $h ); ?>><?php echo esc_html( sprintf( '%02d', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
						</label>
						<label class="maca-bp-field">
							<span><?php esc_html_e( 'Minute', 'maca-backup-pro' ); ?></span>
							<select name="maca_ob_minute" id="maca-bp-wiz-minute">
								<?php for ( $m = 0; $m < 60; $m += 5 ) : ?>
									<option value="<?php echo esc_attr( sprintf( '%02d', $m ) ); ?>" <?php selected( $local_m, $m ); ?>><?php echo esc_html( sprintf( '%02d', $m ) ); ?></option>
								<?php endfor; ?>
							</select>
						</label>
					</div>
					<p class="maca-bp-muted maca-bp-wizard__tz">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: WordPress site timezone */
								__( 'Times use your site timezone (%s).', 'maca-backup-pro' ),
								$tz_label
							)
						);
						?>
					</p>
				</div>
			</div>

			<!-- Step 4: Confirm -->
			<div class="maca-bp-wizard__panel" data-step="4" role="group" aria-labelledby="maca-bp-wiz-confirm-title" hidden>
				<h3 id="maca-bp-wiz-confirm-title" class="maca-bp-wizard__heading"><?php esc_html_e( 'Confirm your setup', 'maca-backup-pro' ); ?></h3>
				<p class="maca-bp-wizard__hint"><?php esc_html_e( 'Review the choices below, then finish to apply them.', 'maca-backup-pro' ); ?></p>
				<dl class="maca-bp-wizard__summary" id="maca-bp-wiz-summary">
					<div>
						<dt><?php esc_html_e( 'Backup type', 'maca-backup-pro' ); ?></dt>
						<dd data-summary="type">—</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'Storage', 'maca-backup-pro' ); ?></dt>
						<dd data-summary="storage">—</dd>
					</div>
					<div>
						<dt><?php esc_html_e( 'When', 'maca-backup-pro' ); ?></dt>
						<dd data-summary="when">—</dd>
					</div>
				</dl>
				<p class="maca-bp-wizard__error" id="maca-bp-wiz-error" hidden></p>
			</div>
		</div>

		<div class="maca-bp-wizard__nav">
			<button type="button" class="button" id="maca-bp-wiz-back" hidden><?php esc_html_e( 'Back', 'maca-backup-pro' ); ?></button>
			<button type="button" class="button button-primary" id="maca-bp-wiz-next"><?php esc_html_e( 'Continue', 'maca-backup-pro' ); ?></button>
			<button type="button" class="button button-primary" id="maca-bp-wiz-finish" hidden><?php esc_html_e( 'Finish', 'maca-backup-pro' ); ?></button>
			<form method="post" class="maca-bp-onboarding__dismiss">
				<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
				<input type="hidden" name="maca_backup_pro_action" value="dismiss_onboarding" />
				<button type="submit" class="button-link maca-bp-onboarding__later">
					<?php esc_html_e( 'Skip for now', 'maca-backup-pro' ); ?>
				</button>
			</form>
		</div>
	</div>
</section>
<?php
// Labels for JS summary (JSON in data attribute avoids extra localize).
$type_js = array();
foreach ( $type_labels as $k => $v ) {
	$type_js[ $k ] = $v;
}
$prov_js = array();
foreach ( $providers as $prov ) {
	$prov_js[ (string) $prov['id'] ] = (string) $prov['label'];
}
?>
<script type="application/json" id="maca-bp-onboarding-i18n">
<?php
echo wp_json_encode(
	array(
		'types'      => $type_js,
		'providers'  => $prov_js,
		'modes'      => array(
			'now'      => __( 'Run once now', 'maca-backup-pro' ),
			'schedule' => __( 'Schedule only', 'maca-backup-pro' ),
			'both'     => __( 'Schedule + run now', 'maca-backup-pro' ),
		),
		'daily'      => __( 'Daily', 'maca-backup-pro' ),
		'weekly'     => __( 'Weekly', 'maca-backup-pro' ),
		'weekdays'   => $weekdays,
		'at'         => __( 'at', 'maca-backup-pro' ),
		'configureFirst' => __( 'Configure this storage destination first, or choose Local storage.', 'maca-backup-pro' ),
		'finishing'  => __( 'Applying…', 'maca-backup-pro' ),
		'finishFail' => __( 'Could not finish setup. Please try again.', 'maca-backup-pro' ),
	)
);
?>
</script>
