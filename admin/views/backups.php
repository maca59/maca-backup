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
		<h2><?php esc_html_e( 'All backups', 'maca-backup' ); ?></h2>
		<div class="maca-bp-actions">
			<button type="button" class="button button-primary maca-bp-btn" data-type="full"><?php esc_html_e( 'New full backup', 'maca-backup' ); ?></button>
		</div>
	</div>
	<div class="maca-bp-import">
		<h3><?php esc_html_e( 'Import backup', 'maca-backup' ); ?></h3>
		<p class="maca-bp-muted"><?php esc_html_e( 'Upload a downloaded maca backup to this site, then restore it from the Restore tab.', 'maca-backup' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="maca-bp-import-form">
			<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
			<input type="hidden" name="maca_backup_pro_action" value="import_backup" />
			<input type="file" name="backup_file" accept=".zip,.enc,application/zip" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import', 'maca-backup' ); ?></button>
		</form>
		<p class="maca-bp-muted"><?php
		echo esc_html(
			sprintf(
				/* translators: 1: PHP direct upload limit, 2: max import size via chunked upload */
				__( 'Direct upload limit: %1$s. Larger archives (up to %2$s) upload automatically in chunks.', 'maca-backup' ),
				size_format( Maca_Backup_Pro_Importer::direct_upload_limit() ),
				size_format( Maca_Backup_Pro_Importer::max_import_bytes() )
			)
		);
		?></p>
		<div class="maca-bp-import-progress-slot" aria-live="polite"></div>
	</div>

	<?php
	$completed = array_values(
		array_filter(
			is_array( $history ) ? $history : array(),
			static fn( $row ) => is_object( $row ) && 'completed' === (string) ( $row->status ?? '' )
		)
	);
	?>
	<?php if ( count( $completed ) >= 2 ) : ?>
		<div class="maca-bp-compare">
			<h3><?php esc_html_e( 'Compare backups', 'maca-backup' ); ?></h3>
			<p class="maca-bp-muted"><?php esc_html_e( 'Diff file lists between two archives — useful when sizes look wrong (e.g. 1 GB vs 6 GB).', 'maca-backup' ); ?></p>
			<div class="maca-bp-compare__controls">
				<label>
					<span><?php esc_html_e( 'Backup A', 'maca-backup' ); ?></span>
					<select id="maca-bp-compare-a">
						<?php foreach ( $completed as $row ) : ?>
							<option value="<?php echo esc_attr( (string) $row->id ); ?>">
								<?php
								echo esc_html(
									Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at )
									. ' — ' . (string) $row->type
									. ' (' . size_format( (int) $row->size_bytes ) . ', '
									. (int) $row->file_count . ' '
									. __( 'files', 'maca-backup' ) . ')'
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Backup B', 'maca-backup' ); ?></span>
					<select id="maca-bp-compare-b">
						<?php foreach ( $completed as $i => $row ) : ?>
							<option value="<?php echo esc_attr( (string) $row->id ); ?>" <?php selected( $i, 1 ); ?>>
								<?php
								echo esc_html(
									Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at )
									. ' — ' . (string) $row->type
									. ' (' . size_format( (int) $row->size_bytes ) . ', '
									. (int) $row->file_count . ' '
									. __( 'files', 'maca-backup' ) . ')'
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button button-primary" id="maca-bp-compare-run"><?php esc_html_e( 'Compare', 'maca-backup' ); ?></button>
			</div>
			<div id="maca-bp-compare-result" class="maca-bp-compare__result" hidden></div>
		</div>
	<?php endif; ?>

	<?php if ( empty( $history ) ) : ?>
		<p class="maca-bp-muted"><?php esc_html_e( 'No backups yet.', 'maca-backup' ); ?></p>
	<?php else : ?>
		<table class="widefat striped maca-bp-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Type', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Storage', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Size', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'CRC32', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Files', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Time', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Status', 'maca-backup' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'maca-backup' ); ?></th>
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
						<td><?php echo esc_html( (string) $row->file_count ); ?></td>
						<td><?php echo esc_html( Maca_Backup_Pro_Format::duration( (int) $row->duration ) ); ?></td>
						<td><span class="maca-bp-pill maca-bp-pill--<?php echo esc_attr( (string) $row->status ); ?>"><?php echo esc_html( (string) $row->status ); ?></span></td>
						<td class="maca-bp-row-actions">
							<?php if ( 'completed' === (string) $row->status ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::download_url( (int) $row->id ) ); ?>" title="<?php esc_attr_e( 'Download to your computer', 'maca-backup' ); ?>"><?php esc_html_e( 'Download', 'maca-backup' ); ?></a>
								<button type="button" class="button button-small maca-bp-test-restore" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Test', 'maca-backup' ); ?></button>
								<a class="button button-small" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'restore', array( 'backup_id' => (int) $row->id ) ) ); ?>"><?php esc_html_e( 'Restore', 'maca-backup' ); ?></a>
							<?php endif; ?>
							<button type="button" class="button button-small maca-bp-delete" data-id="<?php echo esc_attr( (string) $row->id ); ?>"><?php esc_html_e( 'Delete', 'maca-backup' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</section>
