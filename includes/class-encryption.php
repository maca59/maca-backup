<?php
/**
 * Encrypt/decrypt API keys and backup archives.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * OpenSSL-based encryption using WordPress AUTH_KEY salts / passphrase.
 */
class Maca_Backup_Pro_Encryption {

	private const METHOD     = 'AES-256-CBC';
	private const FILE_MAGIC = 'macaenc1';
	private const FILE_METHOD = 'aes-256-gcm';

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Plain value.
	 * @return string Base64 payload or empty string.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();
		$iv  = random_bytes( 16 );
		$raw = openssl_encrypt( $plaintext, self::METHOD, $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $raw ) {
			return '';
		}

		return base64_encode( $iv . $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored payload.
	 *
	 * @param string $payload Encrypted payload.
	 * @return string
	 */
	public static function decrypt( string $payload ): string {
		if ( '' === $payload ) {
			return '';
		}

		$decoded = base64_decode( $payload, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $decoded || strlen( $decoded ) < 17 ) {
			return '';
		}

		$iv  = substr( $decoded, 0, 16 );
		$raw = substr( $decoded, 16 );
		$out = openssl_decrypt( $raw, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $out ? '' : $out;
	}

	/**
	 * Encrypt a file with AES-256-GCM using a passphrase.
	 *
	 * Format: magic(8) + salt(16) + iv(12) + tag(16) + ciphertext
	 *
	 * @param string $src        Source path.
	 * @param string $dest       Destination path.
	 * @param string $passphrase Passphrase.
	 * @return true|\WP_Error
	 */
	public static function encrypt_file( string $src, string $dest, string $passphrase ) {
		if ( ! is_readable( $src ) ) {
			return new WP_Error( 'src', __( 'Source file not readable.', 'maca-backup' ) );
		}
		if ( '' === $passphrase ) {
			return new WP_Error( 'pass', __( 'Passphrase required for encryption.', 'maca-backup' ) );
		}

		$plain = file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $plain ) {
			return new WP_Error( 'read', __( 'Could not read source file.', 'maca-backup' ) );
		}

		$salt = random_bytes( 16 );
		$iv   = random_bytes( 12 );
		$key  = hash_pbkdf2( 'sha256', $passphrase, $salt, 100000, 32, true );
		$tag  = '';
		$cipher = openssl_encrypt( $plain, self::FILE_METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $cipher ) {
			return new WP_Error( 'enc', __( 'Encryption failed.', 'maca-backup' ) );
		}

		$payload = self::FILE_MAGIC . $salt . $iv . $tag . $cipher;
		$ok      = file_put_contents( $dest, $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $ok ) {
			return new WP_Error( 'write', __( 'Could not write encrypted file.', 'maca-backup' ) );
		}

		return true;
	}

	/**
	 * Decrypt an encrypted backup file.
	 *
	 * @param string $src        Encrypted path.
	 * @param string $dest       Output path.
	 * @param string $passphrase Passphrase.
	 * @return true|\WP_Error
	 */
	public static function decrypt_file( string $src, string $dest, string $passphrase ) {
		if ( ! is_readable( $src ) ) {
			return new WP_Error( 'src', __( 'Encrypted file not readable.', 'maca-backup' ) );
		}
		if ( '' === $passphrase ) {
			return new WP_Error( 'pass', __( 'Passphrase required for decryption.', 'maca-backup' ) );
		}

		$data = file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || strlen( $data ) < 52 || ! str_starts_with( $data, self::FILE_MAGIC ) ) {
			return new WP_Error( 'format', __( 'Invalid encrypted backup format.', 'maca-backup' ) );
		}

		$salt   = substr( $data, 8, 16 );
		$iv     = substr( $data, 24, 12 );
		$tag    = substr( $data, 36, 16 );
		$cipher = substr( $data, 52 );
		$key    = hash_pbkdf2( 'sha256', $passphrase, $salt, 100000, 32, true );
		$plain  = openssl_decrypt( $cipher, self::FILE_METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $plain ) {
			return new WP_Error( 'dec', __( 'Decryption failed — check passphrase.', 'maca-backup' ) );
		}

		$ok = file_put_contents( $dest, $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $ok ) {
			return new WP_Error( 'write', __( 'Could not write decrypted file.', 'maca-backup' ) );
		}

		return true;
	}

	/**
	 * Derive a 32-byte key from WP salts.
	 *
	 * @return string
	 */
	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'maca' )
			. ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'backup-pro' );

		return hash( 'sha256', $material, true );
	}
}
