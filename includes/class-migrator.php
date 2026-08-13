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
			// Refuse truncated / credential-shaped hosts (e.g. https://w:example.com).
			if ( ! self::is_plausible_site_url( $from ) || ! self::is_plausible_site_url( $to ) ) {
				return;
			}
			// Never replace ultra-short needles — they corrupt unrelated URLs.
			if ( strlen( $from ) < 12 ) {
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

		// Also cover http↔https and www↔apex so content URLs still match.
		foreach ( array( $source_home, $source_siteurl ) as $src ) {
			$src = untrailingslashit( trim( (string) $src ) );
			if ( '' === $src ) {
				continue;
			}
			$https = (string) preg_replace( '#^http://#i', 'https://', $src );
			$http  = (string) preg_replace( '#^https://#i', 'http://', $src );
			$add( $https, $dest_home );
			$add( $http, $dest_home );
			if ( preg_match( '#^(https?://)www\.(.+)$#i', $src, $m ) ) {
				$add( $m[1] . $m[2], $dest_home );
			} elseif ( preg_match( '#^(https?://)(.+)$#i', $src, $m ) ) {
				$add( $m[1] . 'www.' . $m[2], $dest_home );
			}
		}

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
	 * Writes via $wpdb first so a stale options object-cache (still holding the
	 * pre-restore destination URLs) cannot make update_option() no-op while the
	 * database still contains the source site URLs — which redirects login away.
	 *
	 * @param string $home    Destination home.
	 * @param string $siteurl Destination siteurl.
	 * @return void
	 */
	public static function force_site_urls( string $home, string $siteurl ): void {
		$home    = untrailingslashit( $home );
		$siteurl = untrailingslashit( $siteurl );

		if ( '' !== $home && ! self::is_plausible_site_url( $home ) ) {
			$home = '';
		}
		if ( '' !== $siteurl && ! self::is_plausible_site_url( $siteurl ) ) {
			$siteurl = '';
		}

		self::bust_options_cache();

		if ( '' !== $home ) {
			self::write_option_value( 'home', $home );
		}
		if ( '' !== $siteurl ) {
			self::write_option_value( 'siteurl', $siteurl );
		}

		self::bust_options_cache();

		// Refresh WP's in-request option API after the direct writes.
		if ( '' !== $home ) {
			update_option( 'home', $home );
		}
		if ( '' !== $siteurl ) {
			update_option( 'siteurl', $siteurl );
		}
	}

	/**
	 * Persist an option row even when the object cache is stale.
	 *
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 * @return void
	 */
	private static function write_option_value( string $name, string $value ): void {
		global $wpdb;

		$name = sanitize_key( $name );
		if ( '' === $name || ! isset( $wpdb->options ) ) {
			return;
		}

		$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		if ( $exists ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->options,
				array( 'option_value' => $value ),
				array( 'option_name' => $name ),
				array( '%s' ),
				array( '%s' )
			);
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => 'yes',
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Whether a URL is safe to use as home/siteurl or as a search-replace needle.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public static function is_plausible_site_url( string $url ): bool {
		$url = untrailingslashit( trim( $url ) );
		if ( strlen( $url ) < 12 || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}
		$host = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		if ( '' === $host || ! str_contains( $host, '.' ) ) {
			return false;
		}
		// Reject credential-shaped URLs from bad rewrites (https://w:example.com).
		if ( ! empty( $parts['user'] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Drop cached options so the next read hits the database.
	 *
	 * @return void
	 */
	public static function bust_options_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'home', 'options' );
			wp_cache_delete( 'siteurl', 'options' );
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	/**
	 * Remap prefix-scoped option/usermeta keys after a table-prefix change.
	 *
	 * Table names are rewritten during SQL replay, but rows still store keys like
	 * `wp_capabilities` / `wp_user_roles`. WordPress looks up `{live_prefix}capabilities`,
	 * so without this step users log in with no role and cannot open wp-admin.
	 *
	 * @param string $from Source table prefix (e.g. wp_).
	 * @param string $to   Live table prefix (e.g. xyz_).
	 * @return array{usermeta:int, options:int}
	 */
	public static function remap_prefixed_keys( string $from, string $to ): array {
		global $wpdb;

		$from = (string) $from;
		$to   = (string) $to;
		$out  = array(
			'usermeta' => 0,
			'options'  => 0,
		);

		if ( '' === $from || '' === $to || $from === $to ) {
			return $out;
		}

		// Refuse pathological prefixes that would match almost everything.
		if ( strlen( $from ) < 2 || ! str_ends_with( $from, '_' ) ) {
			return $out;
		}

		$like = $wpdb->esc_like( $from ) . '%';
		$from_len = strlen( $from );

		if ( ! empty( $wpdb->usermeta ) && self::table_exists( (string) $wpdb->usermeta ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s AND meta_key NOT LIKE %s",
					$to,
					$from_len + 1,
					$like,
					$wpdb->esc_like( $to ) . '%'
				)
			);
			if ( is_int( $updated ) && $updated > 0 ) {
				$out['usermeta'] = $updated;
			}
		}

		if ( ! empty( $wpdb->options ) && self::table_exists( (string) $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s AND option_name NOT LIKE %s",
					$to,
					$from_len + 1,
					$like,
					$wpdb->esc_like( $to ) . '%'
				)
			);
			if ( is_int( $updated ) && $updated > 0 ) {
				$out['options'] = $updated;
			}
		}

		self::bust_options_cache();
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return $out;
	}

	/**
	 * Keep wp-admin reachable after a cross-site DB restore.
	 *
	 * - Forces destination home/siteurl (no redirect to the source host)
	 * - Ensures {$prefix}user_roles exists
	 * - Restores the operator account (login + password hash) that started the restore
	 * - Grants that account administrator caps under the live table prefix
	 *
	 * @param array<string, mixed> $admin   Snapshot from restore start.
	 * @param string               $prefix  Live $wpdb->prefix.
	 * @param string               $home    Destination home URL.
	 * @param string               $siteurl Destination siteurl.
	 * @return array{login:string,user_id:int,created:bool,roles_ok:bool}
	 */
	public static function ensure_login_access( array $admin, string $prefix, string $home, string $siteurl ): array {
		global $wpdb;

		$out = array(
			'login'    => '',
			'user_id'  => 0,
			'created'  => false,
			'roles_ok' => false,
		);

		self::force_site_urls( $home, $siteurl );
		$out['roles_ok'] = self::ensure_admin_role_option( $prefix );

		$login = sanitize_user( (string) ( $admin['user_login'] ?? '' ), true );
		$pass  = (string) ( $admin['user_pass'] ?? '' );
		$email = sanitize_email( (string) ( $admin['user_email'] ?? '' ) );
		if ( '' === $login || '' === $pass ) {
			// Fall back: promote the first user that already has any caps, or user ID 1.
			$user_id = self::ensure_any_administrator( $prefix );
			$out['user_id'] = $user_id;
			if ( $user_id > 0 ) {
				$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare( "SELECT user_login FROM {$wpdb->users} WHERE ID = %d", $user_id ),
					ARRAY_A
				);
				$out['login'] = is_array( $row ) ? (string) ( $row['user_login'] ?? '' ) : '';
			}
			self::bust_options_cache();
			return $out;
		}

		$out['login'] = $login;
		$existing_id  = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login )
		);

		$nicename = sanitize_title( (string) ( $admin['user_nicename'] ?? $login ) );
		$display  = sanitize_text_field( (string) ( $admin['display_name'] ?? $login ) );
		$url      = esc_url_raw( (string) ( $admin['user_url'] ?? '' ) );
		if ( '' === $email ) {
			$email = $login . '@example.invalid';
		}

		if ( $existing_id > 0 ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->users,
				array(
					'user_pass'     => $pass,
					'user_email'    => $email,
					'user_nicename' => $nicename,
					'display_name'  => $display,
					'user_url'      => $url,
				),
				array( 'ID' => $existing_id ),
				array( '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$out['user_id'] = $existing_id;
		} else {
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->users,
				array(
					'user_login'          => $login,
					'user_pass'           => $pass,
					'user_nicename'       => $nicename,
					'user_email'          => $email,
					'user_url'            => $url,
					'user_registered'     => gmdate( 'Y-m-d H:i:s' ),
					'user_activation_key' => '',
					'user_status'         => 0,
					'display_name'        => $display,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			);
			if ( false !== $inserted ) {
				$out['user_id'] = (int) $wpdb->insert_id;
				$out['created'] = true;
			}
		}

		if ( $out['user_id'] > 0 ) {
			self::grant_administrator_caps( $out['user_id'], $prefix );
			clean_user_cache( $out['user_id'] );
		}

		self::bust_options_cache();
		return $out;
	}

	/**
	 * Ensure the live-prefix user_roles option contains an administrator role.
	 *
	 * @param string $prefix Table prefix.
	 * @return bool
	 */
	public static function ensure_admin_role_option( string $prefix ): bool {
		global $wpdb;

		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return false;
		}
		$name = $prefix . 'user_roles';

		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		$roles = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $roles ) ) {
			$roles = array();
		}

		if ( empty( $roles['administrator'] ) || ! is_array( $roles['administrator'] ) ) {
			$roles['administrator'] = array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'switch_themes'          => true,
					'edit_themes'           => true,
					'activate_plugins'      => true,
					'edit_plugins'          => true,
					'edit_users'            => true,
					'edit_files'            => true,
					'manage_options'        => true,
					'moderate_comments'     => true,
					'manage_categories'     => true,
					'manage_links'          => true,
					'upload_files'          => true,
					'import'                => true,
					'unfiltered_html'       => true,
					'edit_posts'            => true,
					'edit_others_posts'     => true,
					'edit_published_posts'  => true,
					'publish_posts'         => true,
					'edit_pages'            => true,
					'read'                  => true,
					'level_10'              => true,
					'level_9'               => true,
					'level_8'               => true,
					'level_7'               => true,
					'level_6'               => true,
					'level_5'               => true,
					'level_4'               => true,
					'level_3'               => true,
					'level_2'               => true,
					'level_1'               => true,
					'level_0'               => true,
					'edit_others_pages'     => true,
					'edit_published_pages'  => true,
					'publish_pages'         => true,
					'delete_pages'          => true,
					'delete_others_pages'   => true,
					'delete_published_pages'=> true,
					'delete_posts'          => true,
					'delete_others_posts'   => true,
					'delete_published_posts'=> true,
					'delete_private_posts'  => true,
					'edit_private_posts'    => true,
					'read_private_posts'    => true,
					'delete_private_pages'  => true,
					'edit_private_pages'    => true,
					'read_private_pages'    => true,
					'delete_users'          => true,
					'create_users'          => true,
					'unfiltered_upload'     => true,
					'edit_dashboard'        => true,
					'update_plugins'        => true,
					'delete_plugins'        => true,
					'install_plugins'       => true,
					'update_themes'         => true,
					'install_themes'        => true,
					'update_core'           => true,
					'list_users'            => true,
					'remove_users'          => true,
					'promote_users'         => true,
					'edit_theme_options'    => true,
					'delete_themes'         => true,
					'export'                => true,
				),
			);
			self::write_option_value( $name, maybe_serialize( $roles ) );
			return true;
		}

		return true;
	}

	/**
	 * Grant administrator capabilities for a user under the live prefix.
	 *
	 * @param int    $user_id User ID.
	 * @param string $prefix  Live prefix.
	 * @return void
	 */
	public static function grant_administrator_caps( int $user_id, string $prefix ): void {
		global $wpdb;

		if ( $user_id < 1 || '' === $prefix || empty( $wpdb->usermeta ) ) {
			return;
		}

		$cap_key   = $prefix . 'capabilities';
		$level_key = $prefix . 'user_level';
		$caps      = maybe_serialize( array( 'administrator' => true ) );

		foreach ( array( $cap_key => $caps, $level_key => '10' ) as $meta_key => $meta_value ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s LIMIT 1",
					$user_id,
					$meta_key
				)
			);
			if ( $exists ) {
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional capability usermeta write during migrate.
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->usermeta,
					array( 'meta_value' => $meta_value ),
					array(
						'user_id'  => $user_id,
						'meta_key' => $meta_key,
					),
					array( '%s' ),
					array( '%d', '%s' )
				);
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			} else {
				// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional capability usermeta write during migrate.
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->usermeta,
					array(
						'user_id'    => $user_id,
						'meta_key'   => $meta_key,
						'meta_value' => $meta_value,
					),
					array( '%d', '%s', '%s' )
				);
				// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			}
		}
	}

	/**
	 * Ensure at least one administrator exists (fallback when no preserve snapshot).
	 *
	 * @param string $prefix Live prefix.
	 * @return int User ID or 0.
	 */
	public static function ensure_any_administrator( string $prefix ): int {
		global $wpdb;

		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return 0;
		}

		$cap_key = $prefix . 'capabilities';
		$like    = '%' . $wpdb->esc_like( 'administrator' ) . '%';
		$user_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY user_id ASC LIMIT 1",
				$cap_key,
				$like
			)
		);

		if ( $user_id < 1 ) {
			$user_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( $user_id > 0 ) {
			self::grant_administrator_caps( $user_id, $prefix );
		}

		return $user_id;
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
		if ( '' === $table ) {
			return '';
		}
		$table = esc_sql( $table );
		$row   = $wpdb->get_row( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name is preg_replace-whitelisted then esc_sql'd.
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

		$table   = esc_sql( $table );
		$primary = esc_sql( $primary );
		$cols    = array_map( 'esc_sql', $cols );
		$select  = '`' . $primary . '`, `' . implode( '`, `', $cols ) . '`';
		$offset  = 0;
		$limit   = 200;
		$updated = 0;

		while ( true ) {
			// Identifiers cannot use prepare placeholders; values for LIMIT/OFFSET are prepared.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table/columns are preg_replace-whitelisted then esc_sql'd.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT {$select} FROM `{$table}` LIMIT %d OFFSET %d",
					$limit,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
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
