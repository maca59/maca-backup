<?php
/**
 * Plugin Name:       maca BackUp
 * Plugin URI:        https://github.com/maca59/maca-backup
 * Description:       Backup and restore for WordPress â€” full site, database, files, Smart Restore, and modular cloud storage.
 * Version:           2.0.50
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Maca Development
 * Author URI:        https://github.com/maca59
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       maca-backup
 * Domain Path:       /languages
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

define( 'MACA_BACKUP_PRO_VERSION', '2.0.50' );
define( 'MACA_BACKUP_PRO_DB_VERSION', '2.0.0' );
define( 'MACA_BACKUP_PRO_FILE', __FILE__ );
define( 'MACA_BACKUP_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'MACA_BACKUP_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'MACA_BACKUP_PRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'MACA_BACKUP_PRO_OPTION_KEY', 'maca_backup_pro_settings' );
define( 'MACA_BACKUP_PRO_MIN_PHP', '8.2' );

if ( version_compare( PHP_VERSION, MACA_BACKUP_PRO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html(
				sprintf(
					/* translators: 1: required PHP version, 2: current PHP version */
					__( 'maca BackUp requires PHP %1$s or newer. You are running PHP %2$s.', 'maca-backup' ),
					MACA_BACKUP_PRO_MIN_PHP,
					PHP_VERSION
				)
			);
			echo '</p></div>';
		}
	);
	return;
}

require_once MACA_BACKUP_PRO_PATH . 'includes/class-autoloader.php';
Maca_Backup_Pro_Autoloader::register();

require_once MACA_BACKUP_PRO_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'Maca_Backup_Pro_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Maca_Backup_Pro_Plugin', 'deactivate' ) );

/**
 * Main plugin accessor.
 *
 * @return Maca_Backup_Pro_Plugin
 */
function maca_backup_pro(): Maca_Backup_Pro_Plugin {
	return Maca_Backup_Pro_Plugin::instance();
}

add_action(
	'plugins_loaded',
	static function () {
		maca_backup_pro();
	}
);
