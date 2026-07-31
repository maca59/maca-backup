<?php
/**
 * Storage provider interface.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contract for modular backup destinations.
 */
interface Maca_Backup_Pro_Storage_Provider {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether the provider is configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Upload a local file to remote path.
	 *
	 * @param string $local_path  Absolute local file.
	 * @param string $remote_path Relative remote path.
	 * @return string|\WP_Error Remote identifier/path on success.
	 */
	public function upload( string $local_path, string $remote_path );

	/**
	 * Download remote file to local destination.
	 *
	 * @param string $remote_path Remote identifier/path.
	 * @param string $local_path  Destination.
	 * @return true|\WP_Error
	 */
	public function download( string $remote_path, string $local_path );

	/**
	 * Delete a remote object.
	 *
	 * @param string $remote_path Remote path.
	 * @return true|\WP_Error
	 */
	public function delete( string $remote_path );

	/**
	 * Free space info when available.
	 *
	 * @return array{free?:int,total?:int,used?:int}|null
	 */
	public function space_info(): ?array;
}
