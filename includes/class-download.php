<?php
/**
 * Reliable backup file delivery (large archives on shared hosting).
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Streams or offloads backup downloads to avoid PHP/proxy 503s.
 */
class Maca_Backup_Pro_Download {

	public const DL_DIR_NAME   = 'maca-backup-dl';
	public const CLEANUP_HOOK  = 'maca_backup_cleanup_downloads';
	public const LINK_TTL      = HOUR_IN_SECONDS;
	public const PUBLIC_MIN_MB = 8;

	public const UNLINK_HOOK = 'maca_backup_unlink_download';

	/**
	 * Hook cleanup cron.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_expired' ) );
		add_action( self::UNLINK_HOOK, array( __CLASS__, 'unlink_file' ), 10, 1 );
	}

	/**
	 * Cron callback: delete a temporary package file.
	 *
	 * @param string $file Absolute path.
	 * @return void
	 */
	public static function unlink_file( $file ): void {
		if ( is_string( $file ) && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Deliver a file: prefer static URL / sendfile, else chunked PHP stream.
	 *
	 * @param string $path     Absolute readable file path.
	 * @param string $filename Download filename.
	 * @param string $mime     Content-Type.
	 * @param bool   $unlink   Delete $path after a successful PHP stream (not after redirect).
	 * @return void
	 */
	public static function deliver( string $path, string $filename, string $mime = 'application/octet-stream', bool $unlink = false ): void {
		$path = wp_normalize_path( $path );
		if ( '' === $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			wp_die( esc_html__( 'Could not read backup file for download.', 'maca-backup' ) );
		}

		$filename = self::sanitize_filename( $filename );
		$size     = filesize( $path );
		$size     = false !== $size ? (int) $size : 0;

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'session_write_close' ) ) {
			session_write_close();
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'apache_setenv' ) ) {
			// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Best-effort hosting hint.
			@apache_setenv( 'no-gzip', '1' );
		}
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Disable compression so Content-Length stays valid.
		@ini_set( 'zlib.output_compression', 'Off' );

		nocache_headers();
		header( 'X-Accel-Buffering: no' );
		header( 'Content-Encoding: none' );

		// Large local files: let the web server serve a short-lived public link (avoids 503).
		if ( $size >= self::PUBLIC_MIN_MB * MB_IN_BYTES ) {
			$url = self::create_ephemeral_url( $path, $filename );
			if ( is_string( $url ) && '' !== $url ) {
				if ( $unlink ) {
					// Keep source until link expires; schedule delayed delete of source.
					self::schedule_unlink( $path, self::LINK_TTL + 60 );
				}
				wp_safe_redirect( $url, 302 );
				exit;
			}
		}

		if ( self::try_sendfile( $path, $filename, $mime, $size ) ) {
			if ( $unlink ) {
				wp_delete_file( $path );
			}
			exit;
		}

		self::stream_chunks( $path, $filename, $mime, $size );
		if ( $unlink ) {
			wp_delete_file( $path );
		}
		exit;
	}

	/**
	 * Absolute + URL for the ephemeral download root under uploads.
	 *
	 * @return array{dir:string,url:string}|null
	 */
	public static function dl_root(): ?array {
		$basedir = Maca_Backup_Pro_Paths::uploads_basedir();
		$baseurl = Maca_Backup_Pro_Paths::uploads_baseurl();
		if ( '' === $basedir || '' === $baseurl ) {
			return null;
		}

		$dir = $basedir . '/' . self::DL_DIR_NAME;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return null;
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return array(
			'dir' => $dir,
			'url' => trailingslashit( $baseurl ) . self::DL_DIR_NAME,
		);
	}

	/**
	 * Hardlink/symlink (or small copy) into a token folder and return a public URL.
	 *
	 * Large archives are then served by the web server (not PHP), which avoids 503s.
	 *
	 * @param string $path     Source file.
	 * @param string $filename Public download name.
	 * @return string|null
	 */
	public static function create_ephemeral_url( string $path, string $filename ): ?string {
		$root = self::dl_root();
		if ( ! $root ) {
			return null;
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$token = wp_generate_password( 32, false, false );
		}

		$token_dir = trailingslashit( $root['dir'] ) . $token;
		if ( ! wp_mkdir_p( $token_dir ) ) {
			return null;
		}

		$dest   = trailingslashit( $token_dir ) . $filename;
		$linked = false;

		if ( function_exists( 'link' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hardlink may be unsupported on some FS.
			$linked = @link( $path, $dest );
		}
		if ( ! $linked && function_exists( 'symlink' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$linked = @symlink( $path, $dest );
		}
		if ( ! $linked ) {
			// Avoid doubling large archives on disk; caller falls back to PHP streaming.
			$size = filesize( $path );
			if ( false === $size || $size > 32 * MB_IN_BYTES ) {
				self::rrmdir( $token_dir );
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( ! @copy( $path, $dest ) ) {
				self::rrmdir( $token_dir );
				return null;
			}
		}

		$ht = trailingslashit( $token_dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents(
				$ht,
				"<IfModule mod_headers.c>\nHeader set Content-Disposition \"attachment\"\n</IfModule>\n"
			);
		}

		self::schedule_cleanup();
		set_transient(
			'maca_bp_dl_' . $token,
			array(
				'dir' => $token_dir,
				'exp' => time() + self::LINK_TTL,
			),
			self::LINK_TTL + MINUTE_IN_SECONDS
		);

		return trailingslashit( $root['url'] ) . $token . '/' . rawurlencode( $filename );
	}

	/**
	 * Remove expired ephemeral download folders.
	 *
	 * @return void
	 */
	public static function cleanup_expired(): void {
		$root = self::dl_root();
		if ( ! $root || ! is_dir( $root['dir'] ) ) {
			return;
		}

		$now = time();
		$entries = scandir( $root['dir'] );
		if ( ! is_array( $entries ) ) {
			return;
		}

		foreach ( $entries as $name ) {
			if ( '.' === $name || '..' === $name || 'index.php' === $name ) {
				continue;
			}
			$dir = trailingslashit( $root['dir'] ) . $name;
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$meta = get_transient( 'maca_bp_dl_' . $name );
			$exp  = is_array( $meta ) && isset( $meta['exp'] ) ? (int) $meta['exp'] : 0;
			$mtime = (int) filemtime( $dir );
			$stale = ( $exp > 0 && $now > $exp ) || ( $exp <= 0 && $mtime > 0 && ( $now - $mtime ) > self::LINK_TTL );

			if ( $stale ) {
				self::rrmdir( $dir );
				delete_transient( 'maca_bp_dl_' . $name );
			}
		}
	}

	/**
	 * @return void
	 */
	private static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_single_event( time() + self::LINK_TTL + 120, self::CLEANUP_HOOK );
		}
	}

	/**
	 * @param string $path Absolute path.
	 * @param int    $delay Seconds.
	 * @return void
	 */
	private static function schedule_unlink( string $path, int $delay ): void {
		if ( ! wp_next_scheduled( self::UNLINK_HOOK, array( $path ) ) ) {
			wp_schedule_single_event( time() + max( 60, $delay ), self::UNLINK_HOOK, array( $path ) );
		}
	}

	/**
	 * Try Apache mod_xsendfile offload when clearly available.
	 *
	 * @param string $path     Absolute path.
	 * @param string $filename Filename.
	 * @param string $mime     MIME.
	 * @param int    $size     Bytes.
	 * @return bool True if headers sent and caller should exit.
	 */
	private static function try_sendfile( string $path, string $filename, string $mime, int $size ): bool {
		$can = false;
		if ( function_exists( 'apache_get_modules' ) ) {
			$modules = apache_get_modules();
			$can     = is_array( $modules ) && in_array( 'mod_xsendfile', $modules, true );
		}
		if ( ! $can && ! empty( $_SERVER['HTTP_X_SENDFILE_TYPE'] ) ) {
			$can = true;
		}
		if ( ! $can ) {
			return false;
		}

		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . (string) $size );
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: public' );
		header( 'X-Sendfile: ' . $path );
		return true;
	}

	/**
	 * Chunked PHP stream fallback.
	 *
	 * @param string $path     Path.
	 * @param string $filename Filename.
	 * @param string $mime     MIME.
	 * @param int    $size     Size.
	 * @return void
	 */
	private static function stream_chunks( string $path, string $filename, string $mime, int $size ): void {
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( $size > 0 ) {
			header( 'Content-Length: ' . (string) $size );
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: public' );

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			wp_die( esc_html__( 'Could not read backup file for download.', 'maca-backup' ) );
		}

		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 512 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary.
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * @param string $filename Raw name.
	 * @return string
	 */
	private static function sanitize_filename( string $filename ): string {
		$filename = sanitize_file_name( $filename );
		return '' !== $filename ? $filename : 'backup.zip';
	}

	/**
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function rrmdir( string $dir ): void {
		$dir = wp_normalize_path( untrailingslashit( $dir ) );
		$root = self::dl_root();
		if ( ! $root || '' === $dir || ! str_starts_with( $dir, trailingslashit( $root['dir'] ) ) ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$full = $dir . '/' . $item;
				if ( is_dir( $full ) && ! is_link( $full ) ) {
					self::rrmdir( $full );
				} else {
					wp_delete_file( $full );
				}
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
