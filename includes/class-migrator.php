<?php
/**
 * Cross-site URL migration helpers (serialized-aware).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites site URLs after a database restore onto another host.
 */
class Maca_Backup_Pro_Migrator {

	/**
	 * Build from→to pairs from source/destination home + siteurl.
	 *
	 * @param string $source_home    Source home URL.
	 * @param string $source_siteurl Source siteurl.
	 * @param string $dest_home      Destination home URL.
	 * @param string $dest_siteurl   Destination siteurl.
	 * @return array<string, string> Map of from => to (longest first).
	 */
	public static function url_pairs( string $source_home, string $source_siteurl, string $dest_home, string $dest_siteurl ): array {
		$pairs = array();

		$add = static function ( string $from, string $to ) use ( &$pairs ): void {
			$from = untrailingslashit( trim( $from ) );
			$to   = untrailingslashit( trim( $to ) );
			if ( '' === $from || '' === $to || strtolower( $from ) === strtolower( $to ) ) {
				return;
			}
			$pairs[ $from ]       = $to;
			$pairs[ $from . '/' ] = $to . '/';
			// JSON-escaped slashes (page builders, theme mods).
			$pairs[ str_replace( '/', '\\/', $from ) ]       = str_replace( '/', '\\/', $to );
			$pairs[ str_replace( '/', '\\/', $from . '/' ) ] = str_replace( '/', '\\/', $to . '/' );
		};

		$add( $source_home, $dest_home );
		$add( $source_siteurl, $dest_siteurl );

		// Prefer longer keys first so https://a.example/path beats https://a.example.
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		return $pairs;
	}

	/**
	 * Replace URLs across common WP tables, then force home/siteurl options.
	 *
	 * @param array<string, string> $pairs      from => to.
	 * @param string                $dest_home  Final home.
	 * @param string                $dest_siteurl Final siteurl.
	 * @return array{replaced:int, tables:int}
	 */
	public static function rewrite_site_urls( array $pairs, string $dest_home, string $dest_siteurl ): array {
		global $wpdb;

		if ( empty( $pairs ) ) {
			self::force_site_urls( $dest_home, $dest_siteurl );
			return array( 'replaced' => 0, 'tables' => 0 );
		}

		$targets = array(
			$wpdb->posts         => array( 'post_content', 'post_excerpt', 'guid', 'post_content_filtered' ),
			$wpdb->postmeta      => array( 'meta_value' ),
			$wpdb->options       => array( 'option_value' ),
			$wpdb->comments      => array( 'comment_content', 'comment_author_url' ),
			$wpdb->commentmeta   => array( 'meta_value' ),
			$wpdb->termmeta      => array( 'meta_value' ),
			$wpdb->usermeta      => array( 'meta_value' ),
			$wpdb->terms         => array( 'name', 'slug' ),
		);

		if ( ! empty( $wpdb->term_taxonomy ) ) {
			$targets[ $wpdb->term_taxonomy ] = array( 'description' );
		}

		$replaced = 0;
		$tables   = 0;

		foreach ( $targets as $table => $columns ) {
			if ( ! is_string( $table ) || '' === $table || ! self::table_exists( $table ) ) {
				continue;
			}
			$primary = self::primary_key( $table );
			if ( '' === $primary ) {
				continue;
			}
			++$tables;
			$replaced += self::rewrite_table( $table, $primary, $columns, $pairs );
		}

		self::force_site_urls( $dest_home, $dest_siteurl );

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return array(
			'replaced' => $replaced,
			'tables'   => $tables,
		);
	}

	/**
	 * Force destination home/siteurl regardless of dump contents.
	 *
	 * @param string $home    Destination home.
	 * @param string $siteurl Destination siteurl.
	 * @return void
	 */
	public static function force_site_urls( string $home, string $siteurl ): void {
		$home    = untrailingslashit( $home );
		$siteurl = untrailingslashit( $siteurl );
		if ( '' !== $home ) {
			update_option( 'home', $home );
		}
		if ( '' !== $siteurl ) {
			update_option( 'siteurl', $siteurl );
		}
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		$table = preg_replace( '/[^A-Za-z0-9_\-]/', '', $table );
		if ( '' === $table ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_string( $found ) && $found === $table;
	}

	/**
	 * @param string $table Table name.
	 * @return string
	 */
	private static function primary_key( string $table ): string {
		global $wpdb;
		$table = preg_replace( '/[^A-Za-z0-9_\-]/', '', $table );
		$row   = $wpdb->get_row( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $row ) && ! empty( $row['Column_name'] ) ? (string) $row['Column_name'] : '';
	}

	/**
	 * @param string                $table   Table.
	 * @param string                $primary Primary key column.
	 * @param string[]              $columns Text columns.
	 * @param array<string, string> $pairs   Replacements.
	 * @return int Rows updated.
	 */
	private static function rewrite_table( string $table, string $primary, array $columns, array $pairs ): int {
		global $wpdb;

		$table   = preg_replace( '/[^A-Za-z0-9_\-]/', '', $table );
		$primary = preg_replace( '/[^A-Za-z0-9_\-]/', '', $primary );
		$cols    = array();
		foreach ( $columns as $col ) {
			$col = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $col );
			if ( '' !== $col ) {
				$cols[] = $col;
			}
		}
		if ( '' === $table || '' === $primary || empty( $cols ) ) {
			return 0;
		}

		$select = '`' . $primary . '`, `' . implode( '`, `', $cols ) . '`';
		$offset = 0;
		$limit  = 200;
		$updated = 0;

		while ( true ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT {$select} FROM `{$table}` LIMIT {$limit} OFFSET {$offset}",
				ARRAY_A
			);
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$id      = $row[ $primary ] ?? null;
				$changes = array();
				foreach ( $cols as $col ) {
					if ( ! isset( $row[ $col ] ) || ! is_string( $row[ $col ] ) ) {
						continue;
					}
					$original = $row[ $col ];
					$replaced = self::replace_value( $original, $pairs );
					if ( $replaced !== $original ) {
						$changes[ $col ] = $replaced;
					}
				}
				if ( empty( $changes ) ) {
					continue;
				}
				$ok = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$table,
					$changes,
					array( $primary => $id )
				);
				if ( false !== $ok ) {
					++$updated;
				}
			}

			$offset += $limit;
			if ( count( $rows ) < $limit ) {
				break;
			}
		}

		return $updated;
	}

	/**
	 * Replace in a scalar / serialized / JSON-ish string.
	 *
	 * @param string                $value Original.
	 * @param array<string, string> $pairs from => to.
	 * @return string
	 */
	public static function replace_value( string $value, array $pairs ): string {
		if ( '' === $value || empty( $pairs ) ) {
			return $value;
		}

		// Skip pure numbers / tiny values.
		if ( strlen( $value ) < 3 ) {
			return $value;
		}

		$data = self::maybe_unserialize( $value );
		if ( is_array( $data ) || is_object( $data ) ) {
			$replaced = self::replace_deep( $data, $pairs );
			$out      = maybe_serialize( $replaced );
			return is_string( $out ) ? $out : $value;
		}

		$out = $value;
		foreach ( $pairs as $from => $to ) {
			if ( '' === $from ) {
				continue;
			}
			if ( str_contains( $out, $from ) ) {
				$out = str_replace( $from, $to, $out );
			}
		}
		return $out;
	}

	/**
	 * @param mixed                 $data  Nested data.
	 * @param array<string, string> $pairs Replacements.
	 * @return mixed
	 */
	private static function replace_deep( $data, array $pairs ) {
		if ( is_string( $data ) ) {
			return self::replace_value( $data, $pairs );
		}
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $key => $val ) {
				$new_key       = is_string( $key ) ? self::replace_value( $key, $pairs ) : $key;
				$out[ $new_key ] = self::replace_deep( $val, $pairs );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $key => $val ) {
				$data->$key = self::replace_deep( $val, $pairs );
			}
			return $data;
		}
		return $data;
	}

	/**
	 * Unserialize only when the string looks like PHP serialized data.
	 *
	 * @param string $value Value.
	 * @return mixed
	 */
	private static function maybe_unserialize( string $value ) {
		$trim = ltrim( $value );
		if ( '' === $trim ) {
			return $value;
		}
		$first = $trim[0];
		if ( ! in_array( $first, array( 'a', 'O', 's', 'i', 'd', 'b', 'N' ), true ) ) {
			return $value;
		}
		$un = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false === $un && 'b:0;' !== $value ) {
			return $value;
		}
		return $un;
	}
}
