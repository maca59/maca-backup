<?php
/**
 * Backups list view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $history
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.
?>
<section class="maca-bp-panel">
	<div class="maca-bp-panel__head">
		<h2><?php esc_html_e( 'All backups', 'maca-backup-pro' ); ?></h2>
		<div class="maca-bp-actions">
			<button type="button" class="button button-primary maca-bp-btn" data-type="full"><?php esc_html_e( 'New full backup', 'maca-backup-pro' ); ?></button>
		</div>
	</div>
	<div id="maca-bp-progress" class="maca-bp-progress" hidden>
		<div class="maca-bp-progress__head">
			<div class="maca-bp-progress__bar"><span style="width:0%"></span></div>
			<button type="button" class="button maca-bp-progress__stop" hidden>
				<?php esc_html_e( 'Stop', 'maca-backup-pro' ); ?>
			</button>
		</div>
		<p class="maca-bp-progress__label"></p>
		<p class="maca-bp-progress__detail" aria-live="polite"></p>
		<p class="maca-bp-progress__note" hidden><?php esc_html_e( 'Runs in the background — you can leave this page.', 'maca-backup-pro' ); ?></p>
	</div>

	<div class="maca-bp-import">
		<h3><?php esc_html_e( 'Import backup', 'maca-backup-pro' ); ?></h3>
		<p class="maca-bp-muted"><?php esc_html_e( 'Upload a downloaded maca backup to this site (e.g. after changing host). Then restore it from the Restore tab.', 'maca-backup-pro' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="maca-bp-import-form">
			<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
			<input type="hidden" name="maca_backup_pro_action" value="import_backup" />
			<input type="file" name="backup_file" accept=".zip,.enc,application/zip" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import', 'maca-backup-pro' ); ?></button>
		</form>
		<p class="maca-bp-muted"><?php
		echo esc_html(
			sprintf(
				/* translators: %s: max upload size */
				__( 'Max upload size: %s', 'maca-backup-pro' ),
				size_format( wp_max_upload_size() )
			)
		);
		?></p>
	</div>

	<?php if ( empty( $history ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No backups yet.', 'maca-backup-pro' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Type', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Storage', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Size', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Files', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Time', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'maca-backup-pro' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'maca-backup-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr>
						<td><?php echo esc_html( Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at ) ); ?></td>
						<td>
							<?php
							echo esc_html( (string) $row->type );
							$parent = (int) ( $row->parent_backup_id ?? 0 );
							if ( $parent > 0 ) {
								echo ' <span class="maca-bp-muted">#' . esc_html( (string) $parent ) . '</span>';
							}
							?>
						</td>
						<td><?php echo esc_html( (string) $row->storage ); ?></td>
						<td><?php echo esc_html( size_format( (int) $row->size_bytes ) ); ?></td>
						<td><?php echo esc_html( (string) $row->file_count ); ?></td>
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
							<?php endif; ?>
							<button type="button" class="button button-small maca-bp-delete" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'maca-backup-pro' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
