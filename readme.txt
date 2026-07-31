=== maca BackUp ===
Contributors: macadevelopment
Tags: backup, restore, migration, wordpress backup, cloud storage
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2.0.26
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup and restore for WordPress — full site, database, files, Smart Restore, and modular cloud storage.

== Description ==

maca BackUp protects your WordPress site with full, database-only, and files-only backups. Run jobs in the background, schedule automatic backups, encrypt archives, and restore selectively with Smart Restore.

**Features**

* Full, incremental, differential, database, and files backups
* Background processing with progress in wp-admin
* Schedules (hourly, daily, weekly, monthly, custom)
* Local storage plus FTP, SFTP, Google Drive, Dropbox, OneDrive, and S3-compatible destinations
* AES-256-GCM backup encryption (optional)
* Pre-update backups before WordPress, plugin, or theme updates
* Smart Restore — compare and restore only changed files
* Import/export archives for migration between hosts
* Optional maca Hub heartbeat for multi-site monitoring
* Live maca Hub status (latest backup, active job, schedules) via Hub Connector
* In-plugin Help and Support

Backups stay on your server or in cloud accounts you configure. maca does not host your backup archives.

== Installation ==

1. Upload the `maca-backup-pro` folder to `/wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload.
2. Activate maca BackUp through the Plugins screen.
3. Open **maca BackUp** in the admin menu.
4. Accept the Terms and Privacy Policy on the Support tab.
5. Run a full backup from the Dashboard, or complete the first-run wizard.

== Frequently Asked Questions ==

= Where are backups stored? =

By default in your uploads directory under a maca-backups folder, or in a remote destination you configure under Storage.

= Do I need a license key? =

No. maca BackUp is GPL-licensed and does not use paid license activation.

= What are the requirements? =

WordPress 6.0+, PHP 8.2+, and the PHP ZipArchive extension for file backups and imports. SFTP requires the ssh2 extension.

= How do I get support? =

Use the Support form inside the plugin (maca BackUp → Support), or email support@maca.se. Public help: https://maca.se/support-maca-backup/

== Privacy ==

By default the plugin does **not** send data to maca servers.

If you enable **maca Hub / telemetry** under Settings, the plugin may send limited operational metadata to api.maca.se (for example site URL, plugin version, and backup status). Backup archives and database contents are never uploaded to maca. You can turn this off at any time.

Support requests you submit via the in-plugin Support form are sent to maca.se so we can reply.

Remote storage providers you configure (FTP, cloud drives, S3, etc.) process data under their own terms.

== Changelog ==

= 2.0.23 =
* Hub Connector status endpoint with latest backup, active job, and schedules

= 2.0.21 =
* Plugin Check compatibility fixes
* Support URL and onboarding wizard improvements

= 2.0.0 =
* Major release: Smart Restore, storage providers, schedules, encryption, hub telemetry

== Upgrade Notice ==

= 2.0.23 =
Adds rich maca Hub status (backup, job, schedules) for multi-site monitoring.

= 2.0.21 =
Recommended update for WordPress.org Plugin Check compliance and support URL fixes.
