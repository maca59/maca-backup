<?php
/**
 * Shared live progress bar (manual + scheduled backups / restores).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

$maca_backup_progress_job = null;
if ( class_exists( 'Maca_Backup_Pro_Jobs_Table', false ) ) {
	$maca_backup_progress_job = Maca_Backup_Pro_Jobs_Table::active_for_ui( 'backup' )
		?: Maca_Backup_Pro_Jobs_Table::active_for_ui( 'restore' );
}

$maca_backup_progress_pct   = $maca_backup_progress_job ? max( 0, min( 100, (int) $maca_backup_progress_job->progress ) ) : 0;
$maca_backup_progress_step  = $maca_backup_progress_job ? (string) $maca_backup_progress_job->step : '';
$maca_backup_progress_label = '';
if ( $maca_backup_progress_job ) {
	$maca_backup_progress_label = $maca_backup_progress_step
		? sprintf( '%s — %d%%', $maca_backup_progress_step, $maca_backup_progress_pct )
		: sprintf(
			/* translators: %d: progress percent */
			__( 'Running — %d%%', 'maca-backup' ),
			$maca_backup_progress_pct
		);

	$maca_backup_progress_state = json_decode( (string) ( $maca_backup_progress_job->state ?? '' ), true );
	if ( is_array( $maca_backup_progress_state ) && ! empty( $maca_backup_progress_state['schedule_id'] ) ) {
		$maca_backup_progress_label = sprintf(
			/* translators: 1: progress label */
			__( 'Scheduled: %s', 'maca-backup' ),
			$maca_backup_progress_label
		);
	}
}

$maca_backup_bar_width = max(
	$maca_backup_progress_pct,
	$maca_backup_progress_job && $maca_backup_progress_pct < 1 ? 2 : $maca_backup_progress_pct
);
?>
<div
	id="maca-bp-progress"
	class="maca-bp-progress<?php echo $maca_backup_progress_job ? ' is-active has-fill' : ''; ?>"
	<?php echo $maca_backup_progress_job ? '' : ' hidden'; ?>
>
	<div class="maca-bp-progress__head">
		<div class="maca-bp-progress__bar"><span style="width:<?php echo esc_attr( (string) $maca_backup_bar_width ); ?>%"></span></div>
		<button type="button" class="button maca-bp-progress__stop"<?php echo $maca_backup_progress_job ? '' : ' hidden'; ?>>
			<?php esc_html_e( 'Stop', 'maca-backup' ); ?>
		</button>
	</div>
	<p class="maca-bp-progress__label"><?php echo esc_html( $maca_backup_progress_label ); ?></p>
	<p class="maca-bp-progress__elapsed" aria-live="off"></p>
	<p class="maca-bp-progress__detail" aria-live="polite"></p>
	<p class="maca-bp-progress__note"<?php echo $maca_backup_progress_job ? '' : ' hidden'; ?>><?php esc_html_e( 'Runs in the background — you can leave this page.', 'maca-backup' ); ?></p>
</div>
