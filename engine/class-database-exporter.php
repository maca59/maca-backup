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
			throw new RuntimeException(
				esc_html__( 'Could not write database.sql — full site backup requires the entire database.', 'maca-backup' )
			);
		}

		// Clear PHP stat cache so an empty brand-new file is detected correctly.
		clearstatcache( true, $sql_path );
		$size_now = file_exists( $sql_path ) ? (int) filesize( $sql_path ) : 0;
		if ( $size_now < 1 ) {
			$header = "-- maca BackUp SQL export\n-- Generated: " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
			fwrite( $handle, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		$bytes = 0;
		$count = 0;

		foreach ( $tables as $table ) {
			$table = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $table );
			if ( '' === $table ) {
				continue;
			}

			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $create || empty( $create[1] ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: table name */
						esc_html__( 'Could not export database table %s.', 'maca-backup' ),
						esc_html( $table )
					)
				);
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
							// Prefer $wpdb escape so binary/serialized meta survives round-trip.
							$vals[] = "'" . $wpdb->_real_escape( (string) $value ) . "'";
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
	 * @param string      $sql_path        SQL file.
	 * @param int         $offset          Byte offset to resume from.
	 * @param int         $max_statements  Max statements this tick.
	 * @param string|null $source_prefix   Table prefix inside the dump (e.g. wp_). Null = auto-detect.
	 * @return array{offset:int, done:bool, statements:int, error?:string, source_prefix?:string}
	 */
	public static function restore_batch( string $sql_path, int $offset = 0, int $max_statements = 50, ?string $source_prefix = null ): array {
		global $wpdb;

		if ( ! is_readable( $sql_path ) ) {
			return array(
				'offset'     => $offset,
				'done'       => false,
				'statements' => 0,
				'error'      => __( 'SQL file not readable.', 'maca-backup' ),
			);
		}

		$filesize = (int) filesize( $sql_path );
		if ( $offset >= $filesize ) {
			return array( 'offset' => $offset, 'done' => true, 'statements' => 0 );
		}

		if ( null === $source_prefix || '' === $source_prefix ) {
			$source_prefix = self::detect_table_prefix( $sql_path );
		}
		$dest_prefix = (string) $wpdb->prefix;

		// Read a growing window so huge single INSERTs still parse without loading the whole dump.
		$window     = 2 * 1024 * 1024;
		$max_window = 32 * 1024 * 1024;
		$content    = '';
		$statements = array();

		while ( $window <= $max_window ) {
			$read_len = min( $window, $filesize - $offset );
			$chunk    = file_get_contents( $sql_path, false, null, $offset, $read_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $chunk ) {
				return array(
					'offset'     => $offset,
					'done'       => false,
					'statements' => 0,
					'error'      => __( 'Could not read database.sql (I/O error). Retry the restore.', 'maca-backup' ),
					'source_prefix' => $source_prefix,
				);
			}
			if ( '' === $chunk ) {
				return array(
					'offset'        => $offset,
					'done'          => true,
					'statements'    => 0,
					'source_prefix' => $source_prefix,
				);
			}
			$content    = $chunk;
			$statements = self::split_sql( $content );
			if ( ! empty( $statements ) ) {
				break;
			}
			if ( $offset + strlen( $content ) >= $filesize ) {
				break;
			}
			$window *= 2;
		}

		$executed = 0;
		$consumed = 0;

		foreach ( $statements as $stmt ) {
			if ( $executed >= $max_statements ) {
				break;
			}
			$sql = trim( $stmt['sql'] );
			$consumed += $stmt['length'];
			if ( '' === $sql || str_starts_with( $sql, '--' ) ) {
				continue;
			}

			// Never overwrite the live plugin control plane mid-restore.
			if ( self::is_plugin_control_sql( $sql ) ) {
				++$executed;
				continue;
			}

			$sql = self::remap_table_prefix( $sql, $source_prefix, $dest_prefix );

			// After remap, skip live control tables again (dump may use a different prefix).
			if ( self::is_plugin_control_sql( $sql ) ) {
				++$executed;
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false === $result && ! empty( $wpdb->last_error ) ) {
				return array(
					'offset'        => $offset + $consumed,
					'done'          => false,
					'statements'    => $executed,
					'error'         => $wpdb->last_error,
					'source_prefix' => $source_prefix,
				);
			}
			++$executed;
		}

		$new_offset = $offset + $consumed;

		// Never treat "no complete statement in this chunk" as done while bytes remain —
		// that silently skipped the rest of the dump (including wp_posts).
		if ( $new_offset >= $filesize ) {
			$done = true;
		} elseif ( empty( $statements ) ) {
			$remain = trim( $content );
			// Trailing comments / whitespace are fine.
			if ( '' === $remain || (bool) preg_match( '/^(?:--[^\n]*\s*)+$/', $remain ) ) {
				return array(
					'offset'        => $filesize,
					'done'          => true,
					'statements'    => 0,
					'source_prefix' => $source_prefix,
				);
			}
			return array(
				'offset'        => $offset,
				'done'          => false,
				'statements'    => 0,
				'error'         => __( 'Could not parse the next SQL statement in database.sql (possible quote/escape issue or oversized row).', 'maca-backup' ),
				'source_prefix' => $source_prefix,
			);
		} else {
			$done = false;
		}

		return array(
			'offset'        => $new_offset,
			'done'          => $done,
			'statements'    => $executed,
			'source_prefix' => $source_prefix,
		);
	}

	/**
	 * Detect the table prefix used inside a SQL dump (from *_options table name).
	 *
	 * @param string $sql_path Path to database.sql.
	 * @return string Prefix including trailing underscore (e.g. wp_), or empty if unknown.
	 */
	public static function detect_table_prefix( string $sql_path ): string {
		if ( ! is_readable( $sql_path ) ) {
			return '';
		}
		$sample = file_get_contents( $sql_path, false, null, 0, 256 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $sample ) || '' === $sample ) {
			return '';
		}
		if ( preg_match( '/(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO)\s+`([A-Za-z0-9_]+_)options`/i', $sample, $m ) ) {
			return (string) $m[1];
		}
		if ( preg_match( '/(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO)\s+`([A-Za-z0-9_]+_)posts`/i', $sample, $m ) ) {
			return (string) $m[1];
		}
		return '';
	}

	/**
	 * Count INSERT statements targeting a *posts table (streamed, for diagnostics).
	 *
	 * @param string $sql_path SQL dump.
	 * @return int
	 */
	public static function count_posts_inserts( string $sql_path ): int {
		if ( ! is_readable( $sql_path ) ) {
			return 0;
		}
		$handle = fopen( $sql_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return 0;
		}
		$count  = 0;
		$buffer = '';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			$buffer .= $chunk;
			if ( preg_match_all( '/INSERT\s+INTO\s+`[^`]*posts`/i', $buffer, $m ) ) {
				$count += count( $m[0] );
			}
			// Keep a small tail so matches spanning the chunk boundary are not lost.
			$buffer = substr( $buffer, -64 );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count;
	}

	/**
	 * Rewrite backtick-quoted table names from dump prefix to live prefix.
	 *
	 * @param string $sql    One SQL statement.
	 * @param string $from   Source prefix (e.g. wp_).
	 * @param string $to     Destination prefix (e.g. xyz_).
	 * @return string
	 */
	public static function remap_table_prefix( string $sql, string $from, string $to ): string {
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || '' === $to || $from === $to ) {
			return $sql;
		}
		// Only touch identifiers: `prefix...`
		return str_replace( '`' . $from, '`' . $to, $sql );
	}

	/**
	 * Statements that would DROP/CREATE/INSERT this plugin's live tables.
	 *
	 * Restoring them mid-job deletes the active restore row and aborts the migration.
	 *
	 * @param string $sql Statement.
	 * @return bool
	 */
	public static function is_plugin_control_sql( string $sql ): bool {
		return (bool) preg_match(
			'/`[^`]*maca_backup_(?:jobs|backups|logs)`/i',
			$sql
		);
	}

	/**
	 * Split SQL on semicolons outside quotes (backslash-aware).
	 *
	 * @param string $sql SQL blob.
	 * @return array<int, array{sql:string, length:int}>
	 */
	private static function split_sql( string $sql ): array {
		$out    = array();
		$buffer = '';
		$in_str = false;
		$quote  = '';
		$length = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {
			$ch = $sql[ $i ];
			if ( $in_str ) {
				$buffer .= $ch;
				if ( $ch === $quote && ! self::is_escaped_at( $sql, $i ) ) {
					$in_str = false;
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				$in_str  = true;
				$quote   = $ch;
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

	/**
	 * Whether the character at $index is escaped by an odd number of backslashes.
	 *
	 * @param string $sql   Full string.
	 * @param int    $index Character index.
	 * @return bool
	 */
	private static function is_escaped_at( string $sql, int $index ): bool {
		$slashes = 0;
		for ( $j = $index - 1; $j >= 0 && '\\' === $sql[ $j ]; $j-- ) {
			++$slashes;
		}
		return ( $slashes % 2 ) === 1;
	}
}
