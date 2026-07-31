<?php
/**
 * Storage provider registry.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and resolves storage drivers.
 */
class Maca_Backup_Pro_Storage_Registry {

	/**
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * @var array<string, Maca_Backup_Pro_Storage_Provider>
	 */
	private array $providers = array();

	/**
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register built-in providers. Filter: maca_backup_pro_storage_providers.
	 *
	 * @return void
	 */
	public function boot(): void {
		$built_in = array(
			new Maca_Backup_Pro_Local_Storage(),
			new Maca_Backup_Pro_Ftp_Storage(),
			new Maca_Backup_Pro_Sftp_Storage(),
			new Maca_Backup_Pro_Google_Drive_Storage(),
			new Maca_Backup_Pro_Dropbox_Storage(),
			new Maca_Backup_Pro_OneDrive_Storage(),
			new Maca_Backup_Pro_S3_Storage(),
		);

		/**
		 * Filter registered storage providers.
		 *
		 * @param Maca_Backup_Pro_Storage_Provider[] $providers Providers.
		 */
		$providers = apply_filters( 'maca_backup_pro_storage_providers', $built_in );

		foreach ( $providers as $provider ) {
			if ( $provider instanceof Maca_Backup_Pro_Storage_Provider ) {
				$this->providers[ $provider->id() ] = $provider;
			}
		}
	}

	/**
	 * All providers.
	 *
	 * @return array<string, Maca_Backup_Pro_Storage_Provider>
	 */
	public function all(): array {
		return $this->providers;
	}

	/**
	 * Get one provider.
	 *
	 * @param string $id Provider ID.
	 * @return Maca_Backup_Pro_Storage_Provider|null
	 */
	public function get( string $id ): ?Maca_Backup_Pro_Storage_Provider {
		return $this->providers[ $id ] ?? null;
	}
}
