<?php
/**
 * Smart Restore view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $history
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Smart Restore', 'maca-backup' ); ?></h2>
	<p class="maca-bp-muted"><?php esc_html_e( 'Compare the live site with a backup. Restore only new, changed, or selected files — instead of overwriting everything. Or browse the backup and pick a single file or folder.', 'maca-backup' ); ?></p>

	<div class="maca-bp-form-grid">
		<label>
			<span><?php esc_html_e( 'Backup to compare', 'maca-backup' ); ?></span>
			<select id="maca-bp-smart-backup">
				<option value=""><?php esc_html_e( 'Select…', 'maca-backup' ); ?></option>
				<?php if ( empty( $history ) ) : ?>
					<option value="" disabled><?php esc_html_e( 'No completed backups available', 'maca-backup' ); ?></option>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<option value="<?php echo esc_attr( (string) $row->id ); ?>">
							<?php
							$label_date = Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at );
							echo esc_html( $label_date . ' — ' . (string) $row->type . ' (' . size_format( (int) $row->size_bytes ) . ')' );
							?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
		</label>
	</div>

	<div class="maca-bp-actions" style="margin-top:1rem;">
		<button type="button" class="button button-primary" id="maca-bp-smart-compare"><?php esc_html_e( 'Compare', 'maca-backup' ); ?></button>
		<button type="button" class="button" id="maca-bp-smart-browse"><?php esc_html_e( 'Browse backup', 'maca-backup' ); ?></button>
		<button type="button" class="button" id="maca-bp-smart-restore" disabled><?php esc_html_e( 'Restore selected', 'maca-backup' ); ?></button>
	</div>

	<div id="maca-bp-smart-tree-wrap" class="maca-bp-tree-wrap" hidden>
		<p class="maca-bp-muted"><?php esc_html_e( 'Select files or folders from the backup to restore.', 'maca-backup' ); ?></p>
		<div id="maca-bp-smart-tree" class="maca-bp-tree" data-tree="smart"></div>
		<p class="maca-bp-muted maca-bp-tree-selected" id="maca-bp-smart-selected-count"></p>
	</div>

	<div id="maca-bp-smart-summary" class="maca-bp-grid maca-bp-grid--stats" style="margin-top:1.25rem;" hidden></div>
	<div id="maca-bp-smart-results" class="maca-bp-smart-results" hidden></div>
</section>
