=== maca BackUp ===
Contributors: macas
Tags: backup, restore, migration, wordpress backup, cloud storage
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 2.0.51
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Backup and restore for WordPress â€” full site, database, files, Smart Restore, and modular cloud storage.

== Description ==

maca BackUp protects your WordPress site with full, database-only, and files-only backups. Run jobs in the background, schedule automatic backups, encrypt archives, and restore selectively with Smart Restore.

**Features**

* Full, incremental, differential, database, and files backups
* Background processing with progress in wp-admin
* Schedules (hourly, daily, weekly, monthly, custom)
* Local storage plus FTP, SFTP, Google Drive, Dropbox, OneDrive, and S3-compatible destinations
* AES-256-GCM backup encryption (optional)
* Pre-update backups before WordPress, plugin, or theme updates
* Smart Restore â€” compare and restore only changed files
* Import/export archives for migration between hosts
* Optional maca Hub heartbeat for multi-site monitoring
* Live maca Hub status (latest backup, active job, schedules) via Hub Connector
* In-plugin Help and Support

Backups stay on your server or in cloud accounts you configure. Maca Development does not host your backup archives.

Developed by **Maca Development**. Source and issue tracker: https://github.com/maca59/maca-backup

== Installation ==

1. Upload the `maca-backup` folder to `/wp-content/plugins/` or install via Plugins â†’ Add New (search for â€œmaca BackUpâ€) / Upload Plugin.
2. Activate maca BackUp through the Plugins screen.
3. Open **maca BackUp** in the admin menu.
4. Accept the Terms and Privacy Policy on the Support tab.
5. Run a full backup from the Dashboard, or complete the first-run wizard.

== Frequently Asked Questions ==

= Where are backups stored? =

By default in your uploads directory under a maca-backups folder (resolved via wp_upload_dir()), or in a remote destination you configure under Storage. Custom absolute paths outside uploads are not used.

= Do I need a license key? =

No. maca BackUp is GPL-licensed and does not use paid license activation.

= What are the requirements? =

WordPress 6.0+, PHP 8.2+, and the PHP ZipArchive extension for file backups and imports. SFTP requires the ssh2 extension.

= How do I get support? =

Use the Support form inside the plugin (maca BackUp â†’ Support), or open an issue on GitHub: https://github.com/maca59/maca-backup/issues

== External services ==

This plugin can connect to third-party services when you enable or configure the related features. Nothing below is required for local-only backups.

= Google Drive =

Optional remote storage. When configured under Storage, the plugin uses Google OAuth and the Google Drive API to upload, download, and delete backup archives in your Google Drive account.

Data sent: OAuth tokens/credentials you supply, and backup archive files (binary) when a backup or restore uses Google Drive.

Service provided by Google. [Terms of Service](https://policies.google.com/terms) Â· [Privacy Policy](https://policies.google.com/privacy) Â· [Google APIs Terms](https://developers.google.com/terms)

= Microsoft OneDrive =

Optional remote storage via Microsoft Graph. When configured, the plugin uploads, downloads, and deletes backup archives in your OneDrive.

Data sent: OAuth tokens/credentials you supply, and backup archive files when OneDrive is the active destination.

Service provided by Microsoft. [Microsoft Services Agreement](https://www.microsoft.com/servicesagreement) Â· [Privacy Statement](https://privacy.microsoft.com/privacystatement)

= Dropbox =

Optional remote storage. When configured, the plugin uses the Dropbox API to upload, download, and delete backup archives in your Dropbox account.

Data sent: access token/credentials you supply, and backup archive files when Dropbox is used.

Service provided by Dropbox. [Terms of Service](https://www.dropbox.com/terms) Â· [Privacy Policy](https://www.dropbox.com/privacy)

= Amazon S3 / S3-compatible storage =

Optional remote storage (Amazon S3, Backblaze B2, and other S3-compatible endpoints you configure). Used to upload, download, and delete backup objects in the bucket you specify.

Data sent: API credentials you supply, and backup archive files to the endpoint/region/bucket you configure.

For Amazon Web Services: [AWS Service Terms](https://aws.amazon.com/service-terms/) Â· [AWS Privacy](https://aws.amazon.com/privacy/). Other S3-compatible providers follow their own terms (configure only services you trust).

= maca Hub / telemetry =

Optional and **off by default**. When you enable maca Hub under Settings, the plugin may send limited operational metadata to the Maca Hub API (operated by Maca Development) for multi-site monitoring (heartbeat / lifecycle events).

Data sent (when enabled): site URL, plugin version, WordPress/PHP versions, locale, and backup status metadata (for example type, date, size, status). Backup archive contents and database dumps are never uploaded.

Service provided by Maca Development. [Terms](https://github.com/maca59/maca-backup/blob/master/TERMS.md) · [Privacy](https://github.com/maca59/maca-backup/blob/master/PRIVACY.md)

= In-plugin Support =

When you submit a support request from maca BackUp → Support, the plugin sends your message to Maca Development so we can reply (HTTPS API operated by Maca Development, with e-mail fallback to support@maca.se).

Data sent: name, email, subject, message, and optional system information you choose to include (site URL, plugin/WordPress/PHP versions, locale).

Service provided by Maca Development. [Terms](https://github.com/maca59/maca-backup/blob/master/TERMS.md) · [Privacy](https://github.com/maca59/maca-backup/blob/master/PRIVACY.md)

== Privacy ==

By default the plugin does **not** send data to Maca Development.

If you enable **maca Hub / telemetry** under Settings, the plugin may send limited operational metadata (for example site URL, plugin version, backup status, and a list of backup history entries such as type, date, size, and status) for multi-site monitoring. Backup archives and database contents are never uploaded. You can turn this off at any time.

Support requests you submit via the in-plugin Support form are sent to Maca Development so we can reply.

Remote storage providers you configure (FTP, cloud drives, S3, etc.) process data under their own terms. See **External services** above.

== Changelog ==

= 2.0.51 =
* Remap dump table prefix to the live site prefix during SQL restore (fixes empty pages after migration)
* Do not mark database restore done when database.sql cannot be read
* Log dump size / post-row inserts / page counts; fail clearly if pages never land in live tables
* Store table_prefix in backup manifest; broaden URL rewrite (http/https, www)
* Schedule catch-up: daily/weekly/monthly still run later the same day if WP-Cron misses the exact minute
* Clarify schedule times use the WordPress site timezone (Settings → General)

= 2.0.50 =
* Migration restore: rewrite source URLs to the destination site (serialized-aware) after database restore
* Keep restore job alive by skipping maca_backup_* control tables during SQL replay
* Do not overwrite wp-config.php on restore; flush rewrite rules when restore completes
* Harden database.sql parsing so a failed split cannot silently skip the rest of the dump

= 2.0.49 =
* (version bump alignment)

= 2.0.47 =
* Import verifies and stores archive CRC32 so backups can be compared after migration
* Compare UI shows archive CRC32 and per-file CRC on mismatches

= 2.0.46 =
* Import large backups (over PHP/WordPress upload limits, e.g. >1 GB) via automatic chunked upload
* Media Library always included in full backups; exclude rules cannot strip uploads

= 2.0.39 =
* Public product/legal/support links use GitHub only (no maca.se URLs for review crawlers)
* Assemble Hub/Support API hosts at runtime

= 2.0.38 =
* Confine local backups to uploads/maca-backups (no arbitrary custom paths)
* Resolve site paths via WP APIs (wp_upload_dir, WP_PLUGIN_DIR, get_theme_root, get_home_path)

= 2.0.37 =
* Point Terms/Privacy links to public GitHub documents (maca.se pages were unreachable for wordpress.org review)

= 2.0.36 =
* WordPress.org review: text domain matches plugin slug (maca-backup)
* Document external services (Google Drive, OneDrive, Dropbox, S3, maca Hub, Support) in readme
* Confine staging restores to the local backup directory
* Harden ZIP import/restore against path traversal (Zip Slip)

= 2.0.34 =
* Per-schedule email notifications (inherit site default, off, failures only, success only, or both)
* Site Settings remain the fallback for schedules set to â€œUse site defaultâ€
* Email when a scheduled backup fails to start (respects that scheduleâ€™s email mode)
* Fix duplicate backup/restore notification emails when AJAX and cron finish the same job
* Fix inflated full-backup size when concurrent workers packed the same files repeatedly into the ZIP
* Live progress: elapsed timer ticks every second; status polls return faster so the bar/detail update more often
* Compare two backups on the Backups tab (file counts, only-in-A/B paths, size mismatches)
* Show CRC32 checksum on backup history (and in success emails); stored in manifest + checksum column
* Hub status/heartbeat includes full backup history list (type, datetime, size, status, storage, CRC, etc.)

= 2.0.23 =
* Hub Connector status endpoint with latest backup, active job, and schedules

= 2.0.21 =
* Plugin Check compatibility fixes
* Support URL and onboarding wizard improvements

= 2.0.0 =
* Major release: Smart Restore, storage providers, schedules, encryption, hub telemetry

== Upgrade Notice ==

= 2.0.39 =
All public URLs point to GitHub; maca.se links removed for wordpress.org review crawlers.


= 2.0.38 =
Local storage confined to uploads; WordPress path APIs for site file resolution.


= 2.0.37 =
Terms and Privacy documents hosted on GitHub for reliable review links.


= 2.0.36 =
WordPress.org compliance: text domain, external services documentation, safer staging and ZIP handling.

= 2.0.34 =
Recommended: schedule email controls, safer concurrent backups, live progress, backup compare, and CRC32 checksums.

= 2.0.23 =
Adds rich maca Hub status (backup, job, schedules) for multi-site monitoring.

= 2.0.21 =
Recommended update for WordPress.org Plugin Check compliance and support URL fixes.
