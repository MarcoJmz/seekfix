<?php
/**
 * Tests for ScanController.
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

/**
 * A full scan in steps: extraction, checking, confirmations, controls and lock.
 */
class ScanControllerTest extends WP_UnitTestCase {

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
	 * Empties the tables and the scan state.
	 */
	public function set_up() {
		parent::set_up();

		Installer::reset_data();

		$this->links = new LinkRepository();
		$this->clock = new SeekFix_Test_Clock( time() + 5 );
	}

	/**
	 * Controller whose checker answers from a script.
	 *
	 * @param array $script Responses for SeekFix_Test_Http_Client.
	 * @return ScanController
	 */
	private function controller( array $script ) {
		$checker = new Checker(
			new SeekFix_Test_Http_Client( $script ),
			$this->links,
			new InternalResolver(),
			new HostRules( array() ),
			array( $this->clock, 'now' ),
			array( $this->clock, 'sleep' )
		);

		return new ScanController( Plugin::indexer(), $checker, $this->links, array( $this->clock, 'now' ) );
	}

	/**
	 * A scan extracts every post, checks every URL and waits for the 404 confirmation.
	 */
	public function test_full_scan() {
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/ok">ok</a></p>' ) );
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/gone">gone</a></p>' ) );

		$scan = $this->controller(
			array(
				'https://example.test/ok'   => SeekFix_Test_Http_Client::response( 200 ),
				'https://example.test/gone' => SeekFix_Test_Http_Client::response( 404 ),
			)
		);

		$progress = $scan->start();
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 'extract', $progress['phase'] );

		$progress = $scan->step();

		// Both URLs were checked once; the 404 waits 45 s for its confirmation.
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 'check', $progress['phase'] );
		$this->assertSame( $progress['posts_total'], $progress['posts_done'] );
		$this->assertSame( 2, $progress['urls_total'] );
		$this->assertSame( 2, $progress['urls_checked'] );
		$this->assertSame( 'pending', $this->links->get_by_url( 'https://example.test/gone' )->status );

		$this->clock->advance( 46 );
		$progress = $scan->step();

		$this->assertSame( 'idle', $progress['status'] );
		$this->assertNotNull( $progress['last_finished_at'] );
		$this->assertSame( 'broken', $this->links->get_by_url( 'https://example.test/gone' )->status );
		$this->assertSame( 'ok', $this->links->get_by_url( 'https://example.test/ok' )->status );
	}

	/**
	 * A new scan checks known URLs again without resetting their status.
	 */
	public function test_rescan_keeps_status() {
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/gone">gone</a></p>' ) );
		$link_id = (int) $this->links->get_by_url( 'https://example.test/gone' )->id;

		$this->links->save_check(
			$link_id,
			array(
				'status'        => 'broken',
				'next_check_at' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
			)
		);

		$scan = $this->controller( array( 'https://example.test/gone' => SeekFix_Test_Http_Client::response( 503 ) ) );
		$scan->start();
		$scan->step();

		$link = $this->links->get( $link_id );
		$this->assertSame( 'broken', $link->status, 'A 5xx never clears a broken link.' );
		$this->assertNotNull( $link->last_checked_at );
	}

	/**
	 * Pause stops the steps; resume and cancel change the status only.
	 */
	public function test_controls() {
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/a">a</a></p>' ) );
		$scan = $this->controller( array() );

		$scan->start();
		$this->assertSame( 'paused', $scan->pause()['status'] );

		$progress = $scan->step();
		$this->assertSame( 'extract', $progress['phase'], 'A paused scan does no work.' );

		$this->assertSame( 'running', $scan->resume()['status'] );
		$this->assertSame( 'idle', $scan->cancel()['status'] );
		$this->assertNull( $scan->progress()['last_finished_at'] );
	}

	/**
	 * A step does nothing while another one holds the lock.
	 */
	public function test_lock() {
		global $wpdb;

		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/a">a</a></p>' ) );
		$scan = $this->controller( array() );
		$scan->start();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => ScanController::LOCK,
				'option_value' => (string) ( time() + 60 ),
				'autoload'     => 'no',
			)
		);

		$this->assertSame( 'extract', $scan->step()['phase'] );

		// An expired lock is taken over.
		$this->clock->advance( 120 );
		$this->assertSame( 'check', $scan->step()['phase'] );
	}
}
