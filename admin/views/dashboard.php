<?php
/**
 * Dashboard view.
 *
 * @package Maca_Backup_Pro
 *
 * @var object|null $latest
 * @var int         $count
 * @var int         $total_size
 * @var string      $storage
 * @var array|null  $space
 * @var int|null    $next_backup
 * @var array       $history
 * @var object|null $active_job
 * @var bool        $show_onboarding
 * @var string      $current_storage     (when onboarding)
 * @var array       $providers           (when onboarding)
 * @var string      $storage_url         (when onboarding)
 * @var array       $schedule_defaults   (when onboarding)
 * @var string      $tz_label            (when onboarding)
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$show_onboarding = ! empty( $show_onboarding );
?>
<?php
if ( $show_onboarding ) {
	include __DIR__ . '/onboarding-wizard.php';
}
?>

<p class="maca-bp-muted maca-bp-help-link">
	<?php
	echo wp_kses(
		sprintf(
			/* translators: %s: Help tab URL */
			__( 'New here? See the <a href="%s">Help</a> guide for backups, schedules, restore, and more.', 'maca-backup-pro' ),
			esc_url( Maca_Backup_Pro_Admin::tab_url( 'help' ) )
		),
		array( 'a' => array( 'href' => true ) )
	);
	?>
</p>

<div class="maca-bp-actions">
	<button type="button" class="button button-primary maca-bp-btn" id="maca-bp-start-full" data-type="full">
		<?php esc_html_e( 'Create full backup', 'maca-backup-pro' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" data-type="incremental">
		<?php esc_html_e( 'Incremental', 'maca-backup-pro' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" data-type="differential">
		<?php esc_html_e( 'Differential', 'maca-backup-pro' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" id="maca-bp-start-db" data-type="database">
		<?php esc_html_e( 'Database only', 'maca-backup-pro' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" id="maca-bp-start-files" data-type="files">
		<?php esc_html_e( 'Files only', 'maca-backup-pro' ); ?>
	</button>
</div>

<div id="maca-bp-progress" class="maca-bp-progress" hidden>
	<div class="maca-bp-progress__head">
		<div class="maca-bp-progress__bar"><span style="width:0%"></span></div>
		<button type="button" class="button maca-bp-progress__stop" hidden>
			<?php esc_html_e( 'Stop', 'maca-backup-pro' ); ?>
		</button>
	</div>
	<p class="maca-bp-progress__label"><?php esc_html_e( 'Preparing…', 'maca-backup-pro' ); ?></p>
	<p class="maca-bp-progress__detail" aria-live="polite"></p>
	<p class="maca-bp-progress__note" hidden><?php esc_html_e( 'Runs in the background — you can leave this page.', 'maca-backup-pro' ); ?></p>
</div>

<div class="maca-bp-grid maca-bp-grid--stats">
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Last backup', 'maca-backup-pro' ); ?></span>
		<strong class="maca-bp-card__value">
			<?php echo $latest ? esc_html( Maca_Backup_Pro_Format::datetime_local( (string) $latest->finished_at ) ) : esc_html__( 'Never', 'maca-backup-pro' ); ?>
		</strong>
		<span class="maca-bp-card__hint">
			<?php
			if ( $latest ) {
				echo esc_html( (string) $latest->type . ' · ' . size_format( (int) $latest->size_bytes ) );
			}
			?>
		</span>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Status', 'maca-backup-pro' ); ?></span>
		<strong class="maca-bp-card__value">
			<?php
			if ( $active_job ) {
				echo esc_html( sprintf( '%s (%d%%)', (string) $active_job->status, (int) $active_job->progress ) );
			} elseif ( $latest && 'completed' === $latest->status ) {
				esc_html_e( 'OK', 'maca-backup-pro' );
			} else {
				esc_html_e( 'Idle', 'maca-backup-pro' );
			}
			?>
		</strong>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Next backup', 'maca-backup-pro' ); ?></span>
		<strong class="maca-bp-card__value">
			<?php
			echo $next_backup
				? esc_html( wp_date( 'Y-m-d H:i', $next_backup ) )
				: esc_html__( 'Manual only', 'maca-backup-pro' );
			?>
		</strong>
		<?php if ( $next_backup ) : ?>
			<span class="maca-bp-card__hint"><?php echo esc_html( wp_timezone()->getName() ); ?></span>
		<?php endif; ?>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Saved backups', 'maca-backup-pro' ); ?></span>
		<strong class="maca-bp-card__value"><?php echo esc_html( (string) $count ); ?></strong>
		<span class="maca-bp-card__hint"><?php echo esc_html( size_format( (int) $total_size ) ); ?></span>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Storage', 'maca-backup-pro' ); ?></span>
		<strong class="maca-bp-card__value"><?php echo esc_html( $storage ); ?></strong>
		<span class="maca-bp-card__hint">
			<?php
			if ( is_array( $space ) && isset( $space['free'] ) ) {
				echo esc_html(
					sprintf(
						/* translators: %s: free disk space amount */
						__( '%s free', 'maca-backup-pro' ),
						size_format( (int) $space['free'] )
					)
				);
			}
			?>
		</span>
	</div>
</div>

<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'History', 'maca-backup-pro' ); ?></h2>
	<?php if ( empty( $history ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No backups yet. Create your first backup above.', 'maca-backup-pro' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Type', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Size', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Time', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'maca-backup-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr data-backup-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<td><?php echo esc_html( Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at ) ); ?></td>
						<td><?php echo esc_html( (string) $row->type ); ?></td>
						<td><?php echo esc_html( size_format( (int) $row->size_bytes ) ); ?></td>
						<td><?php echo esc_html( Maca_Backup_Pro_Format::duration( (int) $row->duration ) ); ?></td>
						<td><span class="maca-bp-pill maca-bp-pill--<?php echo esc_attr( (string) $row->status ); ?>"><?php echo esc_html( (string) $row->status ); ?></span></td>
						<td class="maca-bp-row-actions">
							<?php if ( 'completed' === (string) $row->status ) : ?>
								<form method="post" class="maca-bp-inline-form">
									<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
									<input type="hidden" name="maca_backup_pro_action" value="download_backup" />
									<input type="hidden" name="backup_id" value="<?php echo esc_attr( (string) $row->id ); ?>" />
									<button type="submit" class="button button-small" title="<?php esc_attr_e( 'Download to your computer', 'maca-backup-pro' ); ?>"><?php esc_html_e( 'Download', 'maca-backup-pro' ); ?></button>
								</form>
								<button type="button" class="button button-small maca-bp-test-restore" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Test', 'maca-backup-pro' ); ?></button>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'restore', array( 'backup_id' => (int) $row->id ) ) ); ?>"><?php esc_html_e( 'Restore', 'maca-backup-pro' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'logs' ) ); ?>"><?php esc_html_e( 'Log', 'maca-backup-pro' ); ?></a>
							<?php endif; ?>
							<button type="button" class="button button-small maca-bp-delete" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'maca-backup-pro' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
