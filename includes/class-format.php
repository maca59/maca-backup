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
				__( '%1$d h %2$d min %3$d s', 'maca-backup-pro' ),
				$hours,
				$minutes,
				$secs
			);
		}

		if ( $minutes > 0 ) {
			return sprintf(
				/* translators: 1: minutes, 2: seconds */
				__( '%1$d min %2$d s', 'maca-backup-pro' ),
				$minutes,
				$secs
			);
		}

		return sprintf(
			/* translators: %d: seconds */
			__( '%d s', 'maca-backup-pro' ),
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
}
