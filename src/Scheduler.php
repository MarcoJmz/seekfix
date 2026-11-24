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
 * Three cron hooks:
 * - SCAN_HOOK, recurring (daily or weekly), starts a scan when none is
 *   running or paused.
 * - STEP_HOOK, a single event, runs one step and queues the next one while
 *   the scan runs. The admin screen keeps asking for steps while it is open;
 *   the step lock keeps both from overlapping.
 * - CLEANUP_HOOK, daily, deletes fix history older than the retention
 *   period in the settings. Not scheduled when the history is kept forever.
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
	 * Daily event that deletes old fix history.
	 */
	const CLEANUP_HOOK = 'seekfix_cleanup_history';

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
		add_action( self::CLEANUP_HOOK, array( $this, 'run_cleanup' ) );

		// Sites that had the plugin active before the cleanup existed.
		add_action( 'admin_init', array( self::class, 'sync_cleanup' ) );
	}

	/**
	 * CLEANUP_HOOK: deletes fixes older than the retention period.
	 *
	 * Only SeekFix's records go; the posts' revisions stay in WordPress.
	 *
	 * @return int Rows deleted.
	 */
	public function run_cleanup() {
		$days = Settings::history_days();

		if ( $days <= 0 ) {
			return 0;
		}

		return ( new Data\ActionRepository() )->delete_older_than( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
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
	 * Makes the recurring events match the settings.
	 *
	 * Runs when the settings are saved and on activation. An event that
	 * already has the right recurrence keeps its time.
	 */
	public static function sync() {
		self::sync_cleanup();

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
	 * Schedules the daily cleanup while the history has a retention period,
	 * and removes it when the history is kept forever.
	 */
	public static function sync_cleanup() {
		$scheduled = (bool) wp_next_scheduled( self::CLEANUP_HOOK );

		if ( Settings::history_days() > 0 ) {
			if ( ! $scheduled ) {
				wp_schedule_event( self::first_run(), 'daily', self::CLEANUP_HOOK );
			}
		} elseif ( $scheduled ) {
			wp_clear_scheduled_hook( self::CLEANUP_HOOK );
		}
	}

	/**
	 * Removes every event. Runs on deactivation.
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::SCAN_HOOK );
		wp_clear_scheduled_hook( self::STEP_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
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
