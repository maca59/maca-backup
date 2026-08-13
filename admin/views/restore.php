<?php
/**
 * Restore view — same-site disaster recovery.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $history
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$selected    = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$backups_url = Maca_Backup_Pro_Admin::tab_url( 'backups' );
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Restore', 'maca-backup' ); ?></h2>
	<p class="maca-bp-muted"><?php esc_html_e( 'Restore a backup of this site (disaster recovery). Choose a scope, preview what will be overwritten, or run a test restore first.', 'maca-backup' ); ?></p>
	<p class="maca-bp-muted"><?php
	echo wp_kses(
		sprintf(
			/* translators: %s: Backups tab URL */
			__( 'Import a downloaded archive from the <a href="%s">Backups</a> tab, then restore it here.', 'maca-backup' ),
			esc_url( $backups_url )
		),
		array( 'a' => array( 'href' => true ) )
	);
	?></p>

	<div id="maca-bp-job-progress-slot" class="maca-bp-job-progress-slot" aria-live="polite"></div>

	<div class="maca-bp-form-grid">
		<label>
			<span><?php esc_html_e( 'Backup', 'maca-backup' ); ?></span>
			<select id="maca-bp-restore-backup">
				<option value=""><?php esc_html_e( 'Select…', 'maca-backup' ); ?></option>
				<?php if ( empty( $history ) ) : ?>
					<option value="" disabled><?php esc_html_e( 'No completed backups available', 'maca-backup' ); ?></option>
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
			<span><?php esc_html_e( 'Scope', 'maca-backup' ); ?></span>
			<select id="maca-bp-restore-scope">
				<option value="full"><?php esc_html_e( 'Entire site', 'maca-backup' ); ?></option>
				<option value="database"><?php esc_html_e( 'Database only', 'maca-backup' ); ?></option>
				<option value="wp-content"><?php esc_html_e( 'wp-content', 'maca-backup' ); ?></option>
				<option value="uploads"><?php esc_html_e( 'Uploads', 'maca-backup' ); ?></option>
				<option value="plugins"><?php esc_html_e( 'Plugins', 'maca-backup' ); ?></option>
				<option value="themes"><?php esc_html_e( 'Themes', 'maca-backup' ); ?></option>
				<option value="path"><?php esc_html_e( 'Custom path (file or folder)', 'maca-backup' ); ?></option>
			</select>
		</label>
	</div>

	<div id="maca-bp-restore-tree-wrap" class="maca-bp-tree-wrap" hidden>
		<p class="maca-bp-muted"><?php esc_html_e( 'Browse the backup and select files or folders to restore.', 'maca-backup' ); ?></p>
		<div id="maca-bp-restore-tree" class="maca-bp-tree" data-tree="restore"></div>
		<p class="maca-bp-muted maca-bp-tree-selected" id="maca-bp-restore-selected-count"></p>
	</div>

	<div class="maca-bp-actions" style="margin-top:1rem;">
		<button type="button" class="button" id="maca-bp-test-restore"><?php esc_html_e( 'Test restore', 'maca-backup' ); ?></button>
		<button type="button" class="button" id="maca-bp-preview-restore"><?php esc_html_e( 'Preview changes', 'maca-backup' ); ?></button>
		<button type="button" class="button button-primary" id="maca-bp-run-restore"><?php esc_html_e( 'Restore now', 'maca-backup' ); ?></button>
	</div>

	<div id="maca-bp-preview-box" class="maca-bp-preview" hidden></div>
</section>
