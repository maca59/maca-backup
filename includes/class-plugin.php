<?php
/**
 * Main plugin bootstrap.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton plugin orchestrator.
 */
class Maca_Backup_Pro_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		require_once MACA_BACKUP_PRO_PATH . 'includes/maca-api.php';
		require_once MACA_BACKUP_PRO_PATH . 'includes/hub-api.php';
		require_once MACA_BACKUP_PRO_PATH . 'includes/hub-rest.php';
		new Maca_Backup_Pro_Hub_Rest();

		add_action( MACA_BACKUP_PRO_API_FLUSH_CRON_HOOK, 'maca_backup_pro_api_flush_pending_telemetry' );
		add_action( 'admin_init', array( $this, 'maybe_flush_pending_telemetry' ), 999 );
		add_action( 'shutdown', array( $this, 'maybe_schedule_telemetry_flush' ), 0 );

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		$this->maybe_version_upgrade_ping();

		Maca_Backup_Pro_Storage_Registry::instance()->boot();
		Maca_Backup_Pro_Scheduler::instance()->boot();
		Maca_Backup_Pro_Ajax::instance()->boot();
		Maca_Backup_Pro_Pre_Update::boot();

		add_action( 'maca_backup_pro_hub_heartbeat', 'maca_backup_pro_api_hub_heartbeat' );
		add_action( 'init', 'maca_backup_pro_api_schedule_hub_heartbeat', 20 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once MACA_BACKUP_PRO_PATH . 'includes/class-cli.php';
		}

		if ( is_admin() ) {
			new Maca_Backup_Pro_Admin();
			new Maca_Backup_Pro_Assets();
		}

		add_action( 'admin_init', array( 'Maca_Backup_Pro_Legal', 'register_privacy_policy_content' ) );
	}

	/**
	 * After plugin update: refresh hub stats with the new version.
	 *
	 * @return void
	 */
	private function maybe_version_upgrade_ping(): void {
		$stored_version = (string) get_option( 'maca_backup_pro_plugin_version', '' );
		if ( MACA_BACKUP_PRO_VERSION === $stored_version ) {
			return;
		}

		update_option( 'maca_backup_pro_plugin_version', MACA_BACKUP_PRO_VERSION, false );

		if ( '' !== $stored_version ) {
			add_action( 'shutdown', array( $this, 'deliver_version_upgrade_ping' ), 1 );
		}
	}

	/**
	 * Report activation-style ping after a plugin update.
	 *
	 * @return void
	 */
	public function deliver_version_upgrade_ping(): void {
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return;
		}

		require_once MACA_BACKUP_PRO_PATH . 'includes/maca-api.php';
		if ( ! maca_backup_pro_api_is_enabled() ) {
			return;
		}

		update_option( MACA_BACKUP_PRO_API_PENDING_OPTION, 'activated', false );
		maca_backup_pro_api_schedule_flush();
	}

	/**
	 * Deliver pending telemetry on admin page loads (shared hosts without reliable cron).
	 *
	 * @return void
	 */
	public function maybe_flush_pending_telemetry(): void {
		$pending = get_option( MACA_BACKUP_PRO_API_PENDING_OPTION, '' );
		if ( ! is_string( $pending ) || '' === $pending ) {
			return;
		}

		maca_backup_pro_api_flush_pending_telemetry();
	}

	/**
	 * Ensure pending telemetry has a scheduled cron delivery.
	 *
	 * @return void
	 */
	public function maybe_schedule_telemetry_flush(): void {
		$pending = get_option( MACA_BACKUP_PRO_API_PENDING_OPTION, '' );
		if ( ! is_string( $pending ) || '' === $pending ) {
			return;
		}

		maca_backup_pro_api_schedule_flush();
	}

	/**
	 * Init: textdomain, updater, schema upgrades.
	 *
	 * @return void
	 */
	public function init(): void {
		// WordPress.org loads translations automatically for hosted plugins (WP 4.6+).
		$updater = MACA_BACKUP_PRO_PATH . 'includes/class-plugin-updater.php';
		if ( is_readable( $updater ) ) {
			require_once $updater;
			if ( function_exists( 'maca_backup_pro_register_updater' ) ) {
				maca_backup_pro_register_updater();
			}
		}

		Maca_Backup_Pro_Installer::maybe_upgrade();
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		( new Maca_Backup_Pro_REST_Controller() )->register_routes();
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Maca_Backup_Pro_Installer::install();
		Maca_Backup_Pro_Settings::local_backup_dir();
		Maca_Backup_Pro_Scheduler::instance()->reschedule();
		update_option( 'maca_backup_pro_plugin_version', MACA_BACKUP_PRO_VERSION, false );
		flush_rewrite_rules( false );

		require_once MACA_BACKUP_PRO_PATH . 'includes/maca-api.php';
		maca_backup_pro_api_on_activate();
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Maca_Backup_Pro_Scheduler::instance()->clear_all();

		require_once MACA_BACKUP_PRO_PATH . 'includes/maca-api.php';
		maca_backup_pro_api_on_deactivate();
	}
}
