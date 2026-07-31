<?php
/**
 * Activity logger.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes structured log rows.
 */
class Maca_Backup_Pro_Logger {

	/**
	 * Log an event.
	 *
	 * @param string               $level   info|warning|error|success.
	 * @param string               $message Human message.
	 * @param array<string, mixed> $context Extra context.
	 * @return int Insert ID.
	 */
	public static function log( string $level, string $message, array $context = array() ): int {
		return Maca_Backup_Pro_Logs_Table::insert(
			array(
				'level'      => sanitize_key( $level ),
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'backup_id'  => isset( $context['backup_id'] ) ? (int) $context['backup_id'] : 0,
				'job_id'     => isset( $context['job_id'] ) ? (int) $context['job_id'] : 0,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Convenience wrappers.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return int
	 */
	public static function info( string $message, array $context = array() ): int {
		return self::log( 'info', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return int
	 */
	public static function success( string $message, array $context = array() ): int {
		return self::log( 'success', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return int
	 */
	public static function warning( string $message, array $context = array() ): int {
		return self::log( 'warning', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Context.
	 * @return int
	 */
	public static function error( string $message, array $context = array() ): int {
		return self::log( 'error', $message, $context );
	}
}
