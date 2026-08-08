<?php
/**
 * Settings view.
 *
 * @package Maca_Backup_Pro
 *
 * @var array $settings
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.
$excludes = is_array( $settings['exclude_paths'] ?? null ) ? implode( "\n", $settings['exclude_paths'] ) : '';
?>
<section class="maca-bp-panel">
	<h2><?php esc_html_e( 'Settings', 'maca-backup' ); ?></h2>
	<form method="post">
		<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
		<input type="hidden" name="maca_backup_pro_action" value="save_settings" />

		<div class="maca-bp-form-grid">
			<label>
				<span><?php esc_html_e( 'Retention (number of backups)', 'maca-backup' ); ?></span>
				<input type="number" min="1" name="retention_count" value="<?php echo esc_attr( (string) $settings['retention_count'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'ZIP split size (MB, 0 = off)', 'maca-backup' ); ?></span>
				<input type="number" min="0" name="zip_split_mb" value="<?php echo esc_attr( (string) $settings['zip_split_mb'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Files per chunk', 'maca-backup' ); ?></span>
				<input type="number" min="5" name="chunk_files" value="<?php echo esc_attr( (string) $settings['chunk_files'] ); ?>" />
			</label>
			<label>
				<span><?php esc_html_e( 'Tables per chunk', 'maca-backup' ); ?></span>
				<input type="number" min="1" name="chunk_tables" value="<?php echo esc_attr( (string) $settings['chunk_tables'] ); ?>" />
			</label>
			<label class="maca-bp-field--full">
				<span><?php esc_html_e( 'Exclude paths (one per line, relative to site root)', 'maca-backup' ); ?></span>
				<textarea name="exclude_paths" rows="5"><?php echo esc_textarea( $excludes ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Media Library (wp-content/uploads) is always included and cannot be excluded. Full backups ignore exclude paths except plugin cache/staging folders.', 'maca-backup' ); ?></p>
			</label>
		</div>

		<h3><?php esc_html_e( 'Encryption', 'maca-backup' ); ?></h3>
		<label class="maca-bp-check"><input type="checkbox" name="encrypt_backups" value="1" <?php checked( ! empty( $settings['encrypt_backups'] ) ); ?> /> <?php esc_html_e( 'Encrypt backup archives (AES-256-GCM)', 'maca-backup' ); ?></label>
		<label class="maca-bp-field">
			<span><?php esc_html_e( 'Backup passphrase', 'maca-backup' ); ?></span>
			<input type="password" name="backup_passphrase" value="" autocomplete="new-password" placeholder="<?php echo ! empty( $settings['backup_passphrase'] ) ? '••••••••' : ''; ?>" />
		</label>

		<h3><?php esc_html_e( 'Pre-update backups', 'maca-backup' ); ?></h3>
		<label class="maca-bp-check"><input type="checkbox" name="pre_update_backup" value="1" <?php checked( ! empty( $settings['pre_update_backup'] ) ); ?> /> <?php esc_html_e( 'Backup before WordPress, plugin, or theme updates', 'maca-backup' ); ?></label>
		<p class="maca-bp-muted"><?php esc_html_e( 'At most one pre-update backup per hour, so bulk/auto updates do not start a new job for every package.', 'maca-backup' ); ?></p>
		<label class="maca-bp-field">
			<span><?php esc_html_e( 'Pre-update retention', 'maca-backup' ); ?></span>
			<input type="number" min="1" name="pre_update_retention" value="<?php echo esc_attr( (string) ( $settings['pre_update_retention'] ?? 5 ) ); ?>" />
		</label>

		<h3><?php esc_html_e( 'maca Hub', 'maca-backup' ); ?></h3>
		<label class="maca-bp-check"><input type="checkbox" name="hub_enabled" value="1" <?php checked( ! empty( $settings['hub_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable maca Hub / telemetry (optional)', 'maca-backup' ); ?></label>
		<p class="maca-bp-muted"><?php esc_html_e( 'Off by default. When enabled, the plugin may send site URL, plugin version, and backup history metadata (type, date/time, size, status, and related fields) to the Maca Hub API for multi-site monitoring. Backup file contents are never sent.', 'maca-backup' ); ?></p>

		<h3><?php esc_html_e( 'Email notifications', 'maca-backup' ); ?></h3>
		<label class="maca-bp-check"><input type="checkbox" name="email_enabled" value="1" <?php checked( ! empty( $settings['email_enabled'] ) ); ?> /> <?php esc_html_e( 'Enable email notifications', 'maca-backup' ); ?></label>
		<label class="maca-bp-field">
			<span><?php esc_html_e( 'Recipients (comma-separated)', 'maca-backup' ); ?></span>
			<input type="text" name="email_recipients" value="<?php echo esc_attr( (string) $settings['email_recipients'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
		</label>
		<p class="maca-bp-muted"><?php esc_html_e( 'Default for manual backups and schedules set to “Use site default”. Each schedule can override this under Scheduled backups (e.g. failures only for every-4-hours DB backups).', 'maca-backup' ); ?></p>
		<label class="maca-bp-check"><input type="checkbox" name="email_on_success" value="1" <?php checked( ! empty( $settings['email_on_success'] ) ); ?> /> <?php esc_html_e( 'Successful backup', 'maca-backup' ); ?></label>
		<label class="maca-bp-check"><input type="checkbox" name="email_on_failure" value="1" <?php checked( ! empty( $settings['email_on_failure'] ) ); ?> /> <?php esc_html_e( 'Failed backup', 'maca-backup' ); ?></label>
		<label class="maca-bp-check"><input type="checkbox" name="email_on_restore_ok" value="1" <?php checked( ! empty( $settings['email_on_restore_ok'] ) ); ?> /> <?php esc_html_e( 'Successful restore', 'maca-backup' ); ?></label>
		<label class="maca-bp-check"><input type="checkbox" name="email_on_restore_fail" value="1" <?php checked( ! empty( $settings['email_on_restore_fail'] ) ); ?> /> <?php esc_html_e( 'Failed restore', 'maca-backup' ); ?></label>

		<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'maca-backup' ); ?></button></p>
	</form>

	<form method="post" class="maca-bp-test-email" style="margin-top:0.75rem;">
		<?php wp_nonce_field( Maca_Backup_Pro_Security::NONCE_ACTION ); ?>
		<input type="hidden" name="maca_backup_pro_action" value="send_test_email" />
		<p class="maca-bp-muted" style="margin:0 0 0.5rem;">
			<?php
			$test_to = class_exists( 'Maca_Backup_Pro_Mailer', false )
				? Maca_Backup_Pro_Mailer::recipient_list()
				: array( (string) get_option( 'admin_email' ) );
			printf(
				/* translators: %s: recipient email(s) */
				esc_html__( 'Send a test notification to %s. Save recipients first if you changed them.', 'maca-backup' ),
				esc_html( implode( ', ', $test_to ) )
			);
			?>
		</p>
		<button type="submit" class="button"><?php esc_html_e( 'Send test email', 'maca-backup' ); ?></button>
	</form>
</section>
