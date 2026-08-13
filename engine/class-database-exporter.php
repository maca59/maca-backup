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
			$order  = self::stable_select_order( $table );
			while ( true ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare( "SELECT * FROM `{$table}` {$order} LIMIT %d OFFSET %d", $limit, $offset ),
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

		// Keep dump table names (e.g. wp_posts). Remapping onto the live prefix while
		// WordPress is running DROPs clk_*_options/posts mid-request and aborts on
		// duplicate option_name (MPSUM) before wp_posts is replayed.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET NAMES utf8mb4' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query( $sql );
			if ( false === $result && ! empty( $wpdb->last_error ) ) {
				// Duplicate unique keys (e.g. options.option_name) must not abort the dump —
				// that left wp_posts unrestored (maca.se → testsite, option MPSUM).
				if ( self::is_duplicate_key_error( (string) $wpdb->last_error ) || self::is_table_exists_error( (string) $wpdb->last_error ) ) {
					if ( class_exists( 'Maca_Backup_Pro_Logger', false ) ) {
						Maca_Backup_Pro_Logger::warning(
							sprintf(
								/* translators: %s: MySQL error */
								__( 'Skipped duplicate SQL row during restore: %s', 'maca-backup' ),
								(string) $wpdb->last_error
							)
						);
					}
					++$executed;
					continue;
				}
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
	 * Detect the table prefix used inside a SQL dump (from *_posts / *_options).
	 *
	 * Scans the file in chunks so large leading tables cannot hide the prefix.
	 *
	 * @param string $sql_path Path to database.sql.
	 * @return string Prefix including trailing underscore (e.g. wp_), or empty if unknown.
	 */
	public static function detect_table_prefix( string $sql_path ): string {
		if ( ! is_readable( $sql_path ) ) {
			return '';
		}

		$handle = fopen( $sql_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return '';
		}

		$posts_prefix   = '';
		$options_prefix = '';
		$tail           = '';
		$max_scan       = 8 * 1024 * 1024; // Cap scan for huge dumps.
		$scanned        = 0;

		while ( ! feof( $handle ) && $scanned < $max_scan ) {
			$chunk = fread( $handle, 512 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			$scanned += strlen( $chunk );
			$hay     = $tail . $chunk;

			if ( '' === $posts_prefix && preg_match( '/(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO)\s+`([A-Za-z0-9_]+_)posts`/i', $hay, $m ) ) {
				$posts_prefix = (string) $m[1];
			}
			if ( '' === $options_prefix && preg_match( '/(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+INTO)\s+`([A-Za-z0-9_]+_)options`/i', $hay, $m ) ) {
				$options_prefix = (string) $m[1];
			}
			if ( '' !== $posts_prefix && '' !== $options_prefix ) {
				break;
			}
			$tail = substr( $hay, -96 );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Prefer posts prefix (content), then options; require trailing underscore.
		foreach ( array( $posts_prefix, $options_prefix ) as $prefix ) {
			if ( is_string( $prefix ) && strlen( $prefix ) >= 2 && str_ends_with( $prefix, '_' ) ) {
				return $prefix;
			}
		}
		return '';
	}

	/**
	 * Put WordPress core tables first so content restores early and prefix detection is reliable.
	 *
	 * @param string[] $tables All tables.
	 * @return string[]
	 */
	public static function prioritize_tables( array $tables ): array {
		global $wpdb;

		$core = array(
			$wpdb->options,
			$wpdb->users,
			$wpdb->usermeta,
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->links,
		);

		$first  = array();
		$tables = array_values( array_unique( array_map( 'strval', $tables ) ) );
		foreach ( $core as $name ) {
			$name = (string) $name;
			if ( '' !== $name && in_array( $name, $tables, true ) ) {
				$first[] = $name;
			}
		}

		$rest   = array_values( array_diff( $tables, $first ) );
		$plugin = array();
		$other  = array();
		foreach ( $rest as $name ) {
			if ( str_contains( $name, 'maca_backup_' ) ) {
				$plugin[] = $name;
			} else {
				$other[] = $name;
			}
		}

		return array_merge( $first, $other, $plugin );
	}

	/**
	 * Rename every dump-prefixed table onto the live prefix (atomic swap).
	 *
	 * SQL replay leaves source names (wp_posts) intact so WordPress can keep using
	 * the destination tables until the dump is complete. Then we DROP live copies
	 * and RENAME. Plugin control tables (maca_backup_*) stay on the live prefix.
	 *
	 * @param string $from_prefix Dump prefix (e.g. wp_).
	 * @param string $to_prefix   Live $wpdb->prefix.
	 * @return array{adopted:int, tables:string[], from_prefix?:string}
	 */
	public static function adopt_orphan_prefixed_tables( string $from_prefix, string $to_prefix ): array {
		global $wpdb;

		$out = array(
			'adopted' => 0,
			'tables'  => array(),
		);

		$from_prefix = (string) $from_prefix;
		$to_prefix   = (string) $to_prefix;
		if ( '' === $from_prefix || '' === $to_prefix || $from_prefix === $to_prefix ) {
			return $out;
		}
		if ( strlen( $from_prefix ) < 2 || ! str_ends_with( $from_prefix, '_' ) ) {
			return $out;
		}

		$from_len = strlen( $from_prefix );
		$all      = self::tables();
		$sources  = array();
		foreach ( $all as $table ) {
			$table = (string) $table;
			if ( ! str_starts_with( $table, $from_prefix ) ) {
				continue;
			}
			$suffix = substr( $table, $from_len );
			if ( '' === $suffix || str_starts_with( $suffix, 'maca_backup_' ) ) {
				continue;
			}
			$sources[] = $table;
		}

		if ( empty( $sources ) ) {
			return $out;
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $sources as $src ) {
			$suffix = substr( $src, $from_len );
			$dst    = $to_prefix . $suffix;
			$src_s  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $src );
			$dst_s  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $dst );
			if ( '' === $src_s || '' === $dst_s || $src_s === $dst_s ) {
				continue;
			}
			$all = self::tables();
			if ( in_array( $dst, $all, true ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$dst_s}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$renamed = $wpdb->query( "RENAME TABLE `{$src_s}` TO `{$dst_s}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( false !== $renamed ) {
				++$out['adopted'];
				$out['tables'][] = $src;
			} elseif ( ! empty( $wpdb->last_error ) && class_exists( 'Maca_Backup_Pro_Logger', false ) ) {
				Maca_Backup_Pro_Logger::warning(
					sprintf(
						/* translators: 1: source table, 2: dest table, 3: MySQL error */
						__( 'Could not rename %1$s → %2$s: %3$s', 'maca-backup' ),
						$src,
						$dst,
						(string) $wpdb->last_error
					)
				);
			}
		}

		if ( $out['adopted'] > 0 ) {
			$out['from_prefix'] = $from_prefix;
		}

		return $out;
	}

	/**
	 * Scan for a dump-prefixed posts table richer than live and adopt its core set.
	 *
	 * @param string $to_prefix Live prefix.
	 * @return array{adopted:int, tables:string[], from_prefix?:string}
	 */
	public static function adopt_richest_posts_prefix( string $to_prefix ): array {
		global $wpdb;

		$empty = array(
			'adopted' => 0,
			'tables'  => array(),
		);
		$to_prefix = (string) $to_prefix;
		$live      = $to_prefix . 'posts';
		$best      = '';
		$best_pages = 0;

		$live_pages = 0;
		$all        = self::tables();
		if ( in_array( $live, $all, true ) ) {
			$live_safe  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $live );
			$live_pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$live_safe}` WHERE post_type = 'page' AND post_status NOT IN ('auto-draft','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		foreach ( $all as $table ) {
			if ( $table === $live || ! str_ends_with( $table, 'posts' ) || str_contains( $table, 'maca_backup_' ) ) {
				continue;
			}
			$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '', $table );
			if ( '' === $safe ) {
				continue;
			}
			$pages = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$safe}` WHERE post_type = 'page' AND post_status NOT IN ('auto-draft','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $pages > $best_pages ) {
				$best_pages = $pages;
				$best       = $table;
			}
		}

		if ( '' === $best || $best_pages < 1 ) {
			return $empty;
		}
		// Fallback only: do not replace a populated live site with a smaller leftover table.
		if ( $best_pages <= $live_pages && $live_pages >= 5 ) {
			return $empty;
		}

		$from_prefix = substr( $best, 0, -strlen( 'posts' ) );
		if ( '' === $from_prefix || ! str_ends_with( $from_prefix, '_' ) ) {
			return $empty;
		}

		$result = self::adopt_orphan_prefixed_tables( $from_prefix, $to_prefix );
		if ( ! empty( $result['adopted'] ) ) {
			$result['from_prefix'] = $from_prefix;
		}
		return $result;
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
	 * Stable ORDER BY so LIMIT/OFFSET export cannot duplicate or skip rows.
	 *
	 * @param string $table Table name (already sanitized).
	 * @return string SQL fragment starting with ORDER BY, or empty.
	 */
	private static function stable_select_order( string $table ): string {
		global $wpdb;

		$key = $wpdb->get_row( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$col = is_array( $key ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $key['Column_name'] ?? '' ) ) : '';
		if ( '' !== $col ) {
			return "ORDER BY `{$col}` ASC";
		}
		return 'ORDER BY 1 ASC';
	}

	/**
	 * MySQL duplicate unique/primary key (errno 1062).
	 *
	 * @param string $error $wpdb->last_error.
	 * @return bool
	 */
	private static function is_duplicate_key_error( string $error ): bool {
		global $wpdb;
		if ( isset( $wpdb->last_errno ) && 1062 === (int) $wpdb->last_errno ) {
			return true;
		}
		return (bool) preg_match( '/duplicate entry/i', $error );
	}

	/**
	 * MySQL "table already exists" (errno 1050) after a missed DROP.
	 *
	 * @param string $error $wpdb->last_error.
	 * @return bool
	 */
	private static function is_table_exists_error( string $error ): bool {
		global $wpdb;
		if ( isset( $wpdb->last_errno ) && 1050 === (int) $wpdb->last_errno ) {
			return true;
		}
		return (bool) preg_match( '/already exists/i', $error );
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
