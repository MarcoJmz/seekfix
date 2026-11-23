<?php
/**
 * Scheduled scans and background scan steps with WP-Cron.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Starts a full scan on the schedule from the settings, and runs the steps
 * of any running scan in the background.
 *
 * Two cron hooks:
 * - SCAN_HOOK, recurring (daily or weekly), starts a scan when none is
 *   running or paused.
 * - STEP_HOOK, a single event, runs one step and queues the next one while
 *   the scan runs. The admin screen keeps asking for steps while it is open;
 *   the step lock keeps both from overlapping.
 */
class Scheduler {

	/**
	 * Recurring event that starts a scheduled scan.
	 */
	const SCAN_HOOK = 'seekfix_scheduled_scan';

	/**
	 * Single event that runs one scan step.
	 */
	const STEP_HOOK = 'seekfix_scan_step';

	/**
	 * Seconds between background steps. WP-Cron runs at most about once a
	 * minute anyway, and only when the site gets visits.
	 */
	const STEP_DELAY = 30;

	/**
	 * Time budget of a background step, in seconds.
	 */
	const STEP_BUDGET = 20;

	/**
	 * Site-time hour when scheduled scans start.
	 */
	const HOUR = 3;

	/**
	 * Creates the scan controller, so tests can replace it.
	 *
	 * @var callable
	 */
	private $controller_factory;

	/**
	 * Creates the scheduler.
	 *
	 * @param callable|null $controller_factory Returns a ScanController.
	 */
	public function __construct( $controller_factory = null ) {
		$this->controller_factory = $controller_factory ? $controller_factory : static function () {
			return new ScanController();
		};
	}

	/**
	 * Registers the cron callbacks.
	 */
	public function register_hooks() {
		add_action( self::SCAN_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::STEP_HOOK, array( $this, 'run_step' ) );
	}

	/**
	 * SCAN_HOOK: starts a scan unless one is running or paused.
	 *
	 * A paused scan stays paused: the user paused it on purpose.
	 *
	 * @return bool Whether a scan was started.
	 */
	public function run_scheduled() {
		$scan = $this->controller();

		if ( ScanController::IDLE !== $scan->state()['status'] ) {
			return false;
		}

		$scan->start();

		return true;
	}

	/**
	 * STEP_HOOK: runs one step and queues the next while the scan runs.
	 *
	 * @return array<string, mixed> Progress.
	 */
	public function run_step() {
		$progress = $this->controller()->step( self::STEP_BUDGET );

		if ( ScanController::RUNNING === $progress['status'] ) {
			self::queue_step();
		}

		return $progress;
	}

	/**
	 * Queues the next background step, unless one is already queued.
	 */
	public static function queue_step() {
		if ( ! wp_next_scheduled( self::STEP_HOOK ) ) {
			wp_schedule_single_event( time() + self::STEP_DELAY, self::STEP_HOOK );
		}
	}

	/**
	 * Makes the recurring event match the schedule in the settings.
	 *
	 * Runs when the settings are saved and on activation. An event that
	 * already has the right recurrence keeps its time.
	 */
	public static function sync() {
		$schedule = Settings::for_editing()['schedule'];
		$event    = wp_get_scheduled_event( self::SCAN_HOOK );

		if ( 'off' === $schedule ) {
			wp_clear_scheduled_hook( self::SCAN_HOOK );
			return;
		}

		if ( $event && $event->schedule === $schedule ) {
			return;
		}

		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_schedule_event( self::first_run(), $schedule, self::SCAN_HOOK );
	}

	/**
	 * Removes both events. Runs on deactivation.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_clear_scheduled_hook( self::STEP_HOOK );
	}

	/**
	 * When the next scheduled scan starts.
	 *
	 * @return int|null Unix time, or null when scans are not scheduled.
	 */
	public static function next_scan() {
		$next = wp_next_scheduled( self::SCAN_HOOK );

		return $next ? (int) $next : null;
	}

	/**
	 * Next HOUR:00 in the site's time zone.
	 *
	 * @param int|null $now Unix time; defaults to now.
	 * @return int Unix time.
	 */
	public static function first_run( $now = null ) {
		$now = ( new \DateTimeImmutable( '@' . ( null === $now ? time() : (int) $now ) ) )->setTimezone( wp_timezone() );
		$run = $now->setTime( self::HOUR, 0 );

		if ( $run <= $now ) {
			$run = $run->modify( '+1 day' );
		}

		return $run->getTimestamp();
	}

	/**
	 * A new scan controller.
	 *
	 * @return ScanController
	 */
	private function controller() {
		return call_user_func( $this->controller_factory );
	}
}
