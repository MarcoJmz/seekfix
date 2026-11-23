<?php
/**
 * Tests for Scheduler.
 *
 * @package SeekFix
 */

use SeekFix\Checker;
use SeekFix\Data\LinkRepository;
use SeekFix\HostRules;
use SeekFix\Installer;
use SeekFix\InternalResolver;
use SeekFix\Plugin;
use SeekFix\ScanController;
use SeekFix\Scheduler;
use SeekFix\Settings;

/**
 * Scheduled scans and background steps with WP-Cron.
 */
class SchedulerTest extends WP_UnitTestCase {

	/**
	 * Fake clock shared by the controller and the checker.
	 *
	 * @var SeekFix_Test_Clock
	 */
	private $clock;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Empties the tables, the settings and both events.
	 */
	public function set_up() {
		parent::set_up();

		Installer::reset_data();
		delete_option( Settings::OPTION );
		Scheduler::clear();

		$this->links = new LinkRepository();
		$this->clock = new SeekFix_Test_Clock( time() + 5 );
	}

	/**
	 * Removes both events.
	 */
	public function tear_down() {
		Scheduler::clear();
		parent::tear_down();
	}

	/**
	 * Scheduler whose scans check URLs from a script.
	 *
	 * @param array $script Responses for SeekFix_Test_Http_Client.
	 * @return Scheduler
	 */
	private function scheduler( array $script = array() ) {
		$checker = new Checker(
			new SeekFix_Test_Http_Client( $script ),
			$this->links,
			new InternalResolver(),
			new HostRules( array() ),
			array( $this->clock, 'now' ),
			array( $this->clock, 'sleep' )
		);
		$scan    = new ScanController( Plugin::indexer(), $checker, $this->links, array( $this->clock, 'now' ) );

		return new Scheduler(
			static function () use ( $scan ) {
				return $scan;
			}
		);
	}

	/**
	 * Saving a schedule creates the event; changing it reschedules; off removes it.
	 */
	public function test_settings_sync_the_event() {
		$this->assertFalse( wp_next_scheduled( Scheduler::SCAN_HOOK ) );

		Settings::update( array( 'schedule' => 'daily' ) );
		$event = wp_get_scheduled_event( Scheduler::SCAN_HOOK );
		$this->assertSame( 'daily', $event->schedule );
		$this->assertSame( Scheduler::first_run(), $event->timestamp );

		// Saving other settings keeps the event's time.
		Settings::update( array( 'include_drafts' => true ) );
		$this->assertSame( $event->timestamp, wp_next_scheduled( Scheduler::SCAN_HOOK ) );

		Settings::update( array( 'schedule' => 'weekly' ) );
		$this->assertSame( 'weekly', wp_get_scheduled_event( Scheduler::SCAN_HOOK )->schedule );

		Settings::update( array( 'schedule' => 'off' ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::SCAN_HOOK ) );
		$this->assertNull( Scheduler::next_scan() );
	}

	/**
	 * The first run is the next 3:00 in the site's time zone.
	 */
	public function test_first_run_is_next_three_am_site_time() {
		update_option( 'timezone_string', 'America/Mexico_City' );
		$tz = wp_timezone();

		$before = ( new DateTimeImmutable( '2026-11-23 02:59:00', $tz ) )->getTimestamp();
		$after  = ( new DateTimeImmutable( '2026-11-23 03:00:00', $tz ) )->getTimestamp();

		$this->assertSame( ( new DateTimeImmutable( '2026-11-23 03:00:00', $tz ) )->getTimestamp(), Scheduler::first_run( $before ) );
		$this->assertSame( ( new DateTimeImmutable( '2026-11-24 03:00:00', $tz ) )->getTimestamp(), Scheduler::first_run( $after ) );
	}

	/**
	 * Deactivation removes both events.
	 */
	public function test_deactivate_clears_events() {
		Settings::update( array( 'schedule' => 'daily' ) );
		Scheduler::queue_step();

		Installer::deactivate();

		$this->assertFalse( wp_next_scheduled( Scheduler::SCAN_HOOK ) );
		$this->assertFalse( wp_next_scheduled( Scheduler::STEP_HOOK ) );
	}

	/**
	 * The scheduled event starts a scan and queues its first step.
	 */
	public function test_scheduled_event_starts_a_scan() {
		$scheduler = $this->scheduler();

		$this->assertTrue( $scheduler->run_scheduled() );
		$this->assertSame( ScanController::RUNNING, get_option( ScanController::OPTION )['status'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::STEP_HOOK ) );
	}

	/**
	 * A running or paused scan is left alone.
	 */
	public function test_scheduled_event_keeps_a_paused_scan() {
		$scheduler = $this->scheduler();
		$scheduler->run_scheduled();

		$scan = new ScanController();
		$scan->pause();
		$started = $scan->state()['started_at'];

		$this->assertFalse( $scheduler->run_scheduled() );
		$this->assertSame( ScanController::PAUSED, $scan->state()['status'] );
		$this->assertSame( $started, $scan->state()['started_at'] );
	}

	/**
	 * Resuming a scan queues a background step.
	 */
	public function test_resume_queues_a_step() {
		$scan = new ScanController();
		$scan->start();
		$scan->pause();
		Scheduler::clear();

		$scan->resume();

		$this->assertNotFalse( wp_next_scheduled( Scheduler::STEP_HOOK ) );
	}

	/**
	 * Background steps chain until the scan ends, then stop.
	 */
	public function test_steps_chain_until_the_scan_ends() {
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/gone">gone</a></p>' ) );

		$scheduler = $this->scheduler( array( 'https://example.test/gone' => SeekFix_Test_Http_Client::response( 404 ) ) );
		$scheduler->run_scheduled();

		// The 404 waits for its confirmation, so the chain goes on.
		wp_clear_scheduled_hook( Scheduler::STEP_HOOK );
		$this->assertSame( ScanController::RUNNING, $scheduler->run_step()['status'] );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::STEP_HOOK ) );

		$this->clock->advance( 46 );
		wp_clear_scheduled_hook( Scheduler::STEP_HOOK );
		$this->assertSame( ScanController::IDLE, $scheduler->run_step()['status'] );
		$this->assertFalse( wp_next_scheduled( Scheduler::STEP_HOOK ) );
		$this->assertSame( 'broken', $this->links->get_by_url( 'https://example.test/gone' )->status );
	}

	/**
	 * Only one step is queued at a time.
	 */
	public function test_queue_step_once() {
		Scheduler::queue_step();
		$first = wp_next_scheduled( Scheduler::STEP_HOOK );
		Scheduler::queue_step();

		$this->assertSame( $first, wp_next_scheduled( Scheduler::STEP_HOOK ) );
		$queued = array_filter(
			_get_cron_array(),
			static function ( $hooks ) {
				return isset( $hooks[ Scheduler::STEP_HOOK ] );
			}
		);

		$this->assertCount( 1, $queued );
	}

	/**
	 * The summary says when the next scheduled scan starts.
	 */
	public function test_summary_has_next_scan() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		if ( is_multisite() ) {
			grant_super_admin( get_current_user_id() );
		}

		Settings::update( array( 'schedule' => 'weekly' ) );

		$data = rest_do_request( new WP_REST_Request( 'GET', '/seekfix/v1/summary' ) )->get_data();

		$this->assertSame( gmdate( 'c', Scheduler::first_run() ), $data['next_scan_at'] );
	}
}
