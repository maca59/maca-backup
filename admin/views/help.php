<?php
/**
 * In-plugin Help guide (English).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- View/partial vars provided by the admin renderer.

$support_url  = Maca_Backup_Pro_Admin::tab_url( 'support' );
$settings_url = Maca_Backup_Pro_Admin::tab_url( 'settings' );
$storage_url  = Maca_Backup_Pro_Admin::tab_url( 'storage' );
$schedule_url = Maca_Backup_Pro_Admin::tab_url( 'schedule' );
$restore_url  = Maca_Backup_Pro_Admin::tab_url( 'restore' );
$migrate_ui   = Maca_Backup_Pro_Admin::migrate_ui_enabled();
$migrate_url  = $migrate_ui ? Maca_Backup_Pro_Admin::tab_url( 'migrate' ) : '';
$smart_url    = Maca_Backup_Pro_Admin::tab_url( 'smart' );
$backups_url  = Maca_Backup_Pro_Admin::tab_url( 'backups' );
$logs_url     = Maca_Backup_Pro_Admin::tab_url( 'logs' );
?>
<section class="maca-bp-panel maca-bp-help">
	<h2><?php esc_html_e( 'Help', 'maca-backup' ); ?></h2>
	<p class="maca-bp-muted">
		<?php esc_html_e( 'A short guide to maca BackUp — how to back up, schedule, store, and restore your WordPress site.', 'maca-backup' ); ?>
	</p>

	<nav class="maca-bp-help__toc" aria-label="<?php esc_attr_e( 'Help topics', 'maca-backup' ); ?>">
		<a href="#maca-bp-help-start"><?php esc_html_e( 'Getting started', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-manual"><?php esc_html_e( 'Manual backups', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-schedules"><?php esc_html_e( 'Schedules', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-storage"><?php esc_html_e( 'Storage', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-restore"><?php esc_html_e( 'Restore', 'maca-backup' ); ?></a>
		<?php if ( $migrate_ui ) : ?>
		<a href="#maca-bp-help-migrate"><?php esc_html_e( 'Migrate', 'maca-backup' ); ?></a>
		<?php endif; ?>
		<a href="#maca-bp-help-preupdate"><?php esc_html_e( 'Pre-update', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-email"><?php esc_html_e( 'Notifications', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-support"><?php esc_html_e( 'Support', 'maca-backup' ); ?></a>
		<a href="#maca-bp-help-troubleshoot"><?php esc_html_e( 'Troubleshooting', 'maca-backup' ); ?></a>
	</nav>

	<article class="maca-bp-help__section" id="maca-bp-help-start">
		<h3><?php esc_html_e( '1. Getting started', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Support tab URL */
						__( 'Accept the Terms and Privacy on the <a href="%s">Support</a> tab before running backups or restores.', 'maca-backup' ),
						esc_url( $support_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'On first visit, the Dashboard wizard helps you choose backup type, storage, and a schedule — then run your first backup.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'You can skip the wizard and start a full backup anytime from the Dashboard.', 'maca-backup' ); ?></li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-manual">
		<h3><?php esc_html_e( '2. Manual backups', 'maca-backup' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Full — database and files (recommended for a complete restore point).', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Database only — content, settings, and users in the database.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Files only — themes, plugins, uploads, and other site files.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Incremental and differential options are available when you need lighter follow-up backups after a full one.', 'maca-backup' ); ?></li>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Backups tab URL */
						__( 'History lives on the Dashboard and the <a href="%s">Backups</a> tab.', 'maca-backup' ),
						esc_url( $backups_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-schedules">
		<h3><?php esc_html_e( '3. Schedules', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Schedule tab URL */
						__( 'Open <a href="%s">Schedule</a> to add automatic backups.', 'maca-backup' ),
						esc_url( $schedule_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'Times use your WordPress site timezone (local), not UTC-only clock settings in the form.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Frequencies: hourly, every N hours, daily, weekly, monthly, or a custom cron expression.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'You can enable or disable a schedule without deleting it.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Per schedule, choose email notifications: site default, off, failures only, success only, or both.', 'maca-backup' ); ?></li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-storage">
		<h3><?php esc_html_e( '4. Storage', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Storage tab URL */
						__( 'Configure destinations under <a href="%s">Storage</a>.', 'maca-backup' ),
						esc_url( $storage_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'Local — archives stay on this server (simple default).', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Remote — send backups to your own cloud or server: Google Drive, Dropbox, OneDrive, S3-compatible storage, FTP, or SFTP.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Credentials belong to your accounts; maca BackUp does not provide hosted backup storage.', 'maca-backup' ); ?></li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-restore">
		<h3><?php esc_html_e( '5. Restore & Smart Restore', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Restore tab URL */
						__( '<a href="%s">Restore</a> is for the same site — pick a backup of this host and a scope (entire site, database, wp-content, uploads, plugins, themes, or a custom path). It does not rewrite URLs for a different domain.', 'maca-backup' ),
						esc_url( $restore_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'Preview changes shows what will be overwritten before you confirm.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Test restore checks the archive in a temporary folder without changing the live site.', 'maca-backup' ); ?></li>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Smart Restore tab URL */
						__( '<a href="%s">Smart Restore</a> — compare the live site with a backup and restore only new, changed, or selected files (or browse and pick paths).', 'maca-backup' ),
						esc_url( $smart_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
		</ul>
	</article>

	<?php if ( $migrate_ui ) : ?>
	<article class="maca-bp-help__section" id="maca-bp-help-migrate">
		<h3><?php esc_html_e( '6. Migrate (move another site here)', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Migrate tab URL */
						__( 'Use the <a href="%s">Migrate</a> tab to bring another WordPress site onto this host (new domain or new install).', 'maca-backup' ),
						esc_url( $migrate_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'On the old site, download a completed full backup from the Dashboard or Backups tab.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'On this site, import the ZIP under Migrate (or Backups), then click Migrate now. Import alone only registers the archive — content appears after migration finishes.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Migration always restores the full database and files, rewrites the old site URL to this site (including serialized options), remaps table-prefix keys, and keeps your current admin login. wp-config.php is not overwritten.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'After migrate, sign in with the same admin user/password you used on this site before the job (maca BackUp preserves that account).', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Large archives upload automatically in chunks (bypassing the PHP single-request limit). Keep the page open until import finishes.', 'maca-backup' ); ?></li>
		</ul>
	</article>
	<?php endif; ?>

	<article class="maca-bp-help__section" id="maca-bp-help-preupdate">
		<h3><?php esc_html_e( '7. Pre-update backups', 'maca-backup' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Off by default.', 'maca-backup' ); ?></li>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Settings tab URL */
						__( 'Enable under <a href="%s">Settings</a> → “Backup before WordPress, plugin, or theme updates”.', 'maca-backup' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'At most one pre-update backup per hour, so bulk or auto updates do not start a job for every package. Set how many pre-update archives to keep with Pre-update retention.', 'maca-backup' ); ?></li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-email">
		<h3><?php esc_html_e( '8. Notifications / email', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Settings tab URL */
						__( 'Configure under <a href="%s">Settings</a> → Email notifications.', 'maca-backup' ),
						esc_url( $settings_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li><?php esc_html_e( 'Choose recipients and whether to notify on successful or failed backups and restores. These are the site defaults.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Each schedule can override email behavior (Use site default, Off, Failures only, Success only, or Success and failures).', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'If recipients are empty, the site admin email is used when notifications are enabled.', 'maca-backup' ); ?></li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-support">
		<h3><?php esc_html_e( '9. Support', 'maca-backup' ); ?></h3>
		<ul>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Support tab URL */
						__( 'Need help? Use the form on the <a href="%s">Support</a> tab — we reply to the email address you enter. No separate portal login is required.', 'maca-backup' ),
						esc_url( $support_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
			<li>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: Logs tab URL */
						__( 'Include what you tried and any errors from the <a href="%s">Logs</a> tab when you write.', 'maca-backup' ),
						esc_url( $logs_url )
					),
					array( 'a' => array( 'href' => true ) )
				);
				?>
			</li>
		</ul>
	</article>

	<article class="maca-bp-help__section" id="maca-bp-help-troubleshoot">
		<h3><?php esc_html_e( '10. Troubleshooting basics', 'maca-backup' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'WP-Cron — scheduled jobs depend on WordPress cron (often triggered by site visits). Low-traffic sites may run late unless a real cron hits wp-cron.php.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Stuck jobs — if a backup or restore stays “running” with no progress, check Logs, then stop the job from the progress bar if it is still open, or wait for the plugin to reap stale jobs.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'Keep admin open while a job runs when possible — the open maca BackUp tab also drives work (in addition to background processing), which helps on hosts with slow or unreliable cron.', 'maca-backup' ); ?></li>
			<li><?php esc_html_e( 'You can leave the page; jobs continue in the background, but progress updates are fastest while the admin UI stays open.', 'maca-backup' ); ?></li>
		</ul>
	</article>
</section>
