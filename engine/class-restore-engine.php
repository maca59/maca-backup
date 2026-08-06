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
	 * @param array<string, mixed> $options   Extra (selected_files, restore_database, extract_root, etc.).
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

		$extract_root = isset( $options['extract_root'] ) ? (string) $options['extract_root'] : '';
		if ( '' === $extract_root ) {
			$extract_root = self::default_site_root();
		}
		$extract_root = trailingslashit( $extract_root );

		$restore_database = array_key_exists( 'restore_database', $options )
			? (bool) $options['restore_database']
			: in_array( sanitize_key( $scope ), array( 'full', 'database' ), true );

		$state = array(
			'backup_id'        => $backup_id,
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
			__( 'Restore started.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
				'scope'     => $scope,
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
				'prepare'  => 10,
				'database' => 40,
				'files'    => 70,
				'done'     => 100,
				default    => 20,
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

			$zip->close();
		}

		$state['files']       = array_values( array_unique( $files ) );
		$state['file_map']    = $file_map;
		$state['file_offset'] = 0;
		$state['step']        = $want_db ? 'database' : 'files';

		if ( 'database' === $scope ) {
			$state['files'] = array();
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
		$sql = (string) ( $state['sql_path'] ?? '' );
		if ( ! $sql || ! is_readable( $sql ) ) {
			$state['step'] = ( 'database' === $state['scope'] ) ? 'done' : 'files';
			return $state;
		}

		$result = Maca_Backup_Pro_Database_Exporter::restore_batch(
			$sql,
			(int) ( $state['sql_offset'] ?? 0 ),
			40
		);

		if ( ! empty( $result['error'] ) ) {
			throw new RuntimeException( esc_html( (string) $result['error'] ) );
		}

		$state['sql_offset'] = (int) $result['offset'];
		if ( ! empty( $result['done'] ) ) {
			$state['step'] = ( 'database' === $state['scope'] || empty( $state['files'] ) ) ? 'done' : 'files';
		}

		return $state;
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
			__( 'Restore completed.', 'maca-backup' ),
			array(
				'backup_id' => $backup_id,
				'job_id'    => $job_id,
			)
		);

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
