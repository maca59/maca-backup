<?php
/**
 * Plugin settings.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings store with defaults.
 */
class Maca_Backup_Pro_Settings {

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schedule'            => 'manual',
			'custom_cron'         => '',
			'schedule_time_utc'   => '03:00',
			'schedule_weekday'    => 1,
			'schedule_dom'        => 1,
			'backup_type'         => 'full',
			'backup_schedules'    => array(),
			'storage_provider'    => 'local',
			'retention_count'     => 10,
			'zip_split_mb'        => 400,
			'chunk_files'         => 400,
			'chunk_tables'        => 5,
			'encrypt_backups'     => false,
			'backup_passphrase'   => '',
			'pre_update_backup'   => false,
			'pre_update_retention'=> 5,
			'hub_enabled'         => false,
			'exclude_paths'       => array(
				'wp-content/cache',
				'wp-content/upgrade',
				'wp-content/maca-backups',
			),
			'email_enabled'       => true,
			'email_recipients'    => '',
			'email_on_success'    => true,
			'email_on_failure'    => true,
			'email_on_restore_ok'  => true,
			'email_on_restore_fail'=> true,
			'storage'             => array(
				'local'         => array( 'path' => '' ),
				'ftp'           => array(
					'host'     => '',
					'port'     => 21,
					'user'     => '',
					'pass'     => '',
					'path'     => '/',
					'passive'  => true,
				),
				'sftp'          => array(
					'host'     => '',
					'port'     => 22,
					'user'     => '',
					'pass'     => '',
					'path'     => '/',
					'key'      => '',
				),
				'google_drive'  => array(
					'client_id'     => '',
					'client_secret' => '',
					'refresh_token' => '',
					'folder_id'     => '',
				),
				'dropbox'       => array(
					'access_token' => '',
					'path'         => '/maca-backups',
				),
				'onedrive'      => array(
					'client_id'     => '',
					'client_secret' => '',
					'refresh_token' => '',
					'folder_path'   => '/maca-backups',
				),
				's3'            => array(
					'access_key' => '',
					'secret_key' => '',
					'bucket'     => '',
					'region'     => 'us-east-1',
					'endpoint'   => '',
					'prefix'     => 'maca-backups',
					'path_style' => false,
				),
			),
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( MACA_BACKUP_PRO_OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return self::deep_merge( self::defaults(), $stored );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key (dot notation supported for top-level).
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Update settings (partial merge). Sensitive keys are encrypted.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function update( array $input ): array {
		$current = self::all();

		// List settings must replace wholesale (deep_merge would keep stale indexes).
		if ( isset( $input['backup_schedules'] ) && is_array( $input['backup_schedules'] ) ) {
			$current['backup_schedules'] = $input['backup_schedules'];
			unset( $input['backup_schedules'] );
		}

		$merged = self::deep_merge( $current, $input );
		$merged = self::encrypt_secrets( $merged );

		update_option( MACA_BACKUP_PRO_OPTION_KEY, $merged, false );

		return $merged;
	}

	/**
	 * Absolute local backup directory.
	 *
	 * Always under the uploads directory ({uploads}/maca-backups), optionally a
	 * subdirectory of that folder. Arbitrary paths outside uploads are ignored
	 * (wordpress.org filesystem guidelines).
	 *
	 * @return string
	 */
	public static function local_backup_dir(): string {
		$default = Maca_Backup_Pro_Paths::default_backup_dir();
		if ( '' === $default ) {
			return '';
		}

		$settings = self::all();
		$custom   = isset( $settings['storage']['local']['path'] ) ? trim( (string) $settings['storage']['local']['path'] ) : '';

		$dir = $default;
		if ( '' !== $custom ) {
			$candidate = wp_normalize_path( untrailingslashit( $custom ) );
			// Allow only paths inside uploads (typically …/uploads/maca-backups[…]).
			if ( Maca_Backup_Pro_Paths::is_under_uploads( $candidate ) ) {
				$dir = $candidate;
			}
		}

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( is_dir( $dir ) ) {
			self::protect_directory( $dir );
		}

		return $dir;
	}

	/**
	 * Write index.php + .htaccess to block direct access (uploads tree only).
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function protect_directory( string $dir ): void {
		$dir = wp_normalize_path( untrailingslashit( $dir ) );
		if ( '' === $dir || ! Maca_Backup_Pro_Paths::is_under_uploads( $dir ) ) {
			return;
		}

		if ( ! is_dir( $dir ) ) {
			return;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file inside uploads.
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file inside uploads.
			file_put_contents( $htaccess, "Deny from all\n" );
		}
	}

	/**
	 * Encrypt known secret fields in storage config.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function encrypt_secrets( array $settings ): array {
		$secret_paths = array(
			array( 'ftp', 'pass' ),
			array( 'sftp', 'pass' ),
			array( 'sftp', 'key' ),
			array( 'google_drive', 'client_secret' ),
			array( 'google_drive', 'refresh_token' ),
			array( 'dropbox', 'access_token' ),
			array( 'onedrive', 'client_secret' ),
			array( 'onedrive', 'refresh_token' ),
			array( 's3', 'secret_key' ),
		);

		// Encrypt backup passphrase at rest.
		if ( ! empty( $settings['backup_passphrase'] ) && is_string( $settings['backup_passphrase'] ) ) {
			$pp = (string) $settings['backup_passphrase'];
			if ( ! str_starts_with( $pp, 'enc:' ) ) {
				$settings['backup_passphrase'] = 'enc:' . Maca_Backup_Pro_Encryption::encrypt( $pp );
			}
		}

		foreach ( $secret_paths as $path ) {
			[ $provider, $field ] = $path;
			if ( empty( $settings['storage'][ $provider ][ $field ] ) ) {
				continue;
			}
			$value = (string) $settings['storage'][ $provider ][ $field ];
			// Skip if already looks encrypted (base64 with enough length) — re-encrypt plaintext only when marked.
			if ( str_starts_with( $value, 'enc:' ) ) {
				continue;
			}
			$settings['storage'][ $provider ][ $field ] = 'enc:' . Maca_Backup_Pro_Encryption::encrypt( $value );
		}

		return $settings;
	}

	/**
	 * Decrypt a stored secret (strips enc: prefix).
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt_secret( string $value ): string {
		if ( str_starts_with( $value, 'enc:' ) ) {
			return Maca_Backup_Pro_Encryption::decrypt( substr( $value, 4 ) );
		}

		return $value;
	}

	/**
	 * Plaintext backup passphrase (empty if unset).
	 *
	 * @return string
	 */
	public static function backup_passphrase(): string {
		return self::decrypt_secret( (string) self::get( 'backup_passphrase', '' ) );
	}

	/**
	 * Recursive merge preserving nested arrays.
	 *
	 * @param array<string, mixed> $base  Base.
	 * @param array<string, mixed> $over  Override.
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}
}
