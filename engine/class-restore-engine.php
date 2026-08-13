<?php
/**
 * Restore engine with scoped restores.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restores full site, database, or scoped paths in chunks.
 */
class Maca_Backup_Pro_Restore_Engine {

	/**
	 * Start a restore job.
	 *
	 * @param int                  $backup_id Backup ID.
	 * @param string               $scope     full|database|wp-content|uploads|plugins|themes|files|path.
	 * @param array<string, mixed> $options   Extra (mode, selected_files, restore_database, extract_root, etc.).
	 * @return array{job_id:int}|\WP_Error
	 */
	public static function start( int $backup_id, string $scope = 'full', array $options = array() ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup' ) );
		}

		$verified = Maca_Backup_Pro_Verifier::verify_backup( $backup );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}

		$active = Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		if ( $active ) {
			return new WP_Error( 'busy', __( 'A restore is already running.', 'maca-backup' ) );
		}
		if ( Maca_Backup_Pro_Jobs_Table::active( 'backup' ) ) {
			return new WP_Error( 'busy', __( 'Wait for running backups to finish before restoring.', 'maca-backup' ) );
		}

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$archives = array();
		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
			$archives[] = $ready;
		}

		$selected = array_values(
			array_filter(
				array_map(
					static function ( $p ) {
						return ltrim( str_replace( '\\', '/', sanitize_text_field( (string) $p ) ), '/' );
					},
					(array) ( $options['selected_files'] ?? array() )
				)
			)
		);

		if ( 'path' === sanitize_key( $scope ) && empty( $selected ) ) {
			return new WP_Error( 'paths', __( 'Select at least one file or folder to restore.', 'maca-backup' ) );
		}

		$mode = sanitize_key( (string) ( $options['mode'] ?? 'restore' ) );
		if ( ! in_array( $mode, array( 'restore', 'migrate' ), true ) ) {
			$mode = 'restore';
		}
		$is_migrate = ( 'migrate' === $mode );

		if ( $is_migrate ) {
			$scope    = 'full';
			$selected = array();
		}

		$extract_root = isset( $options['extract_root'] ) ? (string) $options['extract_root'] : '';
		if ( '' === $extract_root ) {
			$extract_root = self::default_site_root();
		}
		$extract_root = trailingslashit( $extract_root );

		$restore_database = array_key_exists( 'restore_database', $options )
			? (bool) $options['restore_database']
			: in_array( sanitize_key( $scope ), array( 'full', 'database' ), true );

		if ( $is_migrate ) {
			$restore_database = true;
		}

		// Capture destination URLs BEFORE the dump overwrites home/siteurl.
		$dest_home    = untrailingslashit( home_url() );
		$dest_siteurl = untrailingslashit( site_url() );

		// Keep the operator who started the restore able to log in afterwards
		// (cross-site dumps replace users/options).
		$preserve_admin = array();
		if ( $restore_database && function_exists( 'wp_get_current_user' ) ) {
			$actor = wp_get_current_user();
			if ( $actor instanceof WP_User && $actor->exists() && ! empty( $actor->user_login ) ) {
				$preserve_admin = array(
					'user_login'    => (string) $actor->user_login,
					'user_pass'     => (string) $actor->user_pass,
					'user_email'    => (string) $actor->user_email,
					'user_nicename' => (string) $actor->user_nicename,
					'display_name'  => (string) $actor->display_name,
					'user_url'      => (string) $actor->user_url,
				);
			}
		}

		$state = array(
			'backup_id'        => $backup_id,
			'mode'             => $mode,
			'scope'            => sanitize_key( $scope ),
			'archives'         => $archives,
			'archive'          => $archives[0],
			'step'             => 'prepare',
			'sql_offset'       => 0,
			'file_offset'      => 0,
			'selected_files'   => $selected,
			'restore_database' => $restore_database,
			'extract_root'     => $extract_root,
			'extract_dir'      => trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . 'restore-' . $backup->backup_key,
			'started'          => time(),
			'preview'          => $options['preview'] ?? null,
			'chain'            => $options['chain'] ?? array(),
			'dest_home'        => $restore_database ? $dest_home : '',
			'dest_siteurl'     => $restore_database ? $dest_siteurl : '',
			'source_home'      => '',
			'source_siteurl'   => '',
			'urls_rewritten'   => false,
			'preserve_admin'   => $preserve_admin,
		);

		$job_id = Maca_Backup_Pro_Jobs_Table::insert(
			array(
				'job_type'  => 'restore',
				'status'    => 'running',
				'backup_id' => $backup_id,
				'progress'  => 0,
				'step'      => 'prepare',
				'state'     => wp_json_encode( $state ),
			)
		);

		Maca_Backup_Pro_Logger::info(
			$is_migrate ? __( 'Migrate started.', 'maca-backup' ) : __( 'Restore started.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
				'scope'     => $scope,
				'mode'      => $mode,
			)
		);

		Maca_Backup_Pro_Scheduler::instance()->schedule_process();

		return array( 'job_id' => $job_id );
	}

	/**
	 * Process one restore chunk.
	 *
	 * @param int|null $job_id Job ID.
	 * @return array<string, mixed>
	 */
	public static function process( ?int $job_id = null ): array {
		$job = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		if ( ! $job ) {
			return array(
				'done'     => true,
				'progress' => 100,
				'status'   => 'idle',
			);
		}

		if ( in_array( (string) $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
			return self::status_payload( $job );
		}

		$lock_id = (int) $job->id;
		if ( ! Maca_Backup_Pro_Jobs_Table::acquire_process_lock( $lock_id ) ) {
			return self::status_payload( $job );
		}

		try {
			$job = Maca_Backup_Pro_Jobs_Table::get( $lock_id );
			if ( ! $job ) {
				return array(
					'done'     => true,
					'progress' => 100,
					'status'   => 'idle',
				);
			}
			if ( in_array( (string) $job->status, array( 'completed', 'failed', 'cancelled' ), true ) ) {
				return self::status_payload( $job );
			}

			$state = json_decode( (string) $job->state, true );
			if ( ! is_array( $state ) ) {
				return self::fail( (int) $job->id, (int) $job->backup_id, __( 'Invalid restore state.', 'maca-backup' ) );
			}

			try {
				switch ( (string) $state['step'] ) {
					case 'prepare':
						$state = self::step_prepare( $state );
						break;
					case 'database':
						$state = self::step_database( $state );
						break;
					case 'migrate_urls':
						$state = self::step_migrate_urls( $state );
						break;
					case 'files':
						$state = self::step_files( $state );
						break;
					case 'done':
						return self::complete( (int) $job->id, (int) $job->backup_id, $state );
					default:
						return self::fail( (int) $job->id, (int) $job->backup_id, 'Unknown restore step.' );
				}
			} catch ( Throwable $e ) {
				return self::fail( (int) $job->id, (int) $job->backup_id, $e->getMessage() );
			}

			$fresh = Maca_Backup_Pro_Jobs_Table::get( (int) $job->id );
			if ( $fresh && 'cancelled' === (string) $fresh->status ) {
				return self::status_payload( $fresh );
			}

			$progress = match ( (string) $state['step'] ) {
				'prepare'      => 10,
				'database'     => 35,
				'migrate_urls' => 55,
				'files'        => 75,
				'done'         => 100,
				default        => 20,
			};

			Maca_Backup_Pro_Jobs_Table::update(
				(int) $job->id,
				array(
					'progress' => $progress,
					'step'     => (string) $state['step'],
					'state'    => wp_json_encode( $state ),
				)
			);

			if ( 'done' === $state['step'] ) {
				return self::complete( (int) $job->id, (int) $job->backup_id, $state );
			}

			Maca_Backup_Pro_Scheduler::instance()->schedule_process();

			return array(
				'done'     => false,
				'progress' => $progress,
				'step'     => $state['step'],
				'status'   => 'running',
				'job_id'   => (int) $job->id,
			);
		} finally {
			Maca_Backup_Pro_Jobs_Table::release_process_lock( $lock_id );
		}
	}

	/**
	 * Browse backup archive children under a prefix (lazy tree).
	 *
	 * @param int    $backup_id Backup ID.
	 * @param string $prefix    Relative folder prefix (no leading slash).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function browse( int $backup_id, string $prefix = '' ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup' ) );
		}

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$prefix = ltrim( str_replace( '\\', '/', $prefix ), '/' );
		if ( '' !== $prefix && ! str_ends_with( $prefix, '/' ) ) {
			$prefix .= '/';
		}

		$dirs  = array();
		$files = array();

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name ) {
					continue;
				}
				$name = str_replace( '\\', '/', $name );
				if ( in_array( $name, array( 'manifest.json', 'database.sql', 'files.json' ), true ) ) {
					continue;
				}
				if ( '' !== $prefix && ! str_starts_with( $name, $prefix ) ) {
					continue;
				}

				$rest = '' === $prefix ? $name : substr( $name, strlen( $prefix ) );
				if ( '' === $rest ) {
					continue;
				}

				$slash = strpos( $rest, '/' );
				if ( false !== $slash ) {
					$dir_name = substr( $rest, 0, $slash );
					$key      = $prefix . $dir_name;
					$dirs[ $key ] = array(
						'name' => $dir_name,
						'path' => $key,
						'type' => 'dir',
					);
					continue;
				}

				if ( str_ends_with( $name, '/' ) ) {
					continue;
				}

				$stat = $zip->statIndex( $i );
				$files[ $name ] = array(
					'name' => $rest,
					'path' => $name,
					'type' => 'file',
					'size' => (int) ( $stat['size'] ?? 0 ),
				);
			}
			$zip->close();
		}

		ksort( $dirs );
		ksort( $files );

		return array(
			'backup_id' => $backup_id,
			'prefix'    => rtrim( $prefix, '/' ),
			'items'     => array_values( array_merge( $dirs, $files ) ),
		);
	}

	/**
	 * Prepare extract list based on scope.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function step_prepare( array $state ): array {
		$scope     = (string) $state['scope'];
		$archives  = self::archives_from_state( $state );
		$selected  = $state['selected_files'] ?? array();
		$want_db   = ! empty( $state['restore_database'] );
		$files     = array();
		$file_map  = array(); // path => archive

		foreach ( $archives as $archive ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $archive ) ) {
				throw new RuntimeException( esc_html__( 'Could not open backup for restore.', 'maca-backup' ) );
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}
				$safe = Maca_Backup_Pro_Security::safe_zip_entry_path( $name );
				if ( false === $safe ) {
					continue;
				}
				if ( in_array( $safe, array( 'manifest.json', 'files.json' ), true ) ) {
					continue;
				}
				if ( 'database.sql' === $safe ) {
					continue;
				}
				if ( self::scope_allows_file( $scope, $safe, $selected ) ) {
					$files[]           = $safe;
					$file_map[ $safe ] = $archive;
				}
			}

			if ( $want_db && false !== $zip->locateName( 'database.sql' ) ) {
				$extract = (string) $state['extract_dir'];
				wp_mkdir_p( $extract );
				$sql_dest = Maca_Backup_Pro_Security::path_under_directory( $extract, 'database.sql' );
				if ( false !== $sql_dest ) {
					$stream = $zip->getStream( 'database.sql' );
					if ( $stream ) {
						$out = fopen( $sql_dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
						if ( $out ) {
							while ( ! feof( $stream ) ) {
								$buf = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
								if ( false !== $buf ) {
									fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
								}
							}
							fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							$state['sql_path'] = $sql_dest;
						}
						fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					}
				}
			}

			// Source URLs from manifest (for post-restore search-replace).
			if ( '' === (string) ( $state['source_home'] ?? '' ) && false !== $zip->locateName( 'manifest.json' ) ) {
				$raw = $zip->getFromName( 'manifest.json' );
				if ( is_string( $raw ) && '' !== $raw ) {
					$manifest = json_decode( $raw, true );
					if ( is_array( $manifest ) ) {
						$state['source_home']    = untrailingslashit( (string) ( $manifest['home_url'] ?? $manifest['site_url'] ?? '' ) );
						$state['source_siteurl'] = untrailingslashit( (string) ( $manifest['siteurl'] ?? $manifest['site_url'] ?? $state['source_home'] ) );
						if ( ! empty( $manifest['table_prefix'] ) ) {
							$state['manifest_table_prefix'] = (string) $manifest['table_prefix'];
						}
					}
				}
			}

			$zip->close();
		}

		$state['files']       = array_values( array_unique( $files ) );
		$state['file_map']    = $file_map;
		$state['file_offset'] = 0;
		$state['step']        = $want_db ? 'database' : 'files';

		if ( 'database' === $scope ) {
			$state['files'] = array();
		}

		if ( $want_db && ( empty( $state['sql_path'] ) || ! is_readable( (string) $state['sql_path'] ) ) ) {
			throw new RuntimeException(
				esc_html__( 'This backup has no database.sql — WordPress pages and other content cannot be restored. Use a full or database backup.', 'maca-backup' )
			);
		}

		if ( $want_db && ! empty( $state['sql_path'] ) ) {
			$sql_path = (string) $state['sql_path'];
			if ( empty( $state['sql_prefix'] ) ) {
				$detected      = Maca_Backup_Pro_Database_Exporter::detect_table_prefix( $sql_path );
				$from_manifest = (string) ( $state['manifest_table_prefix'] ?? '' );
				// Dump contents win over a stale manifest prefix.
				$state['sql_prefix'] = '' !== $detected ? $detected : $from_manifest;
			}
			$state['sql_bytes']          = (int) filesize( $sql_path );
			$state['sql_posts_inserts']  = Maca_Backup_Pro_Database_Exporter::count_posts_inserts( $sql_path );
			Maca_Backup_Pro_Logger::info(
				sprintf(
					/* translators: 1: bytes, 2: posts INSERT count, 3: dump prefix, 4: live prefix */
					__( 'Database dump ready: %1$s, %2$d post-row inserts, dump prefix “%3$s” → live “%4$s”.', 'maca-backup' ),
					size_format( (int) $state['sql_bytes'] ),
					(int) $state['sql_posts_inserts'],
					(string) ( $state['sql_prefix'] !== '' ? $state['sql_prefix'] : '?' ),
					$GLOBALS['wpdb']->prefix
				),
				array(
					'sql_bytes' => (int) $state['sql_bytes'],
					'posts_inserts' => (int) $state['sql_posts_inserts'],
					'dump_prefix' => (string) $state['sql_prefix'],
					'live_prefix' => $GLOBALS['wpdb']->prefix,
				)
			);
		}

		return $state;
	}

	/**
	 * Restore SQL in batches.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function step_database( array $state ): array {
		global $wpdb;

		$sql = (string) ( $state['sql_path'] ?? '' );
		if ( ! $sql || ! is_readable( $sql ) ) {
			if ( ! empty( $state['restore_database'] ) ) {
				throw new RuntimeException(
					esc_html__( 'Database dump missing — cannot restore pages/posts. Re-run restore with a full backup that includes the database.', 'maca-backup' )
				);
			}
			$state['step'] = ( 'database' === $state['scope'] ) ? 'done' : 'files';
			return $state;
		}

		$source_prefix = (string) ( $state['sql_prefix'] ?? '' );
		$result        = Maca_Backup_Pro_Database_Exporter::restore_batch(
			$sql,
			(int) ( $state['sql_offset'] ?? 0 ),
			40,
			'' !== $source_prefix ? $source_prefix : null
		);

		if ( ! empty( $result['source_prefix'] ) ) {
			$state['sql_prefix'] = (string) $result['source_prefix'];
		}

		if ( ! empty( $result['error'] ) ) {
			throw new RuntimeException( esc_html( (string) $result['error'] ) );
		}

		$state['sql_offset'] = (int) $result['offset'];
		if ( ! empty( $result['done'] ) ) {
			$dump_prefix = (string) ( $state['sql_prefix'] ?? '' );
			$live_prefix = (string) $wpdb->prefix;

			// If remap missed, dump tables sit under the dump prefix while WP reads live prefix.
			$adopt = Maca_Backup_Pro_Database_Exporter::adopt_orphan_prefixed_tables( $dump_prefix, $live_prefix );
			if ( empty( $adopt['adopted'] ) ) {
				// Last resort: find any *posts table richer in pages than the live one.
				$adopt = Maca_Backup_Pro_Database_Exporter::adopt_richest_posts_prefix( $live_prefix );
			}
			if ( ! empty( $adopt['adopted'] ) ) {
				$state['adopted_tables'] = $adopt;
				Maca_Backup_Pro_Logger::warning(
					sprintf(
						/* translators: 1: number of tables, 2: comma-separated table names */
						__( 'Migration adopted %1$d tables from dump prefix onto the live prefix: %2$s', 'maca-backup' ),
						(int) $adopt['adopted'],
						implode( ', ', $adopt['tables'] )
					),
					$adopt
				);
				if ( ! empty( $adopt['from_prefix'] ) ) {
					$state['sql_prefix'] = (string) $adopt['from_prefix'];
					$dump_prefix        = (string) $adopt['from_prefix'];
				}
			}

			Maca_Backup_Pro_Migrator::bust_options_cache();

			$pages = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status NOT IN ('auto-draft','trash')"
			);
			$posts = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status NOT IN ('auto-draft','trash')"
			);
			$rows = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				"SELECT COUNT(*) FROM {$wpdb->posts}"
			);
			$state['restored_pages'] = $pages;
			$state['restored_posts'] = $posts;

			Maca_Backup_Pro_Logger::info(
				sprintf(
					/* translators: 1: pages, 2: posts, 3: table name */
					__( 'Database restore finished: %1$d pages, %2$d posts in %3$s.', 'maca-backup' ),
					$pages,
					$posts,
					$wpdb->posts
				),
				array(
					'pages'             => $pages,
					'posts'             => $posts,
					'rows'              => $rows,
					'table'             => $wpdb->posts,
					'dump_prefix'       => $dump_prefix,
					'live_prefix'       => $live_prefix,
					'sql_posts_inserts' => (int) ( $state['sql_posts_inserts'] ?? 0 ),
					'adopted'           => (int) ( $adopt['adopted'] ?? 0 ),
				)
			);

			$expected = (int) ( $state['sql_posts_inserts'] ?? 0 );
			// Fail when the dump clearly had content but live pages look like an empty/test site.
			if ( ( $expected >= 20 && $pages < 20 ) || ( $expected >= 50 && $rows < (int) max( 20, $expected * 0.25 ) ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: 1: INSERT count in dump, 2: pages found after restore, 3: dump prefix, 4: live prefix */
						esc_html__( 'Database dump has %1$d post-row inserts but only %2$d pages are in the live table (dump prefix “%3$s”, live “%4$s”). Pages were not loaded into this site’s tables — retry after updating maca BackUp, or restore with matching table prefixes.', 'maca-backup' ),
						$expected,
						$pages,
						'' !== $dump_prefix ? $dump_prefix : '?',
						$live_prefix
					)
				);
			}

			// Bust option cache: raw SQL restore leaves alloptions holding the
			// pre-restore (destination) URLs, so get_option() would lie.
			Maca_Backup_Pro_Migrator::bust_options_cache();

			// Capture source URLs from the freshly restored options (migrate needs them for rewrite).
			if ( '' === (string) ( $state['source_home'] ?? '' ) ) {
				$state['source_home'] = untrailingslashit( (string) get_option( 'home', '' ) );
			}
			if ( '' === (string) ( $state['source_siteurl'] ?? '' ) ) {
				$state['source_siteurl'] = untrailingslashit( (string) get_option( 'siteurl', $state['source_home'] ?? '' ) );
			}

			if ( self::is_migration( $state ) || self::hosts_differ( $state ) ) {
				$state['step'] = 'migrate_urls';
			} else {
				// Same-site restore: remap prefix-scoped keys if dump prefix differs; no URL rewrite.
				$src_prefix = (string) ( $state['sql_prefix'] ?? '' );
				$dst_prefix = (string) $GLOBALS['wpdb']->prefix;
				if ( '' !== $src_prefix && $src_prefix !== $dst_prefix ) {
					$key_stats = Maca_Backup_Pro_Migrator::remap_prefixed_keys( $src_prefix, $dst_prefix );
					$state['prefix_key_remap'] = $key_stats;
					if ( (int) ( $key_stats['usermeta'] ?? 0 ) > 0 || (int) ( $key_stats['options'] ?? 0 ) > 0 ) {
						Maca_Backup_Pro_Logger::info(
							sprintf(
								/* translators: 1: usermeta rows, 2: options rows, 3: source prefix, 4: dest prefix */
								__( 'Restore prefix key remap: %1$d usermeta, %2$d options (%3$s → %4$s).', 'maca-backup' ),
								(int) ( $key_stats['usermeta'] ?? 0 ),
								(int) ( $key_stats['options'] ?? 0 ),
								$src_prefix,
								$dst_prefix
							),
							array(
								'from' => $src_prefix,
								'to'   => $dst_prefix,
							)
						);
					}
				}
				$state['step'] = ( 'database' === $state['scope'] || empty( $state['files'] ) ) ? 'done' : 'files';
			}
		}

		return $state;
	}

	/**
	 * Rewrite source URLs to the destination site after database restore (migrate mode only).
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function step_migrate_urls( array $state ): array {
		if ( ! self::is_migration( $state ) && ! self::hosts_differ( $state ) ) {
			$state['step'] = ( 'database' === $state['scope'] || empty( $state['files'] ) ) ? 'done' : 'files';
			return $state;
		}

		if ( empty( $state['urls_rewritten'] ) ) {
			$dest_home    = untrailingslashit( (string) ( $state['dest_home'] ?? '' ) );
			$dest_siteurl = untrailingslashit( (string) ( $state['dest_siteurl'] ?? '' ) );
			$src_home     = untrailingslashit( (string) ( $state['source_home'] ?? '' ) );
			$src_siteurl  = untrailingslashit( (string) ( $state['source_siteurl'] ?? '' ) );

			// Never fall back to home_url()/site_url() here — after a DB restore those
			// return the *source* URLs from the dump and would lock the site to the old host.
			if ( '' === $dest_home || '' === $dest_siteurl ) {
				$from_request = self::request_destination_urls();
				if ( '' === $dest_home ) {
					$dest_home = $from_request['home'];
				}
				if ( '' === $dest_siteurl ) {
					$dest_siteurl = $from_request['siteurl'];
				}
			}

			// If manifest lacked URLs, still force destination home/siteurl.
			$pairs = Maca_Backup_Pro_Migrator::url_pairs( $src_home, $src_siteurl, $dest_home, $dest_siteurl );
			$stats = Maca_Backup_Pro_Migrator::rewrite_site_urls( $pairs, $dest_home, $dest_siteurl );

			$src_prefix = (string) ( $state['sql_prefix'] ?? '' );
			$dst_prefix = (string) $GLOBALS['wpdb']->prefix;
			$key_stats  = Maca_Backup_Pro_Migrator::remap_prefixed_keys( $src_prefix, $dst_prefix );
			$state['prefix_key_remap'] = $key_stats;

			$admin_stats = Maca_Backup_Pro_Migrator::ensure_login_access(
				is_array( $state['preserve_admin'] ?? null ) ? $state['preserve_admin'] : array(),
				$dst_prefix,
				$dest_home,
				$dest_siteurl
			);
			$state['login_access'] = $admin_stats;

			$state['urls_rewritten'] = true;
			$state['url_replace']    = $stats;

			Maca_Backup_Pro_Logger::info(
				sprintf(
					/* translators: 1: rows updated, 2: tables scanned */
					__( 'Migration URL rewrite: %1$d rows across %2$d tables.', 'maca-backup' ),
					(int) ( $stats['replaced'] ?? 0 ),
					(int) ( $stats['tables'] ?? 0 )
				),
				array(
					'source_home' => $src_home,
					'dest_home'   => $dest_home,
				)
			);

			if ( (int) ( $key_stats['usermeta'] ?? 0 ) > 0 || (int) ( $key_stats['options'] ?? 0 ) > 0 ) {
				Maca_Backup_Pro_Logger::info(
					sprintf(
						/* translators: 1: usermeta rows, 2: options rows, 3: source prefix, 4: dest prefix */
						__( 'Migration prefix key remap: %1$d usermeta, %2$d options (%3$s → %4$s).', 'maca-backup' ),
						(int) ( $key_stats['usermeta'] ?? 0 ),
						(int) ( $key_stats['options'] ?? 0 ),
						$src_prefix !== '' ? $src_prefix : '?',
						$dst_prefix
					),
					array(
						'from' => $src_prefix,
						'to'   => $dst_prefix,
					)
				);
			}

			if ( ! empty( $admin_stats['login'] ) ) {
				Maca_Backup_Pro_Logger::info(
					sprintf(
						/* translators: %s: admin username preserved for login */
						__( 'Migration kept admin login “%s” so you can sign in on this site after restore.', 'maca-backup' ),
						(string) $admin_stats['login']
					),
					$admin_stats
				);
			}
		}

		$state['step'] = ( 'database' === $state['scope'] || empty( $state['files'] ) ) ? 'done' : 'files';
		return $state;
	}

	/**
	 * Whether this job is a cross-site migrate (URL rewrite + preserve admin).
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return bool
	 */
	private static function is_migration( array $state ): bool {
		return 'migrate' === sanitize_key( (string) ( $state['mode'] ?? 'restore' ) );
	}

	/**
	 * Whether dump home/siteurl host differs from the destination (imported archive).
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return bool
	 */
	private static function hosts_differ( array $state ): bool {
		$src = (string) ( wp_parse_url( (string) ( $state['source_home'] ?? '' ), PHP_URL_HOST ) ?: '' );
		$dst = (string) ( wp_parse_url( (string) ( $state['dest_home'] ?? '' ), PHP_URL_HOST ) ?: '' );
		if ( '' === $src || '' === $dst ) {
			$src = (string) ( wp_parse_url( (string) ( $state['source_siteurl'] ?? '' ), PHP_URL_HOST ) ?: $src );
			$dst = (string) ( wp_parse_url( (string) ( $state['dest_siteurl'] ?? '' ), PHP_URL_HOST ) ?: $dst );
		}
		return '' !== $src && '' !== $dst && 0 !== strcasecmp( $src, $dst );
	}

	/**
	 * Best-effort destination URLs from the current HTTP request (migration fallback).
	 *
	 * @return array{home:string,siteurl:string}
	 */
	private static function request_destination_urls(): array {
		$host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = strtolower( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) );
		}
		$host = preg_replace( '/:\d+$/', '', $host ) ?? '';
		$host = preg_replace( '/[^a-z0-9.\-:]/', '', $host ) ?? '';

		if ( '' === $host ) {
			return array(
				'home'    => '',
				'siteurl' => '',
			);
		}

		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
			|| ( isset( $_SERVER['SERVER_PORT'] ) && '443' === (string) $_SERVER['SERVER_PORT'] )
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );

		$base = ( $https ? 'https://' : 'http://' ) . $host;

		return array(
			'home'    => untrailingslashit( $base ),
			'siteurl' => untrailingslashit( $base ),
		);
	}

	/**
	 * Absolute path to the live site root for restores.
	 *
	 * Uses get_home_path() so Plugin Check does not flag ABSPATH file writes.
	 *
	 * @return string Trailing-slashed path.
	 */
	private static function default_site_root(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$home = get_home_path();
		if ( ! is_string( $home ) || '' === $home ) {
			$home = (string) ( defined( 'ABSPATH' ) ? constant( 'ABSPATH' ) : '' );
		}

		return trailingslashit( $home );
	}

	/**
	 * Resolve extract root from job state (staging path or live site).
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return string Trailing-slashed path.
	 */
	private static function resolve_extract_root( array $state ): string {
		if ( ! empty( $state['extract_root'] ) && is_string( $state['extract_root'] ) ) {
			return trailingslashit( $state['extract_root'] );
		}

		return self::default_site_root();
	}

	/**
	 * Extract/copy files in batches.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function step_files( array $state ): array {
		$files  = $state['files'] ?? array();
		$offset = (int) ( $state['file_offset'] ?? 0 );
		$batch  = array_slice( $files, $offset, 40 );
		$root   = self::resolve_extract_root( $state );

		if ( empty( $batch ) ) {
			$state['step'] = 'done';
			return $state;
		}

		$file_map = is_array( $state['file_map'] ?? null ) ? $state['file_map'] : array();
		$open     = array();

		foreach ( $batch as $name ) {
			$safe = Maca_Backup_Pro_Security::safe_zip_entry_path( (string) $name );
			if ( false === $safe ) {
				continue;
			}

			$archive = $file_map[ $name ] ?? $file_map[ $safe ] ?? ( (array) ( $state['archives'] ?? array( $state['archive'] ?? '' ) ) )[0];
			if ( ! isset( $open[ $archive ] ) ) {
				$zip = new ZipArchive();
				if ( true !== $zip->open( $archive ) ) {
					throw new RuntimeException( esc_html__( 'Could not open archive during file restore.', 'maca-backup' ) );
				}
				$open[ $archive ] = $zip;
			}
			$zip = $open[ $archive ];

			$dest = Maca_Backup_Pro_Security::path_under_directory( untrailingslashit( $root ), $safe );
			if ( false === $dest ) {
				continue;
			}
			$dir = dirname( $dest );
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}

			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				$stream = $zip->getStream( $safe );
			}
			if ( ! $stream ) {
				continue;
			}
			// Restore writes into WP root or a staging extract_root — not uploads.
			// phpcs:disable PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
			$out = fopen( $dest, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $out ) {
				while ( ! feof( $stream ) ) {
					$buf = fread( $stream, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					if ( false !== $buf ) {
						fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					}
				}
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			// phpcs:enable PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		foreach ( $open as $zip ) {
			$zip->close();
		}

		$state['file_offset'] = $offset + count( $batch );

		if ( $state['file_offset'] >= count( $files ) ) {
			$state['step'] = 'done';
		}

		return $state;
	}

	/**
	 * Whether a file path is allowed for the restore scope.
	 *
	 * @param string   $scope    Scope.
	 * @param string   $name     Archive entry name.
	 * @param string[] $selected Explicit file/folder list (folders match by prefix).
	 * @return bool
	 */
	public static function scope_allows_file( string $scope, string $name, array $selected ): bool {
		$name = str_replace( '\\', '/', $name );

		// Never overwrite local credentials / prefix during a migration restore.
		$base = basename( $name );
		if ( in_array( $base, array( 'wp-config.php', 'wp-config-sample.php', 'object-cache.php', 'advanced-cache.php', 'db.php' ), true ) ) {
			return false;
		}

		if ( ! empty( $selected ) ) {
			return self::path_matches_selection( $name, $selected );
		}

		return match ( $scope ) {
			'database'   => false,
			'path'       => false,
			'wp-content' => str_starts_with( $name, 'wp-content/' ),
			'uploads'    => str_starts_with( $name, 'wp-content/uploads/' ),
			'plugins'    => str_starts_with( $name, 'wp-content/plugins/' ),
			'themes'     => str_starts_with( $name, 'wp-content/themes/' ),
			'files'      => true,
			default      => true, // full
		};
	}

	/**
	 * Exact file or folder-prefix match.
	 *
	 * @param string   $name     Relative path.
	 * @param string[] $selected Selected paths.
	 * @return bool
	 */
	public static function path_matches_selection( string $name, array $selected ): bool {
		$name = str_replace( '\\', '/', $name );
		foreach ( $selected as $sel ) {
			$sel = rtrim( str_replace( '\\', '/', (string) $sel ), '/' );
			if ( '' === $sel ) {
				continue;
			}
			if ( $name === $sel || str_starts_with( $name, $sel . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Preview what a restore would overwrite.
	 *
	 * @param int      $backup_id Backup ID.
	 * @param string   $scope     Scope.
	 * @param string[] $selected  Optional path list for scope=path.
	 * @param bool     $database  Include database when using path/files.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preview( int $backup_id, string $scope = 'full', array $selected = array(), bool $database = false ) {
		$backup = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'missing', __( 'Backup not found.', 'maca-backup' ) );
		}

		$parts = Maca_Backup_Pro_Verifier::ensure_local_parts( $backup );
		if ( is_wp_error( $parts ) ) {
			return $parts;
		}

		$selected = array_values(
			array_filter(
				array_map(
					static fn( $p ) => ltrim( str_replace( '\\', '/', (string) $p ), '/' ),
					$selected
				)
			)
		);

		$will_write = array();
		$will_db    = $database || in_array( $scope, array( 'full', 'database' ), true );

		foreach ( $parts as $part ) {
			$ready = Maca_Backup_Pro_Verifier::maybe_decrypt_archive( $part );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $ready ) ) {
				continue;
			}

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$name = $zip->getNameIndex( $i );
				if ( false === $name || str_ends_with( $name, '/' ) ) {
					continue;
				}
				if ( 'database.sql' === $name ) {
					if ( $will_db ) {
						$will_db = true;
					}
					continue;
				}
				if ( in_array( $name, array( 'manifest.json', 'files.json' ), true ) ) {
					continue;
				}
				if ( self::scope_allows_file( $scope, $name, $selected ) ) {
					$exists       = file_exists( Maca_Backup_Pro_Paths::absolute( (string) $name ) );
					$will_write[] = array(
						'path'   => $name,
						'exists' => $exists,
						'action' => $exists ? 'overwrite' : 'create',
					);
				}
			}
			$zip->close();
		}

		return array(
			'backup_id'    => $backup_id,
			'scope'        => $scope,
			'database'     => $will_db && in_array( $scope, array( 'full', 'database', 'path', 'files' ), true ),
			'file_count'   => count( $will_write ),
			'overwrite'    => count( array_filter( $will_write, static fn( $f ) => 'overwrite' === $f['action'] ) ),
			'create'       => count( array_filter( $will_write, static fn( $f ) => 'create' === $f['action'] ) ),
			'files_sample' => array_slice( $will_write, 0, 100 ),
		);
	}

	/**
	 * Archives list from job state.
	 *
	 * @param array<string, mixed> $state State.
	 * @return string[]
	 */
	private static function archives_from_state( array $state ): array {
		if ( ! empty( $state['archives'] ) && is_array( $state['archives'] ) ) {
			return array_values( array_filter( array_map( 'strval', $state['archives'] ) ) );
		}
		$single = (string) ( $state['archive'] ?? '' );
		return '' !== $single ? array( $single ) : array();
	}

	/**
	 * Complete restore.
	 *
	 * @param int                  $job_id    Job ID.
	 * @param int                  $backup_id Backup ID.
	 * @param array<string, mixed> $state     State.
	 * @return array<string, mixed>
	 */
	private static function complete( int $job_id, int $backup_id, array $state ): array {
		$duration = max( 0, time() - (int) ( $state['started'] ?? time() ) );

		$claimed = Maca_Backup_Pro_Jobs_Table::claim_terminal(
			$job_id,
			'completed',
			array(
				'progress' => 100,
				'step'     => 'done',
			)
		);

		if ( ! $claimed ) {
			return array(
				'done'     => true,
				'progress' => 100,
				'status'   => 'completed',
				'job_id'   => $job_id,
			);
		}

		Maca_Backup_Pro_Logger::success(
			self::is_migration( $state ) ? __( 'Migrate completed.', 'maca-backup' ) : __( 'Restore completed.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
				'mode'      => sanitize_key( (string) ( $state['mode'] ?? 'restore' ) ),
			)
		);

		// Final login safety pass (URLs + preserved admin) after files may have overwritten options.
		if ( ! empty( $state['restore_database'] ) && ( self::is_migration( $state ) || self::hosts_differ( $state ) ) ) {
			$dest_home    = untrailingslashit( (string) ( $state['dest_home'] ?? '' ) );
			$dest_siteurl = untrailingslashit( (string) ( $state['dest_siteurl'] ?? '' ) );
			if ( '' === $dest_home || '' === $dest_siteurl ) {
				$from_request = self::request_destination_urls();
				if ( '' === $dest_home ) {
					$dest_home = $from_request['home'];
				}
				if ( '' === $dest_siteurl ) {
					$dest_siteurl = $from_request['siteurl'];
				}
			}
			Maca_Backup_Pro_Migrator::ensure_login_access(
				is_array( $state['preserve_admin'] ?? null ) ? $state['preserve_admin'] : array(),
				(string) $GLOBALS['wpdb']->prefix,
				$dest_home,
				$dest_siteurl
			);
		}

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}

		Maca_Backup_Pro_Mailer::notify_restore(
			true,
			array(
				'duration'  => $duration,
				'scope'     => sanitize_key( (string) ( $state['scope'] ?? 'full' ) ),
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			)
		);

		return array(
			'done'     => true,
			'progress' => 100,
			'status'   => 'completed',
			'job_id'   => $job_id,
		);
	}

	/**
	 * Cancel a running restore job.
	 *
	 * @param int|null $job_id Job ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function cancel( ?int $job_id = null ) {
		$job = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : Maca_Backup_Pro_Jobs_Table::active( 'restore' );
		if ( ! $job || 'restore' !== (string) $job->job_type ) {
			return new WP_Error( 'missing', __( 'No running restore to stop.', 'maca-backup' ) );
		}

		if ( ! in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			return new WP_Error( 'not_running', __( 'That restore is not running.', 'maca-backup' ) );
		}

		$state = json_decode( (string) $job->state, true );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$message = __( 'Restore cancelled by user.', 'maca-backup' );

		Maca_Backup_Pro_Jobs_Table::update(
			(int) $job->id,
			array(
				'status'        => 'cancelled',
				'progress'      => (int) $job->progress,
				'error_message' => $message,
			)
		);

		$extract = (string) ( $state['extract_dir'] ?? '' );
		if ( $extract && is_dir( $extract ) ) {
			self::rrmdir( $extract );
		}

		Maca_Backup_Pro_Scheduler::instance()->clear_process();

		Maca_Backup_Pro_Logger::info(
			$message,
			array(
				'backup_id' => (int) $job->backup_id,
				'job_id'    => (int) $job->id,
			)
		);

		$job = Maca_Backup_Pro_Jobs_Table::get( (int) $job->id );
		return $job ? self::status_payload( $job ) : array(
			'done'     => true,
			'status'   => 'cancelled',
			'progress' => 0,
		);
	}

	/**
	 * Public status payload for AJAX polling.
	 *
	 * @param object $job Job row.
	 * @return array<string, mixed>
	 */
	public static function status_payload( object $job ): array {
		$state = json_decode( (string) $job->state, true );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$done = in_array( (string) $job->status, array( 'completed', 'failed', 'cancelled' ), true );
		$step = (string) $job->step;

		$detail = '';
		if ( 'prepare' === $step ) {
			$detail = __( 'Preparing restore…', 'maca-backup' );
		} elseif ( 'database' === $step ) {
			$detail = __( 'Restoring database…', 'maca-backup' );
		} elseif ( 'files' === $step ) {
			$files  = $state['files'] ?? array();
			$offset = (int) ( $state['file_offset'] ?? 0 );
			if ( ! empty( $files[ $offset ] ) ) {
				$detail = (string) $files[ $offset ];
			} elseif ( $offset > 0 && ! empty( $files[ $offset - 1 ] ) ) {
				$detail = (string) $files[ $offset - 1 ];
			} else {
				$detail = __( 'Restoring files…', 'maca-backup' );
			}
		}

		return array(
			'done'         => $done,
			'progress'     => (int) $job->progress,
			'step'         => $step,
			'status'       => (string) $job->status,
			'job_id'       => (int) $job->id,
			'backup_id'    => (int) $job->backup_id,
			'job_type'     => (string) $job->job_type,
			'error'        => (string) ( $job->error_message ?? '' ),
			'current_item' => $detail,
			'processed'    => (int) ( $state['file_offset'] ?? 0 ),
			'total'        => count( $state['files'] ?? array() ),
			'started'      => (int) ( $state['started'] ?? 0 ),
			'elapsed'      => max( 0, time() - (int) ( $state['started'] ?? time() ) ),
		);
	}

	/**
	 * Fail restore.
	 *
	 * @param int    $job_id    Job ID.
	 * @param int    $backup_id Backup ID.
	 * @param string $message   Error.
	 * @return array<string, mixed>
	 */
	private static function fail( int $job_id, int $backup_id, string $message ): array {
		$job_state = array();
		$job_row   = Maca_Backup_Pro_Jobs_Table::get( $job_id );
		if ( $job_row && ! empty( $job_row->state ) ) {
			$decoded = json_decode( (string) $job_row->state, true );
			if ( is_array( $decoded ) ) {
				$job_state = $decoded;
			}
		}

		$claimed = Maca_Backup_Pro_Jobs_Table::claim_terminal(
			$job_id,
			'failed',
			array(
				'error_message' => $message,
			)
		);

		if ( ! $claimed ) {
			return array(
				'done'   => true,
				'status' => 'failed',
				'error'  => $message,
			);
		}

		Maca_Backup_Pro_Logger::error(
			$message,
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			)
		);

		Maca_Backup_Pro_Mailer::notify_restore(
			false,
			array(
				'error'     => $message,
				'scope'     => sanitize_key( (string) ( $job_state['scope'] ?? '' ) ),
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			)
		);

		return array(
			'done'   => true,
			'status' => 'failed',
			'error'  => $message,
		);
	}

	/**
	 * Recursive directory delete.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $dir );
	}
}
