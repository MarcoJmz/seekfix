<?php
/**
 * State of a full scan: start, pause, resume, cancel and progress.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\LinkRepository;

/**
 * Runs a full scan in short steps: first extraction, then checking.
 *
 * Each step does as much as fits in its time budget and saves its cursor in
 * the seekfix_scan_state option (no autoload). The admin screen asks for a
 * step every few seconds while it is open, so a scan finishes even when
 * WP-Cron does not run. Otherwise Scheduler runs the steps with WP-Cron.
 */
class ScanController {

	/**
	 * Option holding the scan state.
	 */
	const OPTION = 'seekfix_scan_state';

	/**
	 * Option used as a lock so that two steps never run at once.
	 */
	const LOCK = 'seekfix_scan_lock';

	/**
	 * Seconds a lock is honored, in case a step dies without releasing it.
	 */
	const LOCK_TTL = 60;

	/**
	 * Default time budget of a step requested by the admin screen, in seconds.
	 */
	const STEP_BUDGET = 8;

	/**
	 * Confirmations due within this many seconds keep a scan running.
	 */
	const CONFIRM_WINDOW = 60;

	/**
	 * Statuses.
	 */
	const IDLE    = 'idle';
	const RUNNING = 'running';
	const PAUSED  = 'paused';

	/**
	 * Post indexer.
	 *
	 * @var PostIndexer
	 */
	private $indexer;

	/**
	 * Checker, created on first use.
	 *
	 * @var Checker|null
	 */
	private $checker;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Returns the current Unix time.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Creates the controller. Every dependency can be replaced in tests.
	 *
	 * @param PostIndexer|null    $indexer Post indexer.
	 * @param Checker|null        $checker Checker.
	 * @param LinkRepository|null $links   URL rows.
	 * @param callable|null       $clock   Returns the time as a number.
	 */
	public function __construct( $indexer = null, $checker = null, $links = null, $clock = null ) {
		$this->indexer = $indexer ? $indexer : Plugin::indexer();
		$this->checker = $checker;
		$this->links   = $links ? $links : new LinkRepository();
		$this->clock   = $clock ? $clock : 'time';
	}

	/**
	 * Current state, with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::OPTION, array() );

		return array_merge(
			array(
				'status'           => self::IDLE,
				'phase'            => 'extract',
				'cursor'           => 0,
				'started_at'       => null,
				'last_finished_at' => null,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Starts a full scan: every post is extracted again and every URL checked.
	 *
	 * Statuses are kept: a URL that was broken stays broken until a check
	 * says otherwise.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function start() {
		$state = $this->state();
		$now   = $this->mysql_now();

		$state['status']     = self::RUNNING;
		$state['phase']      = 'extract';
		$state['cursor']     = 0;
		$state['started_at'] = $now;

		$this->save( $state );
		$this->links->make_all_due( $now );
		Scheduler::queue_step();

		return $this->progress();
	}

	/**
	 * Pauses a running scan.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function pause() {
		$state = $this->state();

		if ( self::RUNNING === $state['status'] ) {
			$state['status'] = self::PAUSED;
			$this->save( $state );
		}

		return $this->progress();
	}

	/**
	 * Resumes a paused scan where it stopped.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function resume() {
		$state = $this->state();

		if ( self::PAUSED === $state['status'] ) {
			$state['status'] = self::RUNNING;
			$this->save( $state );
			Scheduler::queue_step();
		}

		return $this->progress();
	}

	/**
	 * Cancels the scan. What was already checked keeps its status.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function cancel() {
		$state = $this->state();

		$state['status'] = self::IDLE;
		$this->save( $state );

		return $this->progress();
	}

	/**
	 * Runs one step of a running scan.
	 *
	 * Does nothing when no scan is running or another step holds the lock.
	 *
	 * @param int $budget Seconds available.
	 * @return array<string, mixed> Progress.
	 */
	public function step( $budget = self::STEP_BUDGET ) {
		if ( self::RUNNING !== $this->state()['status'] || ! $this->lock() ) {
			return $this->progress();
		}

		$deadline = $this->now() + $budget;
		$finished = false;

		try {
			while ( $this->now() < $deadline ) {
				$state = $this->state();

				if ( self::RUNNING !== $state['status'] ) {
					break;
				}

				if ( 'extract' === $state['phase'] ) {
					$last = $this->indexer->index_batch( (int) $state['cursor'] );

					if ( null === $last ) {
						$state['phase'] = 'check';
						$this->links->delete_orphans();
					} else {
						$state['cursor'] = $last;
					}

					$this->save( $state );
					continue;
				}

				$done = $this->checker()->run_batch( Checker::BATCH_SIZE, max( 1, $deadline - $this->now() ) );

				if ( 0 === $done ) {
					if ( $this->is_finished( $state ) ) {
						$state['status']           = self::IDLE;
						$state['last_finished_at'] = $this->mysql_now();
						$this->save( $state );
						$finished = true;
					}

					// Nothing due yet: confirmations wait their 45 s.
					break;
				}
			}
		} finally {
			$this->unlock();
		}

		if ( $finished ) {
			/**
			 * Fires once when a full scan has checked every URL.
			 *
			 * @param array<string, mixed> $progress Progress, as progress() returns it.
			 */
			do_action( 'seekfix_scan_finished', $this->progress() );
		}

		return $this->progress();
	}

	/**
	 * Progress of the current or last scan, for the summary.
	 *
	 * @return array<string, mixed> status, phase, posts_done, posts_total, urls_checked,
	 *                              urls_total, started_at and last_finished_at (UTC).
	 */
	public function progress() {
		$state = $this->state();
		$urls  = $state['started_at'] ? $this->links->count_checked_since( $state['started_at'] ) : array(
			'total'   => 0,
			'checked' => 0,
		);

		$posts_total = $this->indexer->count_posts();
		$posts_done  = 'check' === $state['phase'] ? $posts_total : $this->indexer->count_posts( (int) $state['cursor'] );

		return array(
			'status'           => $state['status'],
			'phase'            => $state['phase'],
			'posts_done'       => min( $posts_done, $posts_total ),
			'posts_total'      => $posts_total,
			'urls_checked'     => $urls['checked'],
			'urls_total'       => $urls['total'],
			'started_at'       => $state['started_at'],
			'last_finished_at' => $state['last_finished_at'],
		);
	}

	/**
	 * Whether every URL was checked since the scan started and no
	 * confirmation is about to run.
	 *
	 * @param array<string, mixed> $state Scan state.
	 * @return bool
	 */
	private function is_finished( array $state ) {
		$urls = $this->links->count_checked_since( (string) $state['started_at'] );

		return $urls['checked'] >= $urls['total']
			&& 0 === $this->links->count_due_before( gmdate( 'Y-m-d H:i:s', (int) $this->now() + self::CONFIRM_WINDOW ) );
	}

	/**
	 * Takes the step lock.
	 *
	 * INSERT IGNORE on the unique option_name is atomic, so only one request
	 * wins; add_option() is not, because it upserts. A lock older than
	 * LOCK_TTL is taken over.
	 *
	 * @return bool
	 */
	private function lock() {
		global $wpdb;

		$expires = (string) ( (int) $this->now() + self::LOCK_TTL );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock; the options API cannot do it.
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $expires ) );

		if ( 1 !== $taken ) {
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );

			if ( null !== $current && (int) $current < $this->now() ) {
				$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $expires, self::LOCK, $current ) );
			}
		}
		// phpcs:enable

		wp_cache_delete( self::LOCK, 'options' );

		return 1 === $taken;
	}

	/**
	 * Releases the step lock.
	 */
	private function unlock() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row written above.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK ) );
		wp_cache_delete( self::LOCK, 'options' );
	}

	/**
	 * Saves the state without autoload.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Checker, created on first use.
	 *
	 * @return Checker
	 */
	private function checker() {
		if ( null === $this->checker ) {
			$this->checker = new Checker();
		}

		return $this->checker;
	}

	/**
	 * Current Unix time.
	 *
	 * @return float
	 */
	private function now() {
		return (float) call_user_func( $this->clock );
	}

	/**
	 * Current UTC time for the database.
	 *
	 * @return string
	 */
	private function mysql_now() {
		return gmdate( 'Y-m-d H:i:s', (int) $this->now() );
	}
}
