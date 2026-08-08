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
			__( 'New here? See the <a href="%s">Help</a> guide for backups, schedules, restore, and more.', 'maca-backup' ),
			esc_url( Maca_Backup_Pro_Admin::tab_url( 'help' ) )
		),
		array( 'a' => array( 'href' => true ) )
	);
	?>
</p>

<div class="maca-bp-actions">
	<button type="button" class="button button-primary maca-bp-btn" id="maca-bp-start-full" data-type="full">
		<?php esc_html_e( 'Create full backup', 'maca-backup' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" data-type="incremental">
		<?php esc_html_e( 'Incremental', 'maca-backup' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" data-type="differential">
		<?php esc_html_e( 'Differential', 'maca-backup' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" id="maca-bp-start-db" data-type="database">
		<?php esc_html_e( 'Database only', 'maca-backup' ); ?>
	</button>
	<button type="button" class="button maca-bp-btn" id="maca-bp-start-files" data-type="files">
		<?php esc_html_e( 'Files only', 'maca-backup' ); ?>
	</button>
</div>

<div class="maca-bp-grid maca-bp-grid--stats">
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Last backup', 'maca-backup' ); ?></span>
		<strong class="maca-bp-card__value">
			<?php echo $latest ? esc_html( Maca_Backup_Pro_Format::datetime_local( (string) $latest->finished_at ) ) : esc_html__( 'Never', 'maca-backup' ); ?>
		</strong>
		<span class="maca-bp-card__hint">
			<?php
			if ( $latest ) {
				echo esc_html( (string) $latest->type . ' · ' . size_format( (int) $latest->size_bytes ) );
			}
			?>
		</span>
	</div>
	<div class="maca-bp-card" id="maca-bp-status-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Status', 'maca-backup' ); ?></span>
		<strong class="maca-bp-card__value" id="maca-bp-status-value">
			<?php
			if ( $active_job ) {
				echo esc_html( sprintf( '%s (%d%%)', (string) $active_job->status, (int) $active_job->progress ) );
			} elseif ( $latest && 'completed' === $latest->status ) {
				esc_html_e( 'OK', 'maca-backup' );
			} else {
				esc_html_e( 'Idle', 'maca-backup' );
			}
			?>
		</strong>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Next backup', 'maca-backup' ); ?></span>
		<strong class="maca-bp-card__value">
			<?php
			echo $next_backup
				? esc_html( wp_date( 'Y-m-d H:i', $next_backup ) )
				: esc_html__( 'Manual only', 'maca-backup' );
			?>
		</strong>
		<?php if ( $next_backup ) : ?>
			<span class="maca-bp-card__hint"><?php echo esc_html( wp_timezone()->getName() ); ?></span>
		<?php endif; ?>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Saved backups', 'maca-backup' ); ?></span>
		<strong class="maca-bp-card__value"><?php echo esc_html( (string) $count ); ?></strong>
		<span class="maca-bp-card__hint"><?php echo esc_html( size_format( (int) $total_size ) ); ?></span>
	</div>
	<div class="maca-bp-card">
		<span class="maca-bp-card__label"><?php esc_html_e( 'Storage', 'maca-backup' ); ?></span>
		<strong class="maca-bp-card__value"><?php echo esc_html( $storage ); ?></strong>
		<span class="maca-bp-card__hint">
			<?php
			if ( is_array( $space ) && isset( $space['free'] ) ) {
				echo esc_html(
					sprintf(
						/* translators: %s: free disk space amount */
						__( '%s free', 'maca-backup' ),
						size_format( (int) $space['free'] )
					)
				);
			}
			?>
		</span>
	</div>
</div>

<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'History', 'maca-backup' ); ?></h2>
	<?php if ( empty( $history ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No backups yet. Create your first backup above.', 'maca-backup' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Type', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Size', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'CRC32', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Time', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Status', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'maca-backup' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr data-backup-id="<?php echo esc_attr( (string) $row->id ); ?>">
						<td><?php echo esc_html( Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at ) ); ?></td>
						<td><?php echo esc_html( (string) $row->type ); ?></td>
						<td><?php echo esc_html( size_format( (int) $row->size_bytes ) ); ?></td>
						<td>
							<?php
							$crc_label = Maca_Backup_Pro_Format::backup_checksum_label( $row );
							$crc_full  = Maca_Backup_Pro_Format::backup_crc(
								(string) ( $row->checksum ?? '' ),
								(array) ( json_decode( (string) ( $row->manifest ?? '' ), true ) ?: array() )
							);
							?>
							<code class="maca-bp-crc" title="<?php echo esc_attr( '' !== $crc_full ? $crc_full : $crc_label ); ?>"><?php echo esc_html( $crc_label ); ?></code>
						</td>
						<td><?php echo esc_html( Maca_Backup_Pro_Format::duration( (int) $row->duration ) ); ?></td>
						<td><span class="maca-bp-pill maca-bp-pill--<?php echo esc_attr( (string) $row->status ); ?>"><?php echo esc_html( (string) $row->status ); ?></span></td>
						<td class="maca-bp-row-actions">
							<?php if ( 'completed' === (string) $row->status ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::download_url( (int) $row->id ) ); ?>" title="<?php esc_attr_e( 'Download to your computer', 'maca-backup' ); ?>"><?php esc_html_e( 'Download', 'maca-backup' ); ?></a>
								<button type="button" class="button button-small maca-bp-test-restore" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Test', 'maca-backup' ); ?></button>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'restore', array( 'backup_id' => (int) $row->id ) ) ); ?>"><?php esc_html_e( 'Restore', 'maca-backup' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'logs' ) ); ?>"><?php esc_html_e( 'Log', 'maca-backup' ); ?></a>
							<?php endif; ?>
							<button type="button" class="button button-small maca-bp-delete" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'maca-backup' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
