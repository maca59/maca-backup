<?php
/**
 * Storage settings view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $settings
 * @var array $providers
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.
$storage = $settings['storage'] ?? array();
$maca_backup_local_subdir = '';
$maca_backup_saved_path   = isset( $storage['local']['path'] ) ? wp_normalize_path( untrailingslashit( (string) $storage['local']['path'] ) ) : '';
$maca_backup_default_dir  = wp_normalize_path( untrailingslashit( Maca_Backup_Pro_Paths::default_backup_dir() ) );
if ( '' !== $maca_backup_saved_path && str_starts_with( $maca_backup_saved_path, trailingslashit( $maca_backup_default_dir ) ) ) {
	$maca_backup_local_subdir = basename( $maca_backup_saved_path );
}
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Storage destinations', 'maca-backup' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
		<input type="hidden" name="maca_backup_pro_action" value="save_storage" />

		<label class="maca-bp-field">
			<span><?php esc_html_e( 'Active provider', 'maca-backup' ); ?></span>
			<select name="storage_provider">
				<?php foreach ( $providers as $id => $provider ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['storage_provider'], $id ); ?>>
						<?php
						echo esc_html( $provider->label() );
						echo $provider->is_configured() ? '' : ' (' . esc_html__( 'not configured', 'maca-backup' ) . ')';
						?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>

		<details class="maca-bp-details" open>
			<summary><?php esc_html_e( 'Local', 'maca-backup' ); ?></summary>
			<p class="maca-bp-muted">
				<?php
				printf(
					/* translators: %s: absolute backup directory under uploads */
					esc_html__( 'Backups are stored under your uploads directory: %s', 'maca-backup' ),
					esc_html( Maca_Backup_Pro_Settings::local_backup_dir() )
				);
				?>
			</p>
			<label class="maca-bp-field">
				<span><?php esc_html_e( 'Optional subfolder under uploads/maca-backups', 'maca-backup' ); ?></span>
				<input
					type="text"
					name="storage[local][subdir]"
					value="<?php echo esc_attr( $maca_backup_local_subdir ); ?>"
					placeholder="<?php esc_attr_e( 'Leave empty for default', 'maca-backup' ); ?>"
					pattern="[A-Za-z0-9_\-]+"
				/>
			</label>
			<p class="maca-bp-muted"><?php esc_html_e( 'Only letters, numbers, dashes, and underscores. Paths outside uploads are not allowed.', 'maca-backup' ); ?></p>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'FTP', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Host', 'maca-backup' ); ?></span><input type="text" name="storage[ftp][host]" value="<?php echo esc_attr( (string) ( $storage['ftp']['host'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Port', 'maca-backup' ); ?></span><input type="number" name="storage[ftp][port]" value="<?php echo esc_attr( (string) ( $storage['ftp']['port'] ?? 21 ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'User', 'maca-backup' ); ?></span><input type="text" name="storage[ftp][user]" value="<?php echo esc_attr( (string) ( $storage['ftp']['user'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Password', 'maca-backup' ); ?></span><input type="password" name="storage[ftp][pass]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Path', 'maca-backup' ); ?></span><input type="text" name="storage[ftp][path]" value="<?php echo esc_attr( (string) ( $storage['ftp']['path'] ?? '/' ) ); ?>" /></label>
			</div>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'SFTP', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Host', 'maca-backup' ); ?></span><input type="text" name="storage[sftp][host]" value="<?php echo esc_attr( (string) ( $storage['sftp']['host'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Port', 'maca-backup' ); ?></span><input type="number" name="storage[sftp][port]" value="<?php echo esc_attr( (string) ( $storage['sftp']['port'] ?? 22 ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'User', 'maca-backup' ); ?></span><input type="text" name="storage[sftp][user]" value="<?php echo esc_attr( (string) ( $storage['sftp']['user'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Password', 'maca-backup' ); ?></span><input type="password" name="storage[sftp][pass]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label class="maca-bp-field--full"><span><?php esc_html_e( 'Private key (optional)', 'maca-backup' ); ?></span><textarea name="storage[sftp][key]" rows="4" placeholder="<?php esc_attr_e( 'Paste private key to replace stored key', 'maca-backup' ); ?>"></textarea></label>
				<label><span><?php esc_html_e( 'Path', 'maca-backup' ); ?></span><input type="text" name="storage[sftp][path]" value="<?php echo esc_attr( (string) ( $storage['sftp']['path'] ?? '/' ) ); ?>" /></label>
			</div>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'Google Drive', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Client ID', 'maca-backup' ); ?></span><input type="text" name="storage[google_drive][client_id]" value="<?php echo esc_attr( (string) ( $storage['google_drive']['client_id'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Client secret', 'maca-backup' ); ?></span><input type="password" name="storage[google_drive][client_secret]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Refresh token', 'maca-backup' ); ?></span><input type="password" name="storage[google_drive][refresh_token]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Folder ID', 'maca-backup' ); ?></span><input type="text" name="storage[google_drive][folder_id]" value="<?php echo esc_attr( (string) ( $storage['google_drive']['folder_id'] ?? '' ) ); ?>" /></label>
			</div>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'Dropbox', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Access token', 'maca-backup' ); ?></span><input type="password" name="storage[dropbox][access_token]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Path', 'maca-backup' ); ?></span><input type="text" name="storage[dropbox][path]" value="<?php echo esc_attr( (string) ( $storage['dropbox']['path'] ?? '/maca-backups' ) ); ?>" /></label>
			</div>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'OneDrive', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Client ID', 'maca-backup' ); ?></span><input type="text" name="storage[onedrive][client_id]" value="<?php echo esc_attr( (string) ( $storage['onedrive']['client_id'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Client secret', 'maca-backup' ); ?></span><input type="password" name="storage[onedrive][client_secret]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Refresh token', 'maca-backup' ); ?></span><input type="password" name="storage[onedrive][refresh_token]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Folder path', 'maca-backup' ); ?></span><input type="text" name="storage[onedrive][folder_path]" value="<?php echo esc_attr( (string) ( $storage['onedrive']['folder_path'] ?? '/maca-backups' ) ); ?>" /></label>
			</div>
		</details>

		<details class="maca-bp-details">
			<summary><?php esc_html_e( 'Amazon S3 / Backblaze B2 / S3-compatible', 'maca-backup' ); ?></summary>
			<div class="maca-bp-form-grid">
				<label><span><?php esc_html_e( 'Access key', 'maca-backup' ); ?></span><input type="text" name="storage[s3][access_key]" value="<?php echo esc_attr( (string) ( $storage['s3']['access_key'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Secret key', 'maca-backup' ); ?></span><input type="password" name="storage[s3][secret_key]" value="" autocomplete="new-password" placeholder="••••••••" /></label>
				<label><span><?php esc_html_e( 'Bucket', 'maca-backup' ); ?></span><input type="text" name="storage[s3][bucket]" value="<?php echo esc_attr( (string) ( $storage['s3']['bucket'] ?? '' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Region', 'maca-backup' ); ?></span><input type="text" name="storage[s3][region]" value="<?php echo esc_attr( (string) ( $storage['s3']['region'] ?? 'us-east-1' ) ); ?>" /></label>
				<label><span><?php esc_html_e( 'Endpoint (optional, e.g. B2)', 'maca-backup' ); ?></span><input type="text" name="storage[s3][endpoint]" value="<?php echo esc_attr( (string) ( $storage['s3']['endpoint'] ?? '' ) ); ?>" placeholder="https://s3.eu-central-003.backblazeb2.com" /></label>
				<label><span><?php esc_html_e( 'Prefix', 'maca-backup' ); ?></span><input type="text" name="storage[s3][prefix]" value="<?php echo esc_attr( (string) ( $storage['s3']['prefix'] ?? 'maca-backups' ) ); ?>" /></label>
				<label class="maca-bp-check maca-bp-field--full"><input type="checkbox" name="storage[s3][path_style]" value="1" <?php checked( ! empty( $storage['s3']['path_style'] ) ); ?> /> <?php esc_html_e( 'Path-style URLs (required for many S3-compatible providers)', 'maca-backup' ); ?></label>
			</div>
		</details>

		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save storage', 'maca-backup' ); ?></button></p>
	</form>
</section>
