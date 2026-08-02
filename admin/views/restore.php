<?php
/**
 * Restore view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $history
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$selected = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Restore', 'maca-backup-pro' ); ?></h2>
	<p class="maca-bp-muted"><?php esc_html_e( 'Choose a backup and scope. Use Custom path to restore a single file or folder. A preview shows exactly what will be overwritten before you confirm. Test restore verifies the archive in a temporary folder without changing the live site.', 'maca-backup-pro' ); ?></p>

	<div class="maca-bp-import">
		<h3><?php esc_html_e( 'Import from computer', 'maca-backup-pro' ); ?></h3>
		<p class="maca-bp-muted"><?php esc_html_e( 'Moving hosts? Download a backup on the old site, then import the ZIP here and restore.', 'maca-backup-pro' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="maca-bp-import-form">
			<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
			<input type="hidden" name="maca_backup_pro_action" value="import_backup" />
			<input type="file" name="backup_file" accept=".zip,.enc,application/zip" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import backup', 'maca-backup-pro' ); ?></button>
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

	<div class="maca-bp-form-grid">
		<label>
			<span><?php esc_html_e( 'Backup', 'maca-backup-pro' ); ?></span>
			<select id="maca-bp-restore-backup">
				<option value=""><?php esc_html_e( 'Select…', 'maca-backup-pro' ); ?></option>
				<?php if ( empty( $history ) ) : ?>
					<option value="" disabled><?php esc_html_e( 'No completed backups available', 'maca-backup-pro' ); ?></option>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<option value="<?php echo esc_attr( (string) $row->id ); ?>" <?php selected( $selected, (int) $row->id ); ?>>
							<?php
							$label_date = Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at );
							echo esc_html(
								$label_date . ' — ' . (string) $row->type . ' (' . size_format( (int) $row->size_bytes ) . ')'
							);
							?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
		</label>
		<label>
			<span><?php esc_html_e( 'Scope', 'maca-backup-pro' ); ?></span>
			<select id="maca-bp-restore-scope">
				<option value="full"><?php esc_html_e( 'Entire site', 'maca-backup-pro' ); ?></option>
				<option value="database"><?php esc_html_e( 'Database only', 'maca-backup-pro' ); ?></option>
				<option value="wp-content"><?php esc_html_e( 'wp-content', 'maca-backup-pro' ); ?></option>
				<option value="uploads"><?php esc_html_e( 'Uploads', 'maca-backup-pro' ); ?></option>
				<option value="plugins"><?php esc_html_e( 'Plugins', 'maca-backup-pro' ); ?></option>
				<option value="themes"><?php esc_html_e( 'Themes', 'maca-backup-pro' ); ?></option>
				<option value="path"><?php esc_html_e( 'Custom path (file or folder)', 'maca-backup-pro' ); ?></option>
			</select>
		</label>
	</div>

	<div id="maca-bp-restore-tree-wrap" class="maca-bp-tree-wrap" hidden>
		<p class="maca-bp-muted"><?php esc_html_e( 'Browse the backup and select files or folders to restore.', 'maca-backup-pro' ); ?></p>
		<div id="maca-bp-restore-tree" class="maca-bp-tree" data-tree="restore"></div>
		<p class="maca-bp-muted maca-bp-tree-selected" id="maca-bp-restore-selected-count"></p>
	</div>

	<div class="maca-bp-actions" style="margin-top:1rem;">
		<button type="button" class="button" id="maca-bp-test-restore"><?php esc_html_e( 'Test restore', 'maca-backup-pro' ); ?></button>
		<button type="button" class="button" id="maca-bp-preview-restore"><?php esc_html_e( 'Preview changes', 'maca-backup-pro' ); ?></button>
		<button type="button" class="button button-primary" id="maca-bp-run-restore"><?php esc_html_e( 'Restore now', 'maca-backup-pro' ); ?></button>
	</div>

	<div id="maca-bp-preview-box" class="maca-bp-preview" hidden></div>
	<div id="maca-bp-progress" class="maca-bp-progress" hidden>
		<div class="maca-bp-progress__head">
			<div class="maca-bp-progress__bar"><span style="width:0%"></span></div>
			<button type="button" class="button maca-bp-progress__stop" hidden>
				<?php esc_html_e( 'Stop', 'maca-backup-pro' ); ?>
			</button>
		</div>
		<p class="maca-bp-progress__label"></p>
		<p class="maca-bp-progress__elapsed" aria-live="off"></p>
		<p class="maca-bp-progress__detail" aria-live="polite"></p>
		<p class="maca-bp-progress__note" hidden><?php esc_html_e( 'Runs in the background — you can leave this page.', 'maca-backup-pro' ); ?></p>
	</div>
</section>
