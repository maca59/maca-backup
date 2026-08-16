<?php
/**
 * ZIP archive builder with optional splitting.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds ZIP archives and splits large archives into parts.
 */
class Maca_Backup_Pro_Zip_Builder {

	/**
	 * Working directory for the current backup.
	 *
	 * @var string
	 */
	private string $work_dir;

	/**
	 * ZipArchive instance.
	 *
	 * @var ZipArchive|null
	 */
	private ?ZipArchive $zip = null;

	/**
	 * Current part number (1-based).
	 *
	 * @var int
	 */
	private int $part = 1;

	/**
	 * Created part absolute paths.
	 *
	 * @var string[]
	 */
	private array $parts = array();

	/**
	 * Max part size in bytes.
	 *
	 * @var int
	 */
	private int $max_part_bytes;

	/**
	 * Uncompressed bytes queued since last close/rotate (addFile is deferred).
	 *
	 * @var int
	 */
	private int $pending_bytes = 0;

	/**
	 * Extensions that are already compressed — store without DEFLATE.
	 *
	 * @var string[]
	 */
	private const STORE_EXTENSIONS = array(
		'7z',
		'aac',
		'avi',
		'avif',
		'br',
		'bz2',
		'docx',
		'flac',
		'gif',
		'gz',
		'heic',
		'heif',
		'jpeg',
		'jpg',
		'm4a',
		'm4v',
		'mkv',
		'mov',
		'mp3',
		'mp4',
		'ogg',
		'ogv',
		'opus',
		'pdf',
		'png',
		'pptx',
		'rar',
		'webm',
		'webp',
		'woff',
		'woff2',
		'xlsx',
		'xz',
		'zip',
	);

	/**
	 * Constructor.
	 *
	 * @param string $work_dir Working directory.
	 * @param int    $max_mb   Soft max MB per part (0 = no split).
	 */
	public function __construct( string $work_dir, int $max_mb = 400 ) {
		$this->work_dir       = untrailingslashit( $work_dir );
		$this->max_part_bytes = $max_mb > 0 ? $max_mb * 1024 * 1024 : 0;
		if ( ! is_dir( $this->work_dir ) ) {
			wp_mkdir_p( $this->work_dir );
		}
		$this->resume_existing_parts();
	}

	/**
	 * Continue writing into the latest existing part instead of reopening part 001.
	 *
	 * @return void
	 */
	private function resume_existing_parts(): void {
		$single = $this->work_dir . '/backup.zip';
		if ( is_readable( $single ) ) {
			$this->part  = 1;
			$this->parts = array( $single );
			return;
		}

		$glob = glob( $this->work_dir . '/backup.part*.zip' );
		if ( ! is_array( $glob ) || empty( $glob ) ) {
			return;
		}

		natsort( $glob );
		$this->parts = array_values( $glob );
		$last        = (string) end( $this->parts );
		if ( preg_match( '/part(\d+)\.zip$/i', $last, $m ) ) {
			$this->part = max( 1, (int) $m[1] );
		}
	}

	/**
	 * Open (or rotate) the current ZIP part.
	 *
	 * @return bool
	 */
	public function open(): bool {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}

		$path      = $this->part_path( $this->part );
		$this->zip = new ZipArchive();
		$flags     = file_exists( $path ) ? 0 : ZipArchive::CREATE;
		$ok        = true === $this->zip->open( $path, $flags );
		if ( $ok && ! in_array( $path, $this->parts, true ) ) {
			$this->parts[] = $path;
		}
		return $ok;
	}

	/**
	 * Add a file from disk under a relative archive name.
	 *
	 * @param string $absolute Absolute source.
	 * @param string $arcname  Name inside ZIP.
	 * @return bool
	 */
	public function add_file( string $absolute, string $arcname ): bool {
		if ( ! $this->zip ) {
			if ( ! $this->open() ) {
				return false;
			}
		}

		$source = Maca_Backup_Pro_Paths::readable_path( $absolute );
		if ( '' === $source ) {
			$source = $absolute;
		}
		$size = is_file( $source ) ? (int) filesize( $source ) : 0;
		if ( $this->max_part_bytes > 0 && $this->current_part_bytes() + $this->pending_bytes + $size >= $this->max_part_bytes && $this->pending_bytes > 0 ) {
			if ( ! $this->rotate() ) {
				return false;
			}
		}

		if ( false !== $this->zip->locateName( $arcname ) ) {
			$this->zip->deleteName( $arcname );
		}

		$ok = $this->zip->addFile( $source, $arcname );
		if ( ! $ok ) {
			$native = Maca_Backup_Pro_Paths::native( $source );
			if ( $native !== $source ) {
				$ok = $this->zip->addFile( $native, $arcname );
			}
		}
		// ZipArchive::addFile often rejects Windows long-path prefixes; embed small files instead.
		if ( ! $ok && $size <= 16 * 1024 * 1024 ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents = @file_get_contents( $source );
			if ( false !== $contents ) {
				$ok = $this->zip->addFromString( $arcname, $contents );
			}
		}
		if ( ! $ok ) {
			return false;
		}

		if ( self::should_store( $arcname ) && method_exists( $this->zip, 'setCompressionName' ) ) {
			$this->zip->setCompressionName( $arcname, ZipArchive::CM_STORE );
		}

		$this->pending_bytes += max( 0, $size );
		return true;
	}

	/**
	 * Add string content as a file in the archive.
	 *
	 * @param string $arcname Archive path.
	 * @param string $content Content.
	 * @return bool
	 */
	public function add_from_string( string $arcname, string $content ): bool {
		if ( ! $this->zip ) {
			if ( ! $this->open() ) {
				return false;
			}
		}
		if ( false !== $this->zip->locateName( $arcname ) ) {
			$this->zip->deleteName( $arcname );
		}
		$ok = $this->zip->addFromString( $arcname, $content );
		if ( $ok ) {
			$this->pending_bytes += strlen( $content );
		}
		return $ok;
	}

	/**
	 * Close current ZIP.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( $this->zip ) {
			$this->zip->close();
			$this->zip = null;
		}
		$this->pending_bytes = 0;
	}

	/**
	 * Start a new part.
	 *
	 * @return bool
	 */
	public function rotate(): bool {
		$this->close();

		// Promote backup.zip → part001 when first split happens.
		if ( 1 === $this->part ) {
			$single = $this->work_dir . '/backup.zip';
			$part1  = sprintf( '%s/backup.part001.zip', $this->work_dir );
			if ( is_readable( $single ) && ! file_exists( $part1 ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
				rename( $single, $part1 );
				foreach ( $this->parts as $i => $path ) {
					if ( $path === $single ) {
						$this->parts[ $i ] = $part1;
					}
				}
			}
		}

		++$this->part;
		return $this->open();
	}

	/**
	 * Absolute paths of all parts.
	 *
	 * @return string[]
	 */
	public function get_parts(): array {
		$this->close();
		return $this->parts;
	}

	/**
	 * Total size of all parts.
	 *
	 * @return int
	 */
	public function total_size(): int {
		$total = 0;
		foreach ( $this->get_parts() as $path ) {
			if ( is_readable( $path ) ) {
				$total += (int) filesize( $path );
			}
		}
		return $total;
	}

	/**
	 * On-disk size of the current part (0 if not written yet).
	 *
	 * @return int
	 */
	private function current_part_bytes(): int {
		$path = $this->part_path( $this->part );
		return is_readable( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Whether to skip DEFLATE for an already-compressed path.
	 *
	 * @param string $arcname Archive path.
	 * @return bool
	 */
	private static function should_store( string $arcname ): bool {
		$ext = strtolower( (string) pathinfo( $arcname, PATHINFO_EXTENSION ) );
		return '' !== $ext && in_array( $ext, self::STORE_EXTENSIONS, true );
	}

	/**
	 * Path for a part number. Part 1 stays backup.zip until a split is needed.
	 *
	 * @param int $part Part number.
	 * @return string
	 */
	private function part_path( int $part ): string {
		if ( 1 === $part ) {
			$part1 = sprintf( '%s/backup.part001.zip', $this->work_dir );
			if ( is_readable( $part1 ) ) {
				return $part1;
			}
			return $this->work_dir . '/backup.zip';
		}
		return sprintf( '%s/backup.part%03d.zip', $this->work_dir, $part );
	}
}
