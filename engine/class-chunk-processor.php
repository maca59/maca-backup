<?php
/**
 * Chunk / time-budget helper for shared hosts.
 *
 * @package Maca_Backup_Pro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks time and memory budgets so long jobs can resume.
 */
class Maca_Backup_Pro_Chunk_Processor {

	/**
	 * Start timestamp.
	 *
	 * @var float
	 */
	private float $started;

	/**
	 * Soft time limit in seconds.
	 *
	 * @var int
	 */
	private int $time_budget;

	/**
	 * Constructor.
	 *
	 * @param int $time_budget Soft seconds before yielding.
	 */
	public function __construct( int $time_budget = 15 ) {
		$this->started     = microtime( true );
		$this->time_budget = max( 2, $time_budget );
	}

	/**
	 * Whether the soft time budget is exhausted.
	 *
	 * @return bool
	 */
	public function should_yield(): bool {
		return ( microtime( true ) - $this->started ) >= $this->time_budget;
	}

	/**
	 * Elapsed seconds.
	 *
	 * @return float
	 */
	public function elapsed(): float {
		return microtime( true ) - $this->started;
	}

	/**
	 * Soft memory check (80% of memory_limit).
	 *
	 * @return bool
	 */
	public function memory_pressure(): bool {
		$limit = $this->memory_limit_bytes();
		if ( $limit <= 0 ) {
			return false;
		}
		return memory_get_usage( true ) >= (int) ( $limit * 0.8 );
	}

	/**
	 * Parse PHP memory_limit.
	 *
	 * @return int
	 */
	private function memory_limit_bytes(): int {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '-1' === $raw ) {
			return 0;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;
		return match ( $unit ) {
			'g' => $value * 1024 * 1024 * 1024,
			'm' => $value * 1024 * 1024,
			'k' => $value * 1024,
			default => (int) $raw,
		};
	}
}
