<?php
/**
 * Migrate view — bring another WordPress site onto this host.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $history
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$selected   = isset( $_GET['backup_id'] ) ? absint( $_GET['backup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$dest_home  = untrailingslashit( home_url() );
$dest_site  = untrailingslashit( site_url() );
$actor      = wp_get_current_user();
$actor_login = ( $actor instanceof WP_User && $actor->exists() ) ? (string) $actor->user_login : '';
$current_host = (string) ( wp_parse_url( $dest_home, PHP_URL_HOST ) ?: '' );
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Migrate', 'maca-backup' ); ?></h2>
	<p class="maca-bp-muted"><?php esc_html_e( 'Bring another WordPress site onto this host. Migration always restores the full database and files, rewrites URLs to this site, fixes table prefixes, and keeps your current admin login.', 'maca-backup' ); ?></p>

	<div id="maca-bp-migrate-progress-slot" class="maca-bp-job-progress-slot" aria-live="polite"></div>

	<div class="maca-bp-import">
		<h3><?php esc_html_e( '1. Import archive from the old site', 'maca-backup' ); ?></h3>
		<p class="maca-bp-muted"><?php esc_html_e( 'Download a full backup on the source site, then upload the ZIP here. Import only registers the archive — click Migrate now afterwards.', 'maca-backup' ); ?></p>
		<form method="post" enctype="multipart/form-data" class="maca-bp-import-form" data-import-context="migrate">
			<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
			<input type="hidden" name="maca_backup_pro_action" value="import_backup" />
			<input type="file" name="backup_file" accept=".zip,.enc,application/zip" required />
			<button type="submit" class="button"><?php esc_html_e( 'Import backup', 'maca-backup' ); ?></button>
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

	<div class="maca-bp-form-grid" style="margin-top:1.25rem;">
		<label>
			<span><?php esc_html_e( '2. Backup to migrate', 'maca-backup' ); ?></span>
			<select id="maca-bp-migrate-backup">
				<option value=""><?php esc_html_e( 'Select…', 'maca-backup' ); ?></option>
				<?php if ( empty( $history ) ) : ?>
					<option value="" disabled><?php esc_html_e( 'No completed backups available — import one above', 'maca-backup' ); ?></option>
				<?php else : ?>
					<?php foreach ( $history as $row ) : ?>
						<?php
						$label_date = Maca_Backup_Pro_Format::datetime_local( ! empty( $row->finished_at ) ? (string) $row->finished_at : (string) $row->created_at );
						$src_hint   = '';
						$meta       = array();
						if ( ! empty( $row->meta_json ) ) {
							$decoded = json_decode( (string) $row->meta_json, true );
							if ( is_array( $decoded ) ) {
								$meta = $decoded;
							}
						}
						$src_url = (string) ( $meta['home_url'] ?? $meta['site_url'] ?? '' );
						$src_host = $src_url ? (string) ( wp_parse_url( $src_url, PHP_URL_HOST ) ?: '' ) : '';
						if ( '' !== $src_host && '' !== $current_host && 0 !== strcasecmp( $src_host, $current_host ) ) {
							$src_hint = ' · ' . $src_host;
						}
						?>
						<option value="<?php echo esc_attr( (string) $row->id ); ?>" <?php selected( $selected, (int) $row->id ); ?>>
							<?php
							echo esc_html(
								$label_date . ' — ' . (string) $row->type . ' (' . size_format( (int) $row->size_bytes ) . ')' . $src_hint
							);
							?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>
		</label>
	</div>

	<div class="maca-bp-migrate-summary" style="margin-top:1.25rem;">
		<h3><?php esc_html_e( '3. This site (destination)', 'maca-backup' ); ?></h3>
		<dl class="maca-bp-wizard__summary">
			<div>
				<dt><?php esc_html_e( 'Home URL', 'maca-backup' ); ?></dt>
				<dd><?php echo esc_html( $dest_home ); ?></dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Site URL', 'maca-backup' ); ?></dt>
				<dd><?php echo esc_html( $dest_site ); ?></dd>
			</div>
			<?php if ( '' !== $actor_login ) : ?>
				<div>
					<dt><?php esc_html_e( 'Admin kept after migrate', 'maca-backup' ); ?></dt>
					<dd><?php echo esc_html( $actor_login ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
		<p class="maca-bp-muted"><?php esc_html_e( 'After migration, sign in with this site’s current admin password (not necessarily the old site’s). wp-config.php is not overwritten.', 'maca-backup' ); ?></p>
	</div>

	<div class="maca-bp-actions" style="margin-top:1.25rem;">
		<button type="button" class="button button-primary" id="maca-bp-run-migrate"><?php esc_html_e( 'Migrate now', 'maca-backup' ); ?></button>
		<a class="button" href="<?php echo esc_url( Maca_Backup_Pro_Admin::tab_url( 'restore' ) ); ?>"><?php esc_html_e( 'Same-site restore…', 'maca-backup' ); ?></a>
	</div>
</section>
