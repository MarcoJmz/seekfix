<?php
/**
 * Controllable clock for tests.
 *
 * @package SeekFix
 */

/**
 * Controllable clock for Checker tests.
 */
class SeekFix_Test_Clock {

	/**
	 * Current time.
	 *
	 * @var float
	 */
	public $time;

	/**
	 * Seconds slept, in order.
	 *
	 * @var float[]
	 */
	public $sleeps = array();

	/**
	 * Creates the clock.
	 *
	 * @param float $time Start time.
	 */
	public function __construct( $time ) {
		$this->time = (float) $time;
	}

	/**
	 * Returns the time (callable as a clock).
	 *
	 * @return float
	 */
	public function now() {
		return $this->time;
	}

	/**
	 * Moves the clock forward.
	 *
	 * @param float $seconds Seconds.
	 */
	public function advance( $seconds ) {
		$this->time += $seconds;
	}

	/**
	 * Sleeps by moving the clock (callable as a sleeper).
	 *
	 * @param float $seconds Seconds.
	 */
	public function sleep( $seconds ) {
		$this->sleeps[] = $seconds;
		$this->advance( $seconds );
	}
}
