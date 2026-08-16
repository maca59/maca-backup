<?php
/**
 * Chunked backup engine.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates scan → SQL → ZIP → upload → verify.
 */
class Maca_Backup_Pro_Backup_Engine {

	/**
	 * Request-scoped cache of the scanned file list.
	 *
	 * @var string[]|null
	 */
	private static ?array $files_list_cache = null;

	/**
	 * Cache key for $files_list_cache.
	 *
	 * @var string
	 */
	private static string $files_list_cache_key = '';

	/**
	 * Start a new backup job.
	 *
	 * @param string               $type    full|database|files.
	 * @param array<string, mixed> $options Extra options.
	 * @return array{job_id:int, backup_id:int}|\WP_Error
	 */
	public static function start( string $type = 'full', array $options = array() ) {
		$type = sanitize_key( $type );

		$allowed = self::can_start( $type, $options );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$parent_id = (int) ( $options['parent_backup_id'] ?? 0 );

		if ( in_array( $type, array( 'incremental', 'differential' ), true ) ) {
			$base = $parent_id > 0
				? Maca_Backup_Pro_Backups_Table::get( $parent_id )
				: Maca_Backup_Pro_Backups_Table::latest_full_completed();
			if ( ! $base || 'completed' !== $base->status ) {
				return new WP_Error( 'parent', __( 'A completed full backup is required before incremental/differential backups.', 'maca-backup' ) );
			}
			if ( 'incremental' === $type ) {
				$latest = Maca_Backup_Pro_Backups_Table::latest_completed();
				if ( $latest && in_array( (string) $latest->type, array( 'full', 'incremental', 'differential' ), true ) ) {
					$base = $latest;
				}
			} else {
				// Differential always against latest full.
				$base = Maca_Backup_Pro_Backups_Table::latest_full_completed() ?: $base;
			}
			$parent_id = (int) $base->id;
		}

		$settings = Maca_Backup_Pro_Settings::all();
		$storage  = $options['storage'] ?? $settings['storage_provider'];
		$key      = 'mbp_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false );
		$work     = trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . $key;

		if ( ! wp_mkdir_p( $work ) ) {
			return new WP_Error( 'mkdir', __( 'Could not create backup working directory.', 'maca-backup' ) );
		}

		$backup_id = Maca_Backup_Pro_Backups_Table::insert(
			array(
				'backup_key'       => $key,
				'type'             => $type,
				'status'           => 'running',
				'storage'          => sanitize_key( (string) $storage ),
				'path'             => $work,
				'parent_backup_id' => $parent_id,
				'started_at'       => current_time( 'mysql' ),
				'created_at'       => current_time( 'mysql' ),
			)
		);

		if ( $backup_id < 1 ) {
			self::rrmdir( $work );
			global $wpdb;
			$message = __( 'Could not create backup record.', 'maca-backup' );
			if ( ! empty( $wpdb->last_error ) ) {
				$message .= ' ' . $wpdb->last_error;
			}
			return new WP_Error( 'db_insert', $message );
		}

		$schedule_id = sanitize_key( (string) ( $options['schedule_id'] ?? '' ) );

		$state = array(
			'type'             => $type,
			'work_dir'         => $work,
			'storage'          => $storage,
			'step'             => 'scan',
			'file_offset'      => 0,
			'table_offset'     => 0,
			'files'            => array(),
			'tables'           => array(),
			'scope'            => $options['scope'] ?? ( 'database' === $type ? 'none' : 'full' ),
			'sql_path'         => $work . '/database.sql',
			'started'          => time(),
			'parent_backup_id' => $parent_id,
			'pre_update'       => ! empty( $options['pre_update'] ),
			'schedule_id'      => $schedule_id,
		);

		$job_id = Maca_Backup_Pro_Jobs_Table::insert(
			array(
				'job_type'  => 'backup',
				'status'    => 'running',
				'backup_id' => $backup_id,
				'progress'  => 0,
				'step'      => 'scan',
				'state'     => wp_json_encode( $state ),
			)
		);

		Maca_Backup_Pro_Logger::info(
			__( 'Backup started.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
				'type'      => $type,
			)
		);

		Maca_Backup_Pro_Scheduler::instance()->schedule_process();

		return array(
			'job_id'    => $job_id,
			'backup_id' => $backup_id,
		);
	}

	/**
	 * Which resources a backup type/scope needs.
	 *
	 * @param string $type  Backup type.
	 * @param string $scope Optional scope.
	 * @return array{db:bool,files:bool}
	 */
	public static function job_resources( string $type, string $scope = '' ): array {
		$type  = sanitize_key( $type );
		$scope = sanitize_key( $scope );

		if ( 'database' === $type || 'none' === $scope ) {
			return array(
				'db'    => true,
				'files' => false,
			);
		}

		if ( 'files' === $type ) {
			return array(
				'db'    => false,
				'files' => true,
			);
		}

		// full / incremental / differential (and unknown) touch both.
		return array(
			'db'    => true,
			'files' => true,
		);
	}

	/**
	 * Whether a new backup can start alongside current jobs.
	 * Database-only may run beside files-only (and vice versa). Full blocks both.
	 *
	 * @param string               $type    Backup type.
	 * @param array<string, mixed> $options Start options.
	 * @return true|\WP_Error
	 */
	public static function can_start( string $type, array $options = array() ) {
		if ( Maca_Backup_Pro_Jobs_Table::active( 'restore' ) ) {
			return new WP_Error( 'busy', __( 'A restore is already running.', 'maca-backup' ) );
		}

		$type  = sanitize_key( $type );
		$scope = (string) ( $options['scope'] ?? ( 'database' === $type ? 'none' : '' ) );
		$want  = self::job_resources( $type, $scope );
		$active = Maca_Backup_Pro_Jobs_Table::active_all( 'backup' );

		if ( count( $active ) >= 2 ) {
			return new WP_Error( 'busy', __( 'Two backups are already running. Wait for one to finish.', 'maca-backup' ) );
		}

		foreach ( $active as $job ) {
			$state = json_decode( (string) ( $job->state ?? '' ), true );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$have = self::job_resources(
				(string) ( $state['type'] ?? 'full' ),
				(string) ( $state['scope'] ?? '' )
			);

			if ( ( $want['db'] && $have['db'] ) || ( $want['files'] && $have['files'] ) ) {
				$running = (string) ( $state['type'] ?? 'backup' );
				if ( $want['db'] && $have['db'] && ! $want['files'] ) {
					return new WP_Error(
						'busy',
						sprintf(
							/* translators: %s: running backup type */
							__( 'A database backup is already running (%s). Wait for it to finish, or run a files-only backup.', 'maca-backup' ),
							$running
						)
					);
				}
				if ( $want['files'] && $have['files'] && ! $want['db'] ) {
					return new WP_Error(
						'busy',
						sprintf(
							/* translators: %s: running backup type */
							__( 'A file backup is already running (%s). Wait for it to finish, or run a database-only backup.', 'maca-backup' ),
							$running
						)
					);
				}
				return new WP_Error(
					'busy',
					sprintf(
						/* translators: %s: running backup type */
						__( 'Cannot start this backup while “%s” is running (overlapping database/files work).', 'maca-backup' ),
						$running
					)
				);
			}
		}

		return true;
	}

	/**
	 * Process one chunk of the active (or given) job.
	 *
	 * @param int|null $job_id Job ID.
	 * @return array<string, mixed>
	 */
	public static function process( ?int $job_id = null ): array {
		$job = null;
		if ( $job_id ) {
			$job = Maca_Backup_Pro_Jobs_Table::get( $job_id );
		} else {
			$job = Maca_Backup_Pro_Jobs_Table::active( 'backup' );
		}

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
			// Another worker is packing this job — avoid duplicate ZIP entries / inflated size.
			return self::status_payload( $job );
		}

		try {
			// Re-read after lock so we never advance a stale file_offset.
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
				return self::fail( (int) $job->id, (int) $job->backup_id, __( 'Invalid job state.', 'maca-backup' ) );
			}

			// Migrate oversized in-state file lists (legacy / stuck jobs) onto disk once.
			if ( ! empty( $state['files'] ) && is_array( $state['files'] ) && count( $state['files'] ) > 50 ) {
				$state = self::persist_files_list( $state, $state['files'] );
				Maca_Backup_Pro_Jobs_Table::update(
					(int) $job->id,
					array(
						'state' => wp_json_encode( $state ),
					)
				);
			}

			$chunk = new Maca_Backup_Pro_Chunk_Processor( 25 );
			$step  = (string) ( $state['step'] ?? 'scan' );

			try {
				switch ( $step ) {
					case 'scan':
						$state = self::step_scan( $state );
						break;
					case 'database':
						$state = self::step_database( $state, $chunk );
						break;
					case 'files':
						// Pack as many byte-capped batches as the time budget allows.
						do {
							$before = (int) ( $state['file_offset'] ?? 0 );
							$state  = self::step_files( $state, $chunk );
							$after  = (int) ( $state['file_offset'] ?? 0 );
							if ( $after <= $before || 'files' !== (string) ( $state['step'] ?? '' ) ) {
								break;
							}
						} while ( ! $chunk->should_yield() && ! $chunk->memory_pressure() );
						break;
					case 'manifest':
						$state = self::step_manifest( $state, (int) $job->backup_id );
						break;
					case 'upload':
						$state = self::step_upload( $state, (int) $job->backup_id );
						break;
					case 'verify':
						$state = self::step_verify( $state, (int) $job->backup_id );
						break;
					case 'done':
						return self::complete( (int) $job->id, (int) $job->backup_id, $state );
					default:
						return self::fail( (int) $job->id, (int) $job->backup_id, 'Unknown step: ' . $step );
				}
			} catch ( Throwable $e ) {
				return self::fail( (int) $job->id, (int) $job->backup_id, $e->getMessage() );
			}

			$fresh = Maca_Backup_Pro_Jobs_Table::get( (int) $job->id );
			if ( $fresh && 'cancelled' === (string) $fresh->status ) {
				return self::status_payload( $fresh );
			}

			$progress = self::progress_for_state( $state );
			Maca_Backup_Pro_Jobs_Table::update(
				(int) $job->id,
				array(
					'status'   => 'running',
					'step'     => (string) $state['step'],
					'progress' => $progress,
					'state'    => wp_json_encode( $state ),
				)
			);

			if ( 'done' === $state['step'] ) {
				return self::complete( (int) $job->id, (int) $job->backup_id, $state );
			}

			Maca_Backup_Pro_Scheduler::instance()->schedule_process();

			return array_merge(
				array(
					'done'     => false,
					'progress' => $progress,
					'step'     => $state['step'],
					'status'   => 'running',
					'job_id'   => (int) $job->id,
				),
				self::progress_meta( $state )
			);
		} finally {
			Maca_Backup_Pro_Jobs_Table::release_process_lock( $lock_id );
		}
	}

	/**
	 * Scan files / tables.
	 *
	 * @param array<string, mixed> $state State.
	 * @return array<string, mixed>
	 */
	private static function step_scan( array $state ): array {
		$type        = (string) $state['type'];
		$needs_files = 'database' !== $type;
		$needs_db    = 'files' !== $type;
		// Full / incremental / differential must be migration-complete (all files + whole DB).
		$complete = in_array( $type, array( 'full', 'incremental', 'differential' ), true );

		if ( $needs_files ) {
			$scope = (string) ( $state['scope'] ?? 'full' );
			// Pre-update scoped backups keep their scope; complete site backups force full tree.
			if ( $complete && empty( $state['pre_update'] ) ) {
				$scope          = 'full';
				$state['scope'] = 'full';
			}
			$files = Maca_Backup_Pro_File_Scanner::list_files(
				$scope,
				array(
					'work_dir' => (string) ( $state['work_dir'] ?? '' ),
					'complete' => $complete && empty( $state['pre_update'] ),
				)
			);

			if ( in_array( $type, array( 'incremental', 'differential' ), true ) ) {
				$parent_id = (int) ( $state['parent_backup_id'] ?? 0 );
				$parent    = $parent_id > 0 ? Maca_Backup_Pro_Backups_Table::get( $parent_id ) : null;
				$base_inv  = array();
				if ( $parent && ! empty( $parent->inventory ) ) {
					$decoded = json_decode( (string) $parent->inventory, true );
					if ( is_array( $decoded ) ) {
						$base_inv = $decoded;
					}
				}
				$files = self::filter_changed_files( $files, $base_inv );
			}

			if ( $complete && empty( $state['pre_update'] ) && 'full' === $type ) {
				self::assert_full_file_set( $files );
			}

			$state                = self::persist_files_list( $state, $files );
			$state['file_offset'] = 0;
		} else {
			$state                = self::persist_files_list( $state, array() );
			$state['file_offset'] = 0;
		}

		if ( $needs_db ) {
			$state['tables']       = Maca_Backup_Pro_Database_Exporter::prioritize_tables(
				Maca_Backup_Pro_Database_Exporter::tables()
			);
			$state['table_offset'] = 0;
			if ( empty( $state['tables'] ) ) {
				throw new RuntimeException(
					esc_html__( 'Could not list database tables. A full site backup requires the entire database.', 'maca-backup' )
				);
			}
			if ( $complete || 'database' === $type ) {
				self::assert_core_tables( $state['tables'] );
			}
			$state['step'] = 'database';
		} else {
			$state['tables'] = array();
			$state['step']   = 'files';
		}

		$state['current_item']  = __( 'Scan complete', 'maca-backup' );
		$state['current_batch'] = array();
		$state['processed']     = 0;
		$state['total']         = (int) ( $state['file_count'] ?? 0 );

		return $state;
	}

	/**
	 * Ensure a full backup scanned the WordPress core + content tree.
	 *
	 * @param string[] $files Relative paths.
	 * @return void
	 */
	private static function assert_full_file_set( array $files ): void {
		if ( count( $files ) < 50 ) {
			throw new RuntimeException(
				esc_html__( 'Full backup file scan returned too few files. The site tree could not be read completely.', 'maca-backup' )
			);
		}

		$lower = array_map( 'strtolower', $files );
		$has_settings = false;
		$has_includes = false;
		$has_admin    = false;
		$has_content  = false;
		$has_uploads  = false;
		foreach ( $lower as $rel ) {
			if ( 'wp-settings.php' === $rel || str_ends_with( $rel, '/wp-settings.php' ) ) {
				$has_settings = true;
			}
			if ( str_contains( $rel, 'wp-includes/' ) ) {
				$has_includes = true;
			}
			if ( str_contains( $rel, 'wp-admin/' ) ) {
				$has_admin = true;
			}
			if ( str_contains( $rel, 'wp-content/' ) ) {
				$has_content = true;
			}
			if ( self::path_is_media_upload( $rel ) ) {
				$has_uploads = true;
			}
		}
		if ( ! $has_settings ) {
			throw new RuntimeException(
				esc_html__( 'Full backup is incomplete — missing WordPress core (wp-settings.php). All site files must be included for restore/migration.', 'maca-backup' )
			);
		}
		if ( ! $has_includes || ! $has_admin || ! $has_content ) {
			throw new RuntimeException(
				esc_html__( 'Full backup is incomplete — wp-admin, wp-includes, and wp-content must all be included.', 'maca-backup' )
			);
		}

		$uploads_dir = Maca_Backup_Pro_Paths::uploads_basedir();
		if ( '' !== $uploads_dir && is_dir( $uploads_dir ) ) {
			$media_on_disk = self::uploads_has_media_files( $uploads_dir );
			if ( $media_on_disk && ! $has_uploads ) {
				throw new RuntimeException(
					esc_html__( 'Full backup is incomplete — Media Library files (wp-content/uploads) were not included.', 'maca-backup' )
				);
			}
		}
	}

	/**
	 * Whether a relative path is a Media Library upload (not plugin staging).
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	private static function path_is_media_upload( string $rel ): bool {
		$rel = strtolower( str_replace( '\\', '/', $rel ) );
		if ( ! str_starts_with( $rel, 'wp-content/uploads/' ) ) {
			return false;
		}
		return ! (bool) preg_match( '#^wp-content/uploads/(?:maca-backups|maca-backup-dl)(?:/|$)#', $rel );
	}

	/**
	 * True when the uploads directory contains at least one non-staging media file.
	 *
	 * @param string $uploads_dir Absolute uploads basedir.
	 * @return bool
	 */
	private static function uploads_has_media_files( string $uploads_dir ): bool {
		$uploads_dir = wp_normalize_path( untrailingslashit( $uploads_dir ) );
		if ( '' === $uploads_dir || ! is_dir( $uploads_dir ) ) {
			return false;
		}

		try {
			$inner = new RecursiveDirectoryIterator( $uploads_dir, FilesystemIterator::SKIP_DOTS );
			$iter  = new RecursiveIteratorIterator( $inner, RecursiveIteratorIterator::LEAVES_ONLY );
			foreach ( $iter as $fileinfo ) {
				/** @var SplFileInfo $fileinfo */
				if ( ! $fileinfo->isFile() ) {
					continue;
				}
				$path = wp_normalize_path( $fileinfo->getPathname() );
				if ( preg_match( '#/(?:maca-backups|maca-backup-dl)(?:/|$)#i', $path ) ) {
					continue;
				}
				return true;
			}
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return true; // If we cannot probe, still require uploads in the scan set below.
		}

		return false;
	}

	/**
	 * Ensure essential WordPress tables will be exported.
	 *
	 * @param string[] $tables Table names.
	 * @return void
	 */
	private static function assert_core_tables( array $tables ): void {
		global $wpdb;
		$tables_l = array_map( 'strtolower', $tables );
		$set      = array_fill_keys( $tables_l, true );
		$required = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->options,
			$wpdb->users,
		);
		foreach ( $required as $table ) {
			if ( empty( $set[ strtolower( (string) $table ) ] ) ) {
				throw new RuntimeException(
					sprintf(
						/* translators: %s: table name */
						esc_html__( 'Full database backup is incomplete — required table %s was not found.', 'maca-backup' ),
						esc_html( (string) $table )
					)
				);
			}
		}
	}

	/**
	 * Write scanned paths to disk so job state stays small.
	 *
	 * @param array<string, mixed> $state Job state.
	 * @param string[]             $files Relative paths.
	 * @return array<string, mixed>
	 */
	private static function persist_files_list( array $state, array $files ): array {
		$work = (string) ( $state['work_dir'] ?? '' );
		$files = array_values( array_map( 'strval', $files ) );
		$path  = '' !== $work ? trailingslashit( $work ) . 'scan-files.json' : '';

		if ( '' !== $path ) {
			if ( ! is_dir( $work ) ) {
				wp_mkdir_p( $work );
			}
			file_put_contents( $path, (string) wp_json_encode( $files ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$state['files_list'] = $path;
		}

		$state['file_count'] = count( $files );
		$state['files']      = array();
		self::$files_list_cache     = $files;
		self::$files_list_cache_key = (string) ( $state['files_list'] ?? '' ) . '|' . (string) ( $state['work_dir'] ?? '' );
		return $state;
	}

	/**
	 * Load scanned paths from disk (or legacy in-state array).
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return string[]
	 */
	private static function load_files_list( array $state ): array {
		$cache_key = (string) ( $state['files_list'] ?? '' ) . '|' . (string) ( $state['work_dir'] ?? '' );
		if ( null !== self::$files_list_cache && self::$files_list_cache_key === $cache_key ) {
			return self::$files_list_cache;
		}

		if ( ! empty( $state['files'] ) && is_array( $state['files'] ) ) {
			$files = array_values( array_map( 'strval', $state['files'] ) );
			self::$files_list_cache     = $files;
			self::$files_list_cache_key = $cache_key;
			return $files;
		}

		$path = (string) ( $state['files_list'] ?? '' );
		if ( '' === $path || ! is_readable( $path ) ) {
			$work = (string) ( $state['work_dir'] ?? '' );
			$alt  = '' !== $work ? trailingslashit( $work ) . 'scan-files.json' : '';
			$path = ( $alt && is_readable( $alt ) ) ? $alt : '';
		}
		if ( '' === $path ) {
			return array();
		}

		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$files   = is_array( $decoded ) ? array_values( array_map( 'strval', $decoded ) ) : array();
		self::$files_list_cache     = $files;
		self::$files_list_cache_key = $cache_key;
		return $files;
	}

	/**
	 * Keep only new/changed files vs a parent inventory.
	 *
	 * @param string[]                                        $files     Current file list.
	 * @param array<string, array{size?:int,mtime?:int,crc?:int}> $base_inv Parent inventory.
	 * @return string[]
	 */
	private static function filter_changed_files( array $files, array $base_inv ): array {
		if ( empty( $base_inv ) ) {
			return $files;
		}
		$out = array();
		foreach ( $files as $rel ) {
			$rel = str_replace( '\\', '/', (string) $rel );
			$abs = Maca_Backup_Pro_Paths::readable_absolute( (string) $rel );
			if ( '' === $abs ) {
				continue;
			}
			$size  = (int) filesize( $abs );
			$mtime = (int) filemtime( $abs );
			$prev  = $base_inv[ $rel ] ?? null;
			if (
				! $prev
				|| (int) ( $prev['size'] ?? 0 ) !== $size
				|| (int) ( $prev['mtime'] ?? 0 ) !== $mtime
			) {
				$out[] = $rel;
			}
		}
		return $out;
	}

	/**
	 * Export DB tables in batches.
	 *
	 * @param array<string, mixed>           $state State.
	 * @param Maca_Backup_Pro_Chunk_Processor $chunk Chunk helper.
	 * @return array<string, mixed>
	 */
	private static function step_database( array $state, Maca_Backup_Pro_Chunk_Processor $chunk ): array {
		$tables = $state['tables'] ?? array();
		$offset = (int) ( $state['table_offset'] ?? 0 );
		$batch  = (int) Maca_Backup_Pro_Settings::get( 'chunk_tables', 5 );
		$slice  = array_slice( $tables, $offset, $batch );

		if ( empty( $slice ) ) {
			$state['current_item']  = '';
			$state['current_batch'] = array();
			$state['processed']     = count( $tables );
			$state['total']         = count( $tables );
			$state['step']          = ( 'database' === $state['type'] ) ? 'manifest' : 'files';
			return $state;
		}

		$state['current_item']  = (string) end( $slice );
		$state['current_batch'] = array_values( array_map( 'strval', $slice ) );
		$state['processed']     = $offset;
		$state['total']         = count( $tables );

		$result = Maca_Backup_Pro_Database_Exporter::export_tables( (string) $state['sql_path'], $slice );
		$state['table_offset'] = $offset + count( $slice );
		$state['db_bytes']     = ( (int) ( $state['db_bytes'] ?? 0 ) ) + (int) $result['bytes'];
		$state['processed']    = (int) $state['table_offset'];
		$state['current_item'] = (string) end( $slice );

		if ( $state['table_offset'] >= count( $tables ) ) {
			$sql = (string) ( $state['sql_path'] ?? '' );
			if ( '' === $sql || ! is_readable( $sql ) || (int) filesize( $sql ) < 64 ) {
				throw new RuntimeException(
					esc_html__( 'Database export produced an empty file. Pages and other content were not saved.', 'maca-backup' )
				);
			}
			$state['step']          = ( 'database' === $state['type'] ) ? 'manifest' : 'files';
			$state['current_item']  = '';
			$state['current_batch'] = array();
		} elseif ( $chunk->should_yield() ) {
			// Stay on database for next tick.
			$state['step'] = 'database';
		}

		return $state;
	}

	/**
	 * Add files to ZIP in batches.
	 *
	 * @param array<string, mixed>           $state State.
	 * @param Maca_Backup_Pro_Chunk_Processor $chunk Chunk helper.
	 * @return array<string, mixed>
	 */
	private static function step_files( array $state, Maca_Backup_Pro_Chunk_Processor $chunk ): array {
		$files  = self::load_files_list( $state );
		$offset = (int) ( $state['file_offset'] ?? 0 );
		$batch  = (int) Maca_Backup_Pro_Settings::get( 'chunk_files', 400 );
		// Upgrade legacy tiny default (50) so existing installs get competitive speed.
		if ( $batch <= 50 ) {
			$batch = 400;
		}
		$batch     = max( 50, min( 2000, $batch ) );
		$byte_cap  = 48 * 1024 * 1024; // Soft uncompressed bytes per open/close cycle.
		$remaining = max( 0, count( $files ) - $offset );
		$slice     = array_slice( $files, $offset, min( $batch, $remaining ) );

		$state['processed']  = $offset;
		$state['total']      = count( $files );
		$state['file_count'] = count( $files );

		if ( empty( $slice ) ) {
			$state['current_item']  = '';
			$state['current_batch'] = array();
			$state['step']          = 'manifest';
			return $state;
		}

		$max_mb = (int) Maca_Backup_Pro_Settings::get( 'zip_split_mb', 400 );
		$zip    = new Maca_Backup_Pro_Zip_Builder( (string) $state['work_dir'], $max_mb );

		if ( ! $zip->open() ) {
			throw new RuntimeException( esc_html__( 'ZipArchive is required for file backups.', 'maca-backup' ) );
		}

		$last      = '';
		$queued    = 0;
		$inv_path  = trailingslashit( (string) $state['work_dir'] ) . 'inventory.json';
		$inventory = self::load_inventory_file( $inv_path );

		foreach ( $slice as $rel ) {
			// Leave headroom for ZipArchive::close() compression I/O.
			if ( $queued > 0 && ( $chunk->should_yield() || $chunk->memory_pressure() || $queued >= $byte_cap ) ) {
				break;
			}
			$last                  = (string) $rel;
			$state['current_item'] = $last;
			$abs                   = Maca_Backup_Pro_Paths::readable_absolute( (string) $rel );
			if ( '' !== $abs ) {
				$size = (int) filesize( $abs );
				if ( ! $zip->add_file( $abs, $rel ) ) {
					throw new RuntimeException(
						sprintf(
							/* translators: %s: relative file path */
							esc_html__( 'Could not add file to backup archive: %s', 'maca-backup' ),
							esc_html( $rel )
						)
					);
				}
				$queued += max( 0, $size );
				$inventory[ str_replace( '\\', '/', $rel ) ] = array(
					'size'  => $size,
					'mtime' => (int) filemtime( $abs ),
					'crc'   => 0,
				);
			} else {
				// Deep vendor trees (plugin-check / PHPCS) and vanished files must not abort a full backup.
				Maca_Backup_Pro_Logger::warning(
					sprintf(
						/* translators: %s: relative file path */
						__( 'Skipped unreadable file during backup: %s', 'maca-backup' ),
						(string) $rel
					),
					array(
						'path' => (string) $rel,
					)
				);
			}
			++$offset;
		}

		$zip->close();
		self::persist_inventory_file( $inv_path, $inventory );
		$state['file_offset']     = $offset;
		$state['processed']       = $offset;
		$state['current_item']    = $last;
		$state['current_batch']   = array();
		$state['inventory_path']  = $inv_path;
		$state['parts']           = $zip->get_parts();

		if ( $state['file_offset'] >= count( $files ) ) {
			$state['step']          = 'manifest';
			$state['current_item']  = '';
			$state['current_batch'] = array();
		}

		return $state;
	}

	/**
	 * Find ZIP parts in a work directory.
	 *
	 * @param string $work_dir Work directory.
	 * @return string[]
	 */
	private static function discover_parts( string $work_dir ): array {
		$parts = array();

		// Prefer split parts; never count backup.zip alongside part001+ (inflates size).
		$glob = glob( $work_dir . '/backup.part*.zip' );
		if ( is_array( $glob ) && ! empty( $glob ) ) {
			natsort( $glob );
			foreach ( $glob as $path ) {
				if ( is_readable( $path ) ) {
					$parts[] = (string) $path;
				}
			}
		} else {
			$single = $work_dir . '/backup.zip';
			if ( is_readable( $single ) ) {
				$parts[] = $single;
			}
		}

		// Encrypted variants (replace plain parts when present).
		$enc = glob( $work_dir . '/backup*.zip.enc' );
		if ( is_array( $enc ) && ! empty( $enc ) ) {
			natsort( $enc );
			$enc_parts = array();
			foreach ( $enc as $path ) {
				if ( is_readable( $path ) ) {
					$enc_parts[] = (string) $path;
				}
			}
			if ( ! empty( $enc_parts ) ) {
				return array_values( $enc_parts );
			}
		}

		return array_values( $parts );
	}

	/**
	 * Write manifest + checksums, pack SQL into ZIP for DB-only.
	 *
	 * @param array<string, mixed> $state     State.
	 * @param int                  $backup_id Backup ID.
	 * @return array<string, mixed>
	 */
	private static function step_manifest( array $state, int $backup_id ): array {
		$work = (string) $state['work_dir'];
		$max_mb = (int) Maca_Backup_Pro_Settings::get( 'zip_split_mb', 400 );
		$zip    = new Maca_Backup_Pro_Zip_Builder( $work, $max_mb );
		$zip->open();

		$sql      = (string) ( $state['sql_path'] ?? '' );
		$needs_db = 'files' !== (string) ( $state['type'] ?? 'full' );
		if ( $needs_db ) {
			if ( '' === $sql || ! is_readable( $sql ) || (int) filesize( $sql ) < 64 ) {
				throw new RuntimeException(
					esc_html__( 'Database dump is missing from this backup. WordPress pages/posts would not be restorable.', 'maca-backup' )
				);
			}
			if ( ! $zip->add_file( $sql, 'database.sql' ) ) {
				throw new RuntimeException(
					esc_html__( 'Could not pack database.sql into the backup archive.', 'maca-backup' )
				);
			}
		}

		$files     = self::load_files_list( $state );
		$inv_path  = (string) ( $state['inventory_path'] ?? ( trailingslashit( $work ) . 'inventory.json' ) );
		$inventory = self::load_inventory_file( $inv_path );
		if ( empty( $inventory ) ) {
			$inventory = self::build_inventory( $files );
		}
		// Fill CRC from ZIP central directory (cheap) when missing — helps smart restore.
		$inventory = Maca_Backup_Pro_Checksum::enrich_inventory_crc_from_parts( $inventory, self::discover_parts( $work ) );
		$zip->add_from_string( 'files.json', (string) wp_json_encode( $inventory ) );

		$manifest = array(
			'version'           => MACA_BACKUP_PRO_VERSION,
			'created_at'        => gmdate( 'c' ),
			'site_url'          => home_url(),
			'home_url'          => home_url(),
			'siteurl'           => site_url(),
			'table_prefix'      => $GLOBALS['wpdb']->prefix,
			'type'              => $state['type'],
			'scope'             => (string) ( $state['scope'] ?? 'full' ),
			'file_count'        => count( $files ),
			'tables'            => $state['tables'] ?? array(),
			'table_count'       => count( $state['tables'] ?? array() ),
			'has_database'      => $needs_db,
			'db_bytes'          => (int) ( $state['db_bytes'] ?? 0 ),
			'wp_version'        => get_bloginfo( 'version' ),
			'php'               => PHP_VERSION,
			'parent_backup_id'  => (int) ( $state['parent_backup_id'] ?? 0 ),
			'has_inventory'     => true,
			'pre_update'        => ! empty( $state['pre_update'] ),
			'complete'          => $needs_db && count( $files ) > 0,
		);

		$zip->add_from_string( 'manifest.json', (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
		$zip->close();
		$parts = self::discover_parts( $work );

		// Optional AES-256 archive encryption.
		$encrypt    = ! empty( Maca_Backup_Pro_Settings::get( 'encrypt_backups', false ) );
		$passphrase = Maca_Backup_Pro_Settings::backup_passphrase();
		if ( $encrypt && '' !== $passphrase ) {
			$encrypted_parts = array();
			foreach ( $parts as $part ) {
				$enc_path = $part . '.enc';
				$result   = Maca_Backup_Pro_Encryption::encrypt_file( $part, $enc_path, $passphrase );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( esc_html( $result->get_error_message() ) );
				}
				wp_delete_file( $part );
				$encrypted_parts[] = $enc_path;
			}
			$parts                = $encrypted_parts;
			$manifest['encrypted'] = true;
		}

		$checksums = array();
		$crc_parts = array();
		$total     = 0;
		foreach ( $parts as $part ) {
			$hash = Maca_Backup_Pro_Checksum::file( $part );
			$checksums[ basename( $part ) ] = $hash ?: '';
			$crc                              = Maca_Backup_Pro_Checksum::crc32_file( $part );
			if ( '' !== $crc ) {
				$crc_parts[ basename( $part ) ] = $crc;
			}
			$total += (int) filesize( $part );
		}

		$archive_crc = Maca_Backup_Pro_Checksum::crc32_parts( $parts );
		$manifest['crc32']          = $archive_crc;
		$manifest['part_checksums'] = $checksums;
		$manifest['part_crc32']     = $crc_parts;

		$state['parts']      = $parts;
		$state['checksums']  = $checksums;
		$state['crc32']      = $archive_crc;
		$state['size']       = $total;
		$state['manifest']   = $manifest;
		$state['inventory_path'] = $inv_path;
		unset( $state['inventory'] ); // Keep job state small; inventory lives on disk + backups row.
		$state['step']       = 'upload';

		self::persist_inventory_file( $inv_path, $inventory );

		Maca_Backup_Pro_Backups_Table::update(
			$backup_id,
			array(
				'parts'             => count( $parts ),
				'size_bytes'        => $total,
				'db_size_bytes'     => (int) ( $state['db_bytes'] ?? ( is_readable( $sql ) ? filesize( $sql ) : 0 ) ),
				'file_count'        => count( $files ),
				'checksum'          => $archive_crc ?: Maca_Backup_Pro_Checksum::make( (string) wp_json_encode( $checksums ) ),
				'manifest'          => wp_json_encode( $manifest ),
				'inventory'         => wp_json_encode( $inventory ),
				'parent_backup_id'  => (int) ( $state['parent_backup_id'] ?? 0 ),
				'path'              => $parts[0] ?? $work,
			)
		);

		return $state;
	}

	/**
	 * Build durable file inventory (path => size/mtime). CRC filled later from ZIP.
	 *
	 * @param string[] $files Relative paths.
	 * @return array<string, array{size:int,mtime:int,crc:int}>
	 */
	private static function build_inventory( array $files ): array {
		$inventory = array();
		foreach ( $files as $rel ) {
			$rel = str_replace( '\\', '/', (string) $rel );
			$abs = Maca_Backup_Pro_Paths::readable_absolute( (string) $rel );
			if ( '' === $abs ) {
				continue;
			}
			$inventory[ $rel ] = array(
				'size'  => (int) filesize( $abs ),
				'mtime' => (int) filemtime( $abs ),
				'crc'   => 0,
			);
		}
		return $inventory;
	}

	/**
	 * @param string $path Inventory JSON path.
	 * @return array<string, array{size:int,mtime:int,crc:int}>
	 */
	private static function load_inventory_file( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string                                                              $path Inventory JSON path.
	 * @param array<string, array{size?:int,mtime?:int,crc?:int}> $inventory Inventory.
	 * @return void
	 */
	private static function persist_inventory_file( string $path, array $inventory ): void {
		if ( '' === $path ) {
			return;
		}
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		file_put_contents( $path, (string) wp_json_encode( $inventory ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Copy CRC values from ZIP central directory (no full-file re-read).
	 *
	 * @param array<string, array{size?:int,mtime?:int,crc?:int}> $inventory Inventory.
	 * @param string[]                                            $parts     ZIP parts.
	 * @return array<string, array{size:int,mtime:int,crc:int}>
	 */
	private static function enrich_inventory_crc_from_parts( array $inventory, array $parts ): array {
		return Maca_Backup_Pro_Checksum::enrich_inventory_crc_from_parts( $inventory, $parts );
	}

	/**
	 * Upload parts to configured storage.
	 *
	 * @param array<string, mixed> $state     State.
	 * @param int                  $backup_id Backup ID.
	 * @return array<string, mixed>
	 */
	private static function step_upload( array $state, int $backup_id ): array {
		$provider_id = (string) ( $state['storage'] ?? 'local' );
		$provider    = Maca_Backup_Pro_Storage_Registry::instance()->get( $provider_id );
		if ( ! $provider ) {
			throw new RuntimeException( esc_html__( 'Storage provider not found.', 'maca-backup' ) );
		}

		$remote_paths = array();
		foreach ( $state['parts'] ?? array() as $part ) {
			$result = $provider->upload( $part, basename( (string) $state['work_dir'] ) . '/' . basename( $part ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( esc_html( $result->get_error_message() ) );
			}
			$remote_paths[] = $result;
		}

		$state['remote_paths'] = $remote_paths;
		$state['step']         = 'verify';

		Maca_Backup_Pro_Backups_Table::update(
			$backup_id,
			array(
				'path' => $remote_paths[0] ?? ( $state['path'] ?? '' ),
			)
		);

		return $state;
	}

	/**
	 * Verify checksums before marking done.
	 *
	 * @param array<string, mixed> $state     State.
	 * @param int                  $backup_id Backup ID.
	 * @return array<string, mixed>
	 */
	private static function step_verify( array $state, int $backup_id ): array {
		$ok = Maca_Backup_Pro_Verifier::verify_parts( $state['parts'] ?? array(), $state['checksums'] ?? array() );
		if ( ! $ok ) {
			throw new RuntimeException( esc_html__( 'Backup verification failed (checksum mismatch).', 'maca-backup' ) );
		}
		$state['step'] = 'done';
		unset( $backup_id );
		return $state;
	}

	/**
	 * Mark job complete and notify.
	 *
	 * @param int                  $job_id    Job ID.
	 * @param int                  $backup_id Backup ID.
	 * @param array<string, mixed> $state     State.
	 * @return array<string, mixed>
	 */
	private static function complete( int $job_id, int $backup_id, array $state ): array {
		$duration = max( 0, time() - (int) ( $state['started'] ?? time() ) );
		$parts    = array_values( array_filter( array_map( 'strval', (array) ( $state['parts'] ?? array() ) ) ) );
		$path     = (string) ( ( $state['remote_paths'][0] ?? null ) ?: ( $parts[0] ?? ( $state['work_dir'] ?? '' ) ) );
		$size     = (int) ( $state['size'] ?? 0 );
		$work     = (string) ( $state['work_dir'] ?? '' );
		$key      = $work ? basename( $work ) : '';

		$row_data = array(
			'status'           => 'completed',
			'duration'         => $duration,
			'finished_at'      => current_time( 'mysql' ),
			'path'             => $path,
			'parts'            => max( 1, count( $parts ) ),
			'size_bytes'       => $size,
			'db_size_bytes'    => (int) ( $state['db_bytes'] ?? 0 ),
			'file_count'       => (int) ( $state['file_count'] ?? count( self::load_files_list( $state ) ) ),
			'storage'          => sanitize_key( (string) ( $state['storage'] ?? 'local' ) ),
			'type'             => sanitize_key( (string) ( $state['type'] ?? 'full' ) ),
			'parent_backup_id' => (int) ( $state['parent_backup_id'] ?? 0 ),
			'error_message'    => '',
		);

		if ( ! empty( $state['crc32'] ) ) {
			$row_data['checksum'] = (string) $state['crc32'];
		} elseif ( ! empty( $state['checksums'] ) ) {
			$row_data['checksum'] = Maca_Backup_Pro_Checksum::make( (string) wp_json_encode( $state['checksums'] ) );
		}
		if ( ! empty( $state['manifest'] ) ) {
			$row_data['manifest'] = wp_json_encode( $state['manifest'] );
		}
		if ( ! empty( $state['inventory'] ) ) {
			$row_data['inventory'] = wp_json_encode( $state['inventory'] );
		} elseif ( ! empty( $state['inventory_path'] ) ) {
			$inv = self::load_inventory_file( (string) $state['inventory_path'] );
			if ( ! empty( $inv ) ) {
				$row_data['inventory'] = wp_json_encode( $inv );
			}
		}

		$existing = $backup_id > 0 ? Maca_Backup_Pro_Backups_Table::get( $backup_id ) : null;
		if ( ! $existing && $key ) {
			$existing = Maca_Backup_Pro_Backups_Table::get_by_key( $key );
			if ( $existing ) {
				$backup_id = (int) $existing->id;
			}
		}

		if ( $existing ) {
			Maca_Backup_Pro_Backups_Table::update( $backup_id, $row_data );
		} else {
			$row_data['backup_key'] = $key ?: ( 'mbp_recovered_' . $job_id );
			$row_data['started_at'] = ! empty( $state['started'] )
				? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $state['started'] ) )
				: current_time( 'mysql' );
			$row_data['created_at'] = $row_data['started_at'];
			$backup_id              = Maca_Backup_Pro_Backups_Table::insert( $row_data );
		}

		$claimed = Maca_Backup_Pro_Jobs_Table::claim_terminal(
			$job_id,
			'completed',
			array(
				'progress'  => 100,
				'step'      => 'done',
				'backup_id' => $backup_id,
				'state'     => wp_json_encode( $state ),
			)
		);

		// Concurrent processor already finalized this job — do not email/log again.
		if ( ! $claimed ) {
			return array(
				'done'      => true,
				'progress'  => 100,
				'status'    => 'completed',
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			);
		}

		// Ensure the row really exists as completed (guards against silent update misses).
		$saved = $backup_id > 0 ? Maca_Backup_Pro_Backups_Table::get( $backup_id ) : null;
		if ( ! $saved || 'completed' !== (string) $saved->status ) {
			Maca_Backup_Pro_Logger::error(
				__( 'Backup finished but history row could not be saved.', 'maca-backup' ),
				array(
					'backup_id' => $backup_id,
					'job_id'    => $job_id,
				)
			);
		}

		Maca_Backup_Pro_Logger::success(
			__( 'Backup completed.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
				'size'      => $size,
			)
		);

		Maca_Backup_Pro_Mailer::notify_backup(
			true,
			array(
				'size'        => $size,
				'storage'     => $state['storage'] ?? 'local',
				'duration'    => $duration,
				'type'        => sanitize_key( (string) ( $state['type'] ?? 'full' ) ),
				'backup_id'   => $backup_id,
				'job_id'      => $job_id,
				'schedule_id' => sanitize_key( (string) ( $state['schedule_id'] ?? '' ) ),
				'checksum'    => (string) ( $state['crc32'] ?? '' ),
			)
		);

		if ( function_exists( 'maca_backup_pro_api_hub_heartbeat' ) ) {
			maca_backup_pro_api_hub_heartbeat( false );
		}

		self::enforce_retention();

		return array(
			'done'      => true,
			'progress'  => 100,
			'status'    => 'completed',
			'backup_id' => $backup_id,
			'job_id'    => $job_id,
		);
	}

	/**
	 * Cancel a running backup job and clean up its work directory.
	 *
	 * @param int|null $job_id Job ID (defaults to active backup job).
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function cancel( ?int $job_id = null ) {
		$job = $job_id ? Maca_Backup_Pro_Jobs_Table::get( $job_id ) : Maca_Backup_Pro_Jobs_Table::active( 'backup' );
		if ( ! $job || 'backup' !== (string) $job->job_type ) {
			return new WP_Error( 'missing', __( 'No running backup to stop.', 'maca-backup' ) );
		}

		if ( ! in_array( (string) $job->status, array( 'pending', 'running' ), true ) ) {
			return new WP_Error( 'not_running', __( 'That backup is not running.', 'maca-backup' ) );
		}

		$state = json_decode( (string) $job->state, true );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$message = __( 'Backup cancelled by user.', 'maca-backup' );

		Maca_Backup_Pro_Jobs_Table::update(
			(int) $job->id,
			array(
				'status'        => 'cancelled',
				'progress'      => (int) $job->progress,
				'error_message' => $message,
			)
		);

		Maca_Backup_Pro_Backups_Table::update(
			(int) $job->backup_id,
			array(
				'status'        => 'cancelled',
				'error_message' => $message,
				'finished_at'   => current_time( 'mysql' ),
			)
		);

		$work = (string) ( $state['work_dir'] ?? '' );
		if ( $work ) {
			self::rrmdir( $work );
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

		$schedule_id = sanitize_key( (string) ( $state['schedule_id'] ?? '' ) );

		return array_merge(
			array(
				'done'        => $done,
				'progress'    => (int) $job->progress,
				'step'        => (string) $job->step,
				'status'      => (string) $job->status,
				'job_id'      => (int) $job->id,
				'backup_id'   => (int) $job->backup_id,
				'job_type'    => (string) $job->job_type,
				'error'       => (string) ( $job->error_message ?? '' ),
				'started'     => (int) ( $state['started'] ?? 0 ),
				'elapsed'     => max( 0, time() - (int) ( $state['started'] ?? time() ) ),
				'schedule_id' => $schedule_id,
				'scheduled'   => '' !== $schedule_id,
			),
			self::progress_meta( $state )
		);
	}

	/**
	 * Fail a job.
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
				'done'     => true,
				'progress' => 0,
				'status'   => 'failed',
				'error'    => $message,
			);
		}

		if ( $backup_id > 0 ) {
			Maca_Backup_Pro_Backups_Table::update(
				$backup_id,
				array(
					'status'        => 'failed',
					'error_message' => $message,
					'finished_at'   => current_time( 'mysql' ),
				)
			);
		}

		Maca_Backup_Pro_Logger::error(
			$message,
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			)
		);

		Maca_Backup_Pro_Mailer::notify_backup(
			false,
			array(
				'storage'     => Maca_Backup_Pro_Settings::get( 'storage_provider', 'local' ),
				'error'       => $message,
				'type'        => sanitize_key( (string) ( $job_state['type'] ?? 'full' ) ),
				'backup_id'   => $backup_id,
				'job_id'      => $job_id,
				'schedule_id' => sanitize_key( (string) ( $job_state['schedule_id'] ?? '' ) ),
			)
		);

		return array(
			'done'     => true,
			'progress' => 0,
			'status'   => 'failed',
			'error'    => $message,
		);
	}

	/**
	 * Progress percentage with within-step granularity.
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return int
	 */
	private static function progress_for_state( array $state ): int {
		$step = (string) ( $state['step'] ?? '' );

		if ( 'database' === $step ) {
			$tables = $state['tables'] ?? array();
			$total  = count( $tables );
			$offset = (int) ( $state['table_offset'] ?? 0 );
			if ( $total > 0 ) {
				return (int) round( 8 + ( min( $offset, $total ) / $total ) * 22 );
			}
			return 25;
		}

		if ( 'files' === $step ) {
			$total  = (int) ( $state['file_count'] ?? 0 );
			if ( $total < 1 && ! empty( $state['files'] ) && is_array( $state['files'] ) ) {
				$total = count( $state['files'] );
			}
			$offset = (int) ( $state['file_offset'] ?? 0 );
			if ( $total > 0 ) {
				return (int) round( 30 + ( min( $offset, $total ) / $total ) * 45 );
			}
			return 55;
		}

		return match ( $step ) {
			'scan'     => 5,
			'manifest' => 78,
			'upload'   => 88,
			'verify'   => 95,
			'done'     => 100,
			default    => 10,
		};
	}

	/**
	 * UI-facing progress fields (current file/table, counts).
	 *
	 * @param array<string, mixed> $state Job state.
	 * @return array<string, mixed>
	 */
	private static function progress_meta( array $state ): array {
		$step = (string) ( $state['step'] ?? '' );
		$meta = array(
			'current_item'  => (string) ( $state['current_item'] ?? '' ),
			'current_batch' => array_values( array_map( 'strval', (array) ( $state['current_batch'] ?? array() ) ) ),
			'processed'     => (int) ( $state['processed'] ?? 0 ),
			'total'         => (int) ( $state['total'] ?? 0 ),
		);

		if ( 'scan' === $step ) {
			$meta['current_item'] = __( 'Scanning files…', 'maca-backup' );
		} elseif ( 'manifest' === $step ) {
			$meta['current_item'] = __( 'Writing manifest…', 'maca-backup' );
		} elseif ( 'upload' === $step ) {
			$meta['current_item'] = __( 'Uploading backup…', 'maca-backup' );
		} elseif ( 'verify' === $step ) {
			$meta['current_item'] = __( 'Verifying checksums…', 'maca-backup' );
		} elseif ( 'database' === $step && '' === $meta['current_item'] ) {
			$meta['current_item'] = __( 'Exporting database…', 'maca-backup' );
			$meta['total']        = count( $state['tables'] ?? array() );
			$meta['processed']    = (int) ( $state['table_offset'] ?? 0 );
		} elseif ( 'files' === $step && '' === $meta['current_item'] ) {
			$meta['current_item'] = __( 'Packing files…', 'maca-backup' );
			$meta['total']        = (int) ( $state['file_count'] ?? 0 );
			if ( $meta['total'] < 1 && ! empty( $state['files'] ) && is_array( $state['files'] ) ) {
				$meta['total'] = count( $state['files'] );
			}
			$meta['processed'] = (int) ( $state['file_offset'] ?? 0 );
		}

		return $meta;
	}

	/**
	 * Delete oldest backups beyond retention.
	 *
	 * @return void
	 */
	private static function enforce_retention(): void {
		$keep = (int) Maca_Backup_Pro_Settings::get( 'retention_count', 10 );
		if ( $keep < 1 ) {
			$keep = 1;
		}

		$all       = Maca_Backup_Pro_Backups_Table::recent( 500 );
		$completed = array_values(
			array_filter(
				$all,
				static fn( $row ) => 'completed' === (string) $row->status
			)
		);

		if ( count( $completed ) <= $keep ) {
			return;
		}

		$to_delete = array_slice( $completed, $keep );
		foreach ( $to_delete as $row ) {
			self::delete_backup( (int) $row->id );
		}
	}

	/**
	 * Re-create missing history rows from completed jobs and leftover ZIP folders.
	 * Only recovers when archive files still exist on disk.
	 *
	 * @return int Number of recovered backups.
	 */
	public static function reconcile_history(): int {
		$recovered = 0;

		foreach ( Maca_Backup_Pro_Jobs_Table::recent( 'backup', array( 'completed' ), 50 ) as $job ) {
			$backup_id = (int) $job->backup_id;
			$existing  = $backup_id > 0 ? Maca_Backup_Pro_Backups_Table::get( $backup_id ) : null;
			$state     = json_decode( (string) $job->state, true );
			if ( ! is_array( $state ) ) {
				continue;
			}

			// Intentionally purged — do not resurrect.
			if ( ! empty( $state['purged'] ) ) {
				continue;
			}

			$work = (string) ( $state['work_dir'] ?? '' );
			$key  = $work ? basename( $work ) : '';
			if ( ! $existing && $key ) {
				$existing = Maca_Backup_Pro_Backups_Table::get_by_key( $key );
			}

			if ( $existing && 'completed' === (string) $existing->status ) {
				continue;
			}

			$candidate_parts = array_values( array_filter( array_map( 'strval', (array) ( $state['parts'] ?? array() ) ) ) );
			if ( empty( $candidate_parts ) && $work ) {
				$candidate_parts = self::discover_parts( $work );
			}

			// Only keep parts that still exist — stale job state must not resurrect deletes.
			$parts = array();
			foreach ( $candidate_parts as $part ) {
				if ( is_readable( $part ) ) {
					$parts[] = $part;
				}
			}
			if ( empty( $parts ) && $work ) {
				$parts = self::discover_parts( $work );
			}
			if ( empty( $parts ) ) {
				continue;
			}

			$path = (string) ( ( $state['remote_paths'][0] ?? null ) ?: $parts[0] );
			// Remote-only path without local files: skip unless local parts exist (already checked).
			if ( ! is_readable( $path ) && ! empty( $parts ) ) {
				$path = $parts[0];
			}

			$size = (int) ( $state['size'] ?? 0 );
			if ( $size < 1 ) {
				foreach ( $parts as $part ) {
					$size += (int) filesize( $part );
				}
			}

			$data = array(
				'status'        => 'completed',
				'type'          => sanitize_key( (string) ( $state['type'] ?? 'full' ) ),
				'storage'       => sanitize_key( (string) ( $state['storage'] ?? 'local' ) ),
				'path'          => $path,
				'parts'         => max( 1, count( $parts ) ),
				'size_bytes'    => $size,
				'db_size_bytes' => (int) ( $state['db_bytes'] ?? 0 ),
				'file_count'    => (int) ( $state['file_count'] ?? count( self::load_files_list( $state ) ) ),
				'duration'      => max( 0, (int) ( strtotime( (string) $job->updated_at ) - (int) ( $state['started'] ?? time() ) ) ),
				'finished_at'   => (string) ( $job->updated_at ?? current_time( 'mysql' ) ),
				'error_message' => '',
			);

			if ( $existing ) {
				Maca_Backup_Pro_Backups_Table::update( (int) $existing->id, $data );
				++$recovered;
			} else {
				$data['backup_key'] = $key ?: ( 'mbp_recovered_' . (int) $job->id );
				$data['started_at'] = ! empty( $state['started'] )
					? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $state['started'] ) )
					: (string) ( $job->created_at ?? current_time( 'mysql' ) );
				$data['created_at'] = $data['started_at'];
				$new_id             = Maca_Backup_Pro_Backups_Table::insert( $data );
				if ( $new_id > 0 ) {
					Maca_Backup_Pro_Jobs_Table::update(
						(int) $job->id,
						array( 'backup_id' => $new_id )
					);
					++$recovered;
				}
			}
		}

		$base = Maca_Backup_Pro_Settings::local_backup_dir();
		if ( is_dir( $base ) ) {
			$dirs = glob( trailingslashit( $base ) . 'mbp_*', GLOB_ONLYDIR );
			if ( is_array( $dirs ) ) {
				foreach ( $dirs as $dir ) {
					$key = basename( (string) $dir );
					$parts = self::discover_parts( (string) $dir );
					if ( empty( $parts ) ) {
						continue;
					}

					$existing_key = Maca_Backup_Pro_Backups_Table::get_by_key( $key );
					if ( $existing_key ) {
						// Repair import rows that were re-stamped with the ZIP's original created_at.
						if ( str_starts_with( $key, 'mbp_import_' ) ) {
							$when = self::datetime_from_backup_dir( (string) $dir, $parts );
							$fin  = (string) ( $existing_key->finished_at ?? '' );
							if ( '' !== $when && $when !== $fin ) {
								Maca_Backup_Pro_Backups_Table::update(
									(int) $existing_key->id,
									array(
										'started_at'  => $when,
										'finished_at' => $when,
										'created_at'  => $when,
									)
								);
								++$recovered;
							}
						}
						continue;
					}

					$size = 0;
					foreach ( $parts as $part ) {
						$size += is_readable( $part ) ? (int) filesize( $part ) : 0;
					}
					$when = self::datetime_from_backup_dir( (string) $dir, $parts );
					$new_id = Maca_Backup_Pro_Backups_Table::insert(
						array(
							'backup_key'  => $key,
							'type'        => 'full',
							'status'      => 'completed',
							'storage'     => 'local',
							'path'        => $parts[0],
							'parts'       => count( $parts ),
							'size_bytes'  => $size,
							'file_count'  => 0,
							'started_at'  => $when,
							'finished_at' => $when,
							'created_at'  => $when,
						)
					);
					if ( $new_id > 0 ) {
						++$recovered;
					}
				}
			}
		}

		return $recovered;
	}

	/**
	 * Best-effort local MySQL datetime for a leftover backup folder.
	 *
	 * For imports: prefer import-meta.json / folder name / manifest imported_at
	 * so the picker shows import time (newest), not the source backup's created_at
	 * (which made every imported archive look identical).
	 *
	 * For native backups: prefer manifest created_at, then archive mtime —
	 * never stamp every orphan with the same current_time().
	 *
	 * @param string   $dir   Backup work directory.
	 * @param string[] $parts Discovered archive paths.
	 * @return string MySQL datetime in site local time.
	 */
	private static function datetime_from_backup_dir( string $dir, array $parts ): string {
		$key = basename( $dir );

		$from_import_meta = self::datetime_from_import_meta( $dir );
		if ( '' !== $from_import_meta ) {
			return $from_import_meta;
		}

		$from_key = self::datetime_from_import_key( $key );
		if ( '' !== $from_key ) {
			return $from_key;
		}

		if ( class_exists( 'ZipArchive' ) ) {
			foreach ( $parts as $part ) {
				$part = (string) $part;
				if ( ! is_readable( $part ) || str_ends_with( strtolower( $part ), '.enc' ) ) {
					continue;
				}
				$zip = new ZipArchive();
				if ( true !== $zip->open( $part ) ) {
					continue;
				}
				$raw = $zip->getFromName( 'manifest.json' );
				$zip->close();
				if ( false === $raw || '' === $raw ) {
					continue;
				}
				$manifest = json_decode( (string) $raw, true );
				if ( ! is_array( $manifest ) ) {
					continue;
				}
				// imported_at first — never let source created_at mask a fresh import.
				$fields = str_starts_with( $key, 'mbp_import_' )
					? array( 'imported_at', 'finished_at', 'created_at' )
					: array( 'created_at', 'finished_at', 'imported_at' );
				foreach ( $fields as $field ) {
					if ( empty( $manifest[ $field ] ) || ! is_string( $manifest[ $field ] ) ) {
						continue;
					}
					$parsed = strtotime( $manifest[ $field ] );
					if ( false !== $parsed && $parsed > 0 ) {
						return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $parsed ) );
					}
				}
			}
		}

		$mtime = 0;
		foreach ( $parts as $part ) {
			if ( is_readable( (string) $part ) ) {
				$mtime = max( $mtime, (int) filemtime( (string) $part ) );
			}
		}
		if ( $mtime < 1 && is_dir( $dir ) ) {
			$mtime = (int) filemtime( $dir );
		}
		if ( $mtime > 0 ) {
			return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $mtime ) );
		}

		return current_time( 'mysql' );
	}

	/**
	 * Read import-meta.json written by the importer.
	 *
	 * @param string $dir Backup directory.
	 * @return string Local MySQL datetime or empty.
	 */
	private static function datetime_from_import_meta( string $dir ): string {
		$path = trailingslashit( $dir ) . 'import-meta.json';
		if ( ! is_readable( $path ) ) {
			return '';
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw || '' === $raw ) {
			return '';
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['imported_at'] ) || ! is_string( $data['imported_at'] ) ) {
			return '';
		}
		$parsed = strtotime( $data['imported_at'] );
		if ( false === $parsed || $parsed < 1 ) {
			return '';
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $parsed ) );
	}

	/**
	 * Parse mbp_import_YYYYMMDD_HHMMSS_* folder names.
	 *
	 * @param string $key Backup folder basename.
	 * @return string Local MySQL datetime or empty.
	 */
	private static function datetime_from_import_key( string $key ): string {
		if ( ! preg_match( '/^mbp_import_(\d{8})_(\d{6})_/', $key, $m ) ) {
			return '';
		}
		$gmt = substr( $m[1], 0, 4 ) . '-' . substr( $m[1], 4, 2 ) . '-' . substr( $m[1], 6, 2 )
			. ' ' . substr( $m[2], 0, 2 ) . ':' . substr( $m[2], 2, 2 ) . ':' . substr( $m[2], 4, 2 );
		$parsed = strtotime( $gmt . ' UTC' );
		if ( false === $parsed || $parsed < 1 ) {
			return '';
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $parsed ) );
	}

	/**
	 * Delete backup files + DB row, and prevent reconcile from resurrecting it.
	 *
	 * @param int $backup_id Backup ID.
	 * @return bool
	 */
	public static function delete_backup( int $backup_id ): bool {
		$row = Maca_Backup_Pro_Backups_Table::get( $backup_id );
		if ( ! $row ) {
			return false;
		}

		$key = (string) $row->backup_key;
		$dir = $key ? trailingslashit( Maca_Backup_Pro_Settings::local_backup_dir() ) . $key : '';

		$provider = Maca_Backup_Pro_Storage_Registry::instance()->get( (string) $row->storage );
		if ( $provider ) {
			$paths = array();
			if ( ! empty( $row->path ) ) {
				$paths[] = (string) $row->path;
			}
			// Multi-part siblings next to the primary path.
			$primary = (string) ( $row->path ?? '' );
			if ( $primary && preg_match( '/backup\.part\d+\.zip(\.enc)?$/i', $primary ) ) {
				$parent = dirname( $primary );
				$glob   = glob( $parent . '/backup.part*.zip*' );
				if ( is_array( $glob ) ) {
					$paths = array_merge( $paths, $glob );
				}
			}
			if ( $dir && is_dir( $dir ) ) {
				$local_parts = self::discover_parts( $dir );
				$paths       = array_merge( $paths, $local_parts );
			}
			foreach ( array_unique( $paths ) as $remote ) {
				$provider->delete( (string) $remote );
			}
		}

		if ( $dir && is_dir( $dir ) ) {
			self::rrmdir( $dir );
		}

		// Mark related jobs purged so history reconcile cannot recreate the row.
		Maca_Backup_Pro_Jobs_Table::purge_backup( $backup_id, $key );

		return Maca_Backup_Pro_Backups_Table::delete( $backup_id );
	}

	/**
	 * Recursive directory delete.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function rrmdir( string $dir ): void {
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
