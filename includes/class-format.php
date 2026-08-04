<?php
/**
 * Formatting helpers.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Human-readable formatting for admin UI and emails.
 */
class Maca_Backup_Pro_Format {

	/**
	 * Format a duration in seconds as hours, minutes and seconds.
	 *
	 * @param int $seconds Elapsed seconds.
	 * @return string
	 */
	public static function duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$secs    = $seconds % MINUTE_IN_SECONDS;

		if ( $hours > 0 ) {
			return sprintf(
				/* translators: 1: hours, 2: minutes, 3: seconds */
				__( '%1$d h %2$d min %3$d s', 'maca-backup' ),
				$hours,
				$minutes,
				$secs
			);
		}

		if ( $minutes > 0 ) {
			return sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$d min %2$d s', 'maca-backup' ),
				$minutes,
				$secs
			);
		}

		return sprintf(
			/* translators: %d: seconds */
			__( '%d s', 'maca-backup' ),
			$secs
		);
	}

	/**
	 * Format a MySQL datetime (site local via current_time) for display.
	 *
	 * @param string $mysql_datetime Datetime string.
	 * @param string $format         PHP date format.
	 * @return string
	 */
	public static function datetime_local( string $mysql_datetime, string $format = 'Y-m-d H:i' ): string {
		$mysql_datetime = trim( $mysql_datetime );
		if ( '' === $mysql_datetime || '0000-00-00 00:00:00' === $mysql_datetime ) {
			return '—';
		}

		$dt = date_create( $mysql_datetime, wp_timezone() );
		if ( ! $dt ) {
			return $mysql_datetime;
		}

		return wp_date( $format, $dt->getTimestamp() );
	}

	/**
	 * Format a stored backup checksum for admin display (CRC32 preferred).
	 *
	 * @param string               $checksum Raw checksum column.
	 * @param array<string, mixed> $manifest Decoded manifest (optional).
	 * @return string
	 */
	public static function backup_crc( string $checksum, array $manifest = array() ): string {
		$from_manifest = isset( $manifest['crc32'] ) ? strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $manifest['crc32'] ) ?? '' ) : '';
		if ( is_string( $from_manifest ) && 8 === strlen( $from_manifest ) ) {
			return strtoupper( $from_manifest );
		}

		$raw = strtolower( preg_replace( '/[^a-f0-9]/i', '', $checksum ) ?? '' );
		if ( 8 === strlen( $raw ) ) {
			return strtoupper( $raw );
		}

		return '';
	}

	/**
	 * Short label for checksum column (CRC or truncated SHA-256 for legacy rows).
	 *
	 * @param object $backup Backup row.
	 * @return string
	 */
	public static function backup_checksum_label( object $backup ): string {
		$manifest = array();
		if ( ! empty( $backup->manifest ) ) {
			$decoded = json_decode( (string) $backup->manifest, true );
			if ( is_array( $decoded ) ) {
				$manifest = $decoded;
			}
		}

		$crc = self::backup_crc( (string) ( $backup->checksum ?? '' ), $manifest );
		if ( '' !== $crc ) {
			return $crc;
		}

		$sha = strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) ( $backup->checksum ?? '' ) ) ?? '' );
		if ( 64 === strlen( $sha ) ) {
			return strtoupper( substr( $sha, 0, 8 ) ) . '…' . strtoupper( substr( $sha, -4 ) );
		}

		return '—';
	}
}
