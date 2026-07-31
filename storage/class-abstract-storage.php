<?php
/**
 * Base storage provider.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for storage drivers.
 */
abstract class Maca_Backup_Pro_Abstract_Storage implements Maca_Backup_Pro_Storage_Provider {

	/**
	 * Provider settings slice.
	 *
	 * @return array<string, mixed>
	 */
	protected function settings(): array {
		$all = Maca_Backup_Pro_Settings::all();
		$id  = $this->id();
		return is_array( $all['storage'][ $id ] ?? null ) ? $all['storage'][ $id ] : array();
	}

	/**
	 * Decrypt a secret setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	protected function secret( string $key ): string {
		$settings = $this->settings();
		$value    = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
		return Maca_Backup_Pro_Settings::decrypt_secret( $value );
	}

	/**
	 * Default space info (unknown).
	 *
	 * @return array{free?:int,total?:int,used?:int}|null
	 */
	public function space_info(): ?array {
		return null;
	}
}
