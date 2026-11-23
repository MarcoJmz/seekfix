<?php
/**
 * Tests for Notifier.
 *
 * @package SeekFix
 */

use SeekFix\Checker;
use SeekFix\Data\LinkRepository;
use SeekFix\HostRules;
use SeekFix\Installer;
use SeekFix\InternalResolver;
use SeekFix\Notifier;
use SeekFix\Plugin;
use SeekFix\ScanController;
use SeekFix\Settings;

/**
 * The broken link e-mail: when it is sent, to whom, and that each URL goes once.
 */
class NotifierTest extends WP_UnitTestCase {

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Empties the tables, the settings and the mailer.
	 */
	public function set_up() {
		parent::set_up();

		Installer::reset_data();
		delete_option( Settings::OPTION );
		reset_phpmailer_instance();

		// wp-env's test site runs on localhost, and PHPMailer rejects wordpress@localhost.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);

		$this->links = new LinkRepository();
	}

	/**
	 * Empties the mailer.
	 */
	public function tear_down() {
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/**
	 * Creates a post linking to a URL and marks the URL as broken.
	 *
	 * @param string $url   URL.
	 * @param string $title Post title.
	 * @return int Link row ID.
	 */
	private function broken( $url, $title = 'A post' ) {
		self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => '<p><a href="' . esc_url( $url ) . '">link</a></p>',
			)
		);

		$id = (int) $this->links->get_by_url( $url )->id;
		$this->links->save_check(
			$id,
			array(
				'status'    => 'broken',
				'http_code' => 404,
			)
		);

		return $id;
	}

	/**
	 * Sent e-mails.
	 *
	 * @return array
	 */
	private function sent() {
		$sent = tests_retrieve_phpmailer_instance()->mock_sent;

		// PHPMailer sends CRLF line endings.
		foreach ( $sent as $i => $email ) {
			$sent[ $i ]['body'] = str_replace( "\r\n", "\n", $email['body'] );
		}

		return $sent;
	}

	/**
	 * Turns the e-mail on.
	 *
	 * @param string[] $recipients Addresses.
	 */
	private function enable( array $recipients = array() ) {
		Settings::update(
			array(
				'email_enabled'    => true,
				'email_recipients' => $recipients,
			)
		);
	}

	/**
	 * Nothing is sent while the e-mail is off.
	 */
	public function test_off_sends_nothing() {
		$this->broken( 'https://example.test/gone' );

		$this->assertSame( 0, ( new Notifier() )->send() );
		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * The e-mail lists the URL, why it failed and the post, and goes to the saved address.
	 */
	public function test_sends_new_broken_links() {
		$this->enable( array( 'editor@example.org' ) );
		$this->broken( 'https://example.test/gone', 'Hello world' );

		$this->assertSame( 1, ( new Notifier() )->send() );

		$sent = $this->sent();
		$this->assertCount( 1, $sent );
		$this->assertSame( 'editor@example.org', $sent[0]['to'][0][0] );
		$this->assertStringContainsString( '1 new broken link', $sent[0]['subject'] );
		$this->assertStringContainsString( 'https://example.test/gone', $sent[0]['body'] );
		$this->assertStringContainsString( 'HTTP 404', $sent[0]['body'] );
		$this->assertStringContainsString( 'In: Hello world', $sent[0]['body'] );
		$this->assertStringContainsString( 'tools.php?page=seekfix&tab=links', $sent[0]['body'] );
	}

	/**
	 * With no address saved, the e-mail goes to the admin address.
	 */
	public function test_defaults_to_admin_email() {
		add_filter(
			'pre_option_admin_email',
			static function () {
				return 'owner@example.org';
			}
		);
		$this->enable();
		$this->broken( 'https://example.test/gone' );

		( new Notifier() )->send();

		$this->assertSame( 'owner@example.org', $this->sent()[0]['to'][0][0] );
	}

	/**
	 * A URL is reported once, until it works again and breaks again.
	 */
	public function test_each_url_once_until_it_recovers() {
		$this->enable();
		$id = $this->broken( 'https://example.test/gone' );

		$notifier = new Notifier();
		$this->assertSame( 1, $notifier->send() );
		$this->assertSame( 0, $notifier->send() );
		$this->assertCount( 1, $this->sent() );

		$this->links->save_check( $id, array( 'status' => 'ok' ) );
		$this->assertNull( $this->links->get( $id )->notified_at );

		$this->links->save_check( $id, array( 'status' => 'broken' ) );
		$this->assertSame( 1, $notifier->send() );
		$this->assertCount( 2, $this->sent() );
	}

	/**
	 * A re-check that keeps the URL broken does not report it again.
	 */
	public function test_still_broken_is_not_reported_again() {
		$this->enable();
		$id = $this->broken( 'https://example.test/gone' );

		( new Notifier() )->send();
		$this->links->save_check( $id, array( 'status' => 'broken' ) );

		$this->assertSame( 0, ( new Notifier() )->send() );
	}

	/**
	 * Ignored URLs and URLs no longer in any post are not reported.
	 */
	public function test_skips_ignored_and_orphans() {
		$this->enable();
		$ignored = $this->broken( 'https://example.test/ignored' );
		$this->links->set_ignored( $ignored, true );

		$post_id = self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/orphan">x</a></p>' ) );
		$orphan  = (int) $this->links->get_by_url( 'https://example.test/orphan' )->id;
		$this->links->save_check( $orphan, array( 'status' => 'broken' ) );
		wp_delete_post( $post_id, true );

		$this->assertSame( 0, ( new Notifier() )->send() );
		$this->assertCount( 0, $this->sent() );
	}

	/**
	 * When wp_mail() fails, the URLs stay unreported for the next try.
	 */
	public function test_failed_mail_retries_later() {
		$this->enable();
		$this->broken( 'https://example.test/gone' );

		add_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 0, ( new Notifier() )->send() );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertSame( 1, ( new Notifier() )->send() );
	}

	/**
	 * Only the first URLs are listed; the rest are counted.
	 */
	public function test_long_list_is_cut() {
		$this->enable();

		for ( $i = 1; $i <= Notifier::MAX_LISTED + 2; $i++ ) {
			$this->broken( 'https://example.test/gone-' . $i );
		}

		$this->assertSame( Notifier::MAX_LISTED + 2, ( new Notifier() )->send() );

		$body = $this->sent()[0]['body'];
		$this->assertStringContainsString( 'And 2 more.', $body );
		$this->assertStringContainsString( 'gone-' . Notifier::MAX_LISTED . "\n", $body );
		$this->assertStringNotContainsString( 'gone-' . ( Notifier::MAX_LISTED + 1 ) . "\n", $body );
	}

	/**
	 * The filter can skip the e-mail, and the URLs are reported next time.
	 */
	public function test_filter_can_skip() {
		$this->enable();
		$this->broken( 'https://example.test/gone' );

		$skip = static function ( $email ) {
			$email['to'] = array();
			return $email;
		};

		add_filter( 'seekfix_notification_email', $skip );
		$this->assertSame( 0, ( new Notifier() )->send() );
		remove_filter( 'seekfix_notification_email', $skip );

		$this->assertCount( 0, $this->sent() );
		$this->assertSame( 1, ( new Notifier() )->send() );
	}

	/**
	 * A finished scan sends the e-mail once.
	 */
	public function test_finished_scan_sends() {
		$this->enable();
		self::factory()->post->create( array( 'post_content' => '<p><a href="https://example.test/gone">gone</a></p>' ) );

		$clock   = new SeekFix_Test_Clock( time() + 5 );
		$checker = new Checker(
			new SeekFix_Test_Http_Client( array( 'https://example.test/gone' => SeekFix_Test_Http_Client::response( 404 ) ) ),
			$this->links,
			new InternalResolver(),
			new HostRules( array() ),
			array( $clock, 'now' ),
			array( $clock, 'sleep' )
		);
		$scan    = new ScanController( Plugin::indexer(), $checker, $this->links, array( $clock, 'now' ) );

		$scan->start();
		$scan->step();
		$this->assertCount( 0, $this->sent(), 'Not sent before the 404 is confirmed.' );

		$clock->advance( 46 );
		$this->assertSame( 'idle', $scan->step()['status'] );
		$this->assertCount( 1, $this->sent() );

		// A step after the scan ended does not send it again.
		$scan->step();
		$this->assertCount( 1, $this->sent() );
	}
}
