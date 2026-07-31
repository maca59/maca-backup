<?php
/**
 * maca BackUp updates from api.maca.se.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Maca_Plugin_Updater', false ) ) {
	require_once MACA_BACKUP_PRO_PATH . 'includes/class-maca-plugin-updater.php';
}

/**
 * Register maca BackUp updater.
 *
 * @return void
 */
function maca_backup_pro_register_updater(): void {
	if ( ! class_exists( 'Maca_Plugin_Updater', false ) ) {
		return;
	}

	new Maca_Plugin_Updater(
		array(
			'slug'         => 'maca-backup',
			'basename'     => MACA_BACKUP_PRO_BASENAME,
			'version'      => MACA_BACKUP_PRO_VERSION,
			'update_url'   => 'https://api.maca.se/v1/backup-pro/update.json',
			'name'         => 'maca BackUp',
			'homepage'     => 'https://maca.se/maca-backup/',
			'description'  => __( 'Backup and restore for WordPress — full site, database, files, Smart Restore, and modular cloud storage.', 'maca-backup-pro' ),
			'admin_screen' => 'maca-backup',
			'text_domain'  => 'maca-backup-pro',
		)
	);
}
