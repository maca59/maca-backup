<?php
/**
 * Database SQL exporter / importer.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names cannot be prepared placeholders; queries use validated identifiers.

/**
 * Exports and restores MySQL tables in batches.
 */
class Maca_Backup_Pro_Database_Exporter {

	/**
	 * List all tables in the current database.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $tables ) ? array_map( 'strval', $tables ) : array();
	}

	/**
	 * Export a batch of tables into an SQL file (append mode).
	 *
	 * @param string   $sql_path Destination .sql path.
	 * @param string[] $tables   Tables to export this batch.
	 * @return array{bytes:int, tables:int}
	 */
	public static function export_tables( string $sql_path, array $tables ): array {
		global $wpdb;

		$dir = dirname( $sql_path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$handle = fopen( $sql_path, file_exists( $sql_path ) ? 'ab' : 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return array( 'bytes' => 0, 'tables' => 0 );
		}

		if ( 0 === filesize( $sql_path ) || false === filesize( $sql_path ) ) {
			$header = "-- maca BackUp SQL export\n-- Generated: " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
			fwrite( $handle, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		$bytes  = 0;
		$count  = 0;

		foreach ( $tables as $table ) {
			$table = preg_replace( '/[^A-Za-z0-9_\-]/', '', $table );
			if ( '' === $table ) {
				continue;
			}

			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $create || empty( $create[1] ) ) {
				continue;
			}

			$chunk  = "\nDROP TABLE IF EXISTS `{$table}`;\n{$create[1]};\n\n";
			$bytes += (int) fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			$offset = 0;
			$limit  = 200;
			while ( true ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $limit, $offset ),
					ARRAY_A
				);
				if ( empty( $rows ) ) {
					break;
				}

				foreach ( $rows as $row ) {
					$cols = array_map(
						static function ( $col ) {
							return '`' . str_replace( '`', '``', (string) $col ) . '`';
						},
						array_keys( $row )
					);
					$vals = array();
					foreach ( $row as $value ) {
						if ( null === $value ) {
							$vals[] = 'NULL';
						} else {
							$vals[] = "'" . esc_sql( (string) $value ) . "'";
						}
					}
					$line   = 'INSERT INTO `' . $table . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n";
					$bytes += (int) fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				}

				$offset += $limit;
				if ( count( $rows ) < $limit ) {
					break;
				}
			}

			++$count;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return array( 'bytes' => $bytes, 'tables' => $count );
	}

	/**
	 * Restore SQL file table-by-table (or statement batches).
	 *
	 * @param string $sql_path SQL file.
	 * @param int    $offset   Byte offset to resume from.
	 * @param int    $max_statements Max statements this tick.
	 * @return array{offset:int, done:bool, statements:int, error?:string}
	 */
	public static function restore_batch( string $sql_path, int $offset = 0, int $max_statements = 50 ): array {
		global $wpdb;

		if ( ! is_readable( $sql_path ) ) {
			return array(
				'offset'      => $offset,
				'done'        => true,
				'statements'  => 0,
				'error'       => __( 'SQL file not readable.', 'maca-backup' ),
			);
		}

		$content = file_get_contents( $sql_path, false, null, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content || '' === $content ) {
			return array( 'offset' => $offset, 'done' => true, 'statements' => 0 );
		}

		$statements = self::split_sql( $content );
		$executed   = 0;
		$consumed   = 0;

		foreach ( $statements as $stmt ) {
			if ( $executed >= $max_statements ) {
				break;
			}
			$sql = trim( $stmt['sql'] );
			$consumed += $stmt['length'];
			if ( '' === $sql || str_starts_with( $sql, '--' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false === $result && ! empty( $wpdb->last_error ) ) {
				return array(
					'offset'     => $offset + $consumed,
					'done'       => false,
					'statements' => $executed,
					'error'      => $wpdb->last_error,
				);
			}
			++$executed;
		}

		$new_offset = $offset + $consumed;
		$filesize   = (int) filesize( $sql_path );
		$done       = $new_offset >= $filesize || empty( $statements );

		return array(
			'offset'     => $new_offset,
			'done'       => $done,
			'statements' => $executed,
		);
	}

	/**
	 * Naive SQL splitter on semicolons outside quotes.
	 *
	 * @param string $sql SQL blob.
	 * @return array<int, array{sql:string, length:int}>
	 */
	private static function split_sql( string $sql ): array {
		$out     = array();
		$buffer  = '';
		$in_str  = false;
		$quote   = '';
		$length  = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $sql[ $i ];
			if ( $in_str ) {
				$buffer .= $ch;
				if ( $ch === $quote && ( 0 === $i || '\\' !== $sql[ $i - 1 ] ) ) {
					$in_str = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$in_str = true;
				$quote  = $ch;
				$buffer .= $ch;
				continue;
			}
			if ( ';' === $ch ) {
				$buffer .= $ch;
				$out[]   = array(
					'sql'    => $buffer,
					'length' => strlen( $buffer ),
				);
				$buffer = '';
				continue;
			}
			$buffer .= $ch;
		}

		return $out;
	}
}
