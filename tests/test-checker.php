<?php
/**
 * Tests for Checker, InternalResolver, HostRules and RequestsClient error mapping.
 *
 * @package SeekFix
 */

use SeekFix\Checker;
use SeekFix\Data\LinkRepository;
use SeekFix\HostRules;
use SeekFix\Http\RequestsClient;
use SeekFix\Installer;
use SeekFix\InternalResolver;
use SeekFix\UrlNormalizer;

/**
 * Checker tests with a scripted HTTP client and a fake clock.
 */
class CheckerTest extends WP_UnitTestCase {

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Fake clock.
	 *
	 * @var SeekFix_Test_Clock
	 */
	private $clock;

	/**
	 * Sets up the repository and the clock, and empties the links table.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Installer::table( 'links' ) ) );

		$this->links = new LinkRepository();
		// A few seconds ahead, so URLs added during the test are already due.
		$this->clock = new SeekFix_Test_Clock( time() + 5 );
	}

	/**
	 * Adds a due URL and returns its row ID.
	 *
	 * @param string $url URL.
	 * @return int
	 */
	private function add( $url ) {
		$normalizer = new UrlNormalizer();

		return $this->links->find_or_create( $url, sha1( $url ), UrlNormalizer::host( $url ), $normalizer->is_internal( $url ) );
	}

	/**
	 * Creates a checker with a scripted client.
	 *
	 * @param SeekFix_Test_Http_Client $client Client.
	 * @param HostRules|null           $rules  Host rules.
	 * @return Checker
	 */
	private function checker( $client, $rules = null ) {
		return new Checker(
			$client,
			$this->links,
			new InternalResolver(),
			$rules ? $rules : new HostRules( array() ),
			array( $this->clock, 'now' ),
			array( $this->clock, 'sleep' )
		);
	}

	/**
	 * A 200 on HEAD is ok after one request.
	 */
	public function test_head_ok() {
		$id     = $this->add( 'https://ok.test/' );
		$client = new SeekFix_Test_Http_Client( array( 'HEAD https://ok.test/' => SeekFix_Test_Http_Client::response( 200 ) ) );

		$this->assertSame( 1, $this->checker( $client )->run_batch() );
		$this->assertSame( array( 'HEAD https://ok.test/' ), $client->sent() );

		$link = $this->links->get( $id );
		$this->assertSame( 'ok', $link->status );
		$this->assertSame( '200', $link->http_code );
		$this->assertStringContainsString( 'SeekFix/', $client->requests[0]['user_agent'] );
	}

	/**
	 * Servers that refuse HEAD are retried with GET.
	 */
	public function test_head_refused_retries_with_get() {
		$id     = $this->add( 'https://nohead.test/' );
		$client = new SeekFix_Test_Http_Client(
			array(
				'HEAD https://nohead.test/' => SeekFix_Test_Http_Client::response( 405 ),
				'GET https://nohead.test/'  => SeekFix_Test_Http_Client::response( 200 ),
			)
		);

		$this->checker( $client )->run_batch();

		$this->assertSame( array( 'HEAD https://nohead.test/', 'GET https://nohead.test/' ), $client->sent() );
		$this->assertSame( 'ok', $this->links->get( $id )->status );
	}

	/**
	 * Redirects are followed by hand and the whole chain is stored.
	 */
	public function test_redirect_chain() {
		$id     = $this->add( 'http://old.test/a' );
		$client = new SeekFix_Test_Http_Client(
			array(
				'http://old.test/a'  => SeekFix_Test_Http_Client::response( 301, array( 'location' => 'https://old.test/a' ) ),
				'https://old.test/a' => SeekFix_Test_Http_Client::response( 302, array( 'location' => '/b' ) ),
				'https://old.test/b' => SeekFix_Test_Http_Client::response( 200 ),
			)
		);

		$this->checker( $client )->run_batch();

		$link = $this->links->get( $id );
		$this->assertSame( 'redirect', $link->status );
		$this->assertSame( 'https://old.test/b', $link->final_url );
		$this->assertSame(
			array(
				array(
					'url'  => 'http://old.test/a',
					'code' => 301,
				),
				array(
					'url'  => 'https://old.test/a',
					'code' => 302,
				),
			),
			json_decode( $link->redirect_chain, true )
		);
	}

	/**
	 * More than 5 redirects stop as a redirect loop (unverifiable).
	 */
	public function test_redirect_loop_stops() {
		$id     = $this->add( 'https://loop.test/' );
		$client = new SeekFix_Test_Http_Client( array( 'https://loop.test/' => SeekFix_Test_Http_Client::response( 302, array( 'location' => 'https://loop.test/' ) ) ) );

		$this->checker( $client )->run_batch();

		$this->assertCount( Checker::MAX_REDIRECTS + 1, $client->requests );
		$this->assertSame( 'too_many_redirects', $this->links->get( $id )->error_code );
		$this->assertSame( 'unverifiable', $this->links->get( $id )->status );
	}

	/**
	 * A 404 is retried 30–60 s later with GET and a plain browser User-Agent, then broken.
	 */
	public function test_404_is_confirmed_with_get_and_another_user_agent() {
		$id     = $this->add( 'https://gone.test/x' );
		$client = new SeekFix_Test_Http_Client( array( 'https://gone.test/x' => SeekFix_Test_Http_Client::response( 404 ) ) );

		$checker = $this->checker( $client );
		$checker->run_batch();

		$this->assertSame( 'pending', $this->links->get( $id )->status );
		$this->assertSame( 0, $checker->run_batch(), 'The confirmation is not due yet.' );

		$this->clock->advance( 46 );
		$checker->run_batch();

		$last = end( $client->requests );
		$this->assertSame( 'GET', $last['method'] );
		$this->assertSame( Checker::browser_user_agent(), $last['user_agent'] );
		$this->assertSame( 'broken', $this->links->get( $id )->status );
	}

	/**
	 * An invalid certificate is retried without verification and flagged.
	 */
	public function test_invalid_certificate_is_flagged() {
		$id     = $this->add( 'https://expired.test/' );
		$client = new SeekFix_Test_Http_Client(
			array(
				'https://expired.test/' => array(
					SeekFix_Test_Http_Client::error( 'ssl' ),
					SeekFix_Test_Http_Client::response( 200 ),
				),
			)
		);

		$this->checker( $client )->run_batch();

		$this->assertTrue( $client->requests[0]['verify_ssl'] );
		$this->assertFalse( $client->requests[1]['verify_ssl'] );
		$link = $this->links->get( $id );
		$this->assertSame( 'ok', $link->status );
		$this->assertSame( '1', $link->ssl_invalid );
	}

	/**
	 * A Cloudflare challenge is unverifiable, not broken.
	 */
	public function test_cloudflare_challenge_is_unverifiable() {
		$id     = $this->add( 'https://cf.test/' );
		$client = new SeekFix_Test_Http_Client(
			array(
				'https://cf.test/' => SeekFix_Test_Http_Client::response(
					403,
					array(
						'server'       => 'cloudflare',
						'cf-mitigated' => 'challenge',
					),
					'<title>Just a moment...</title>'
				),
			)
		);

		$this->checker( $client )->run_batch();

		$this->assertSame( 'unverifiable', $this->links->get( $id )->status );
	}

	/**
	 * Host rules: excluded hosts leave the queue, "always unverifiable" ones are never requested.
	 */
	public function test_host_rules() {
		$excluded = $this->add( 'https://www.excluido.test/a' );
		$blocked  = $this->add( 'https://facebook.test/page' );
		$client   = new SeekFix_Test_Http_Client( array() );
		$rules    = new HostRules(
			array(
				HostRules::EXCLUDE      => array( 'excluido.test' ),
				HostRules::UNVERIFIABLE => array( 'facebook.test' ),
			)
		);

		$this->checker( $client, $rules )->run_batch();

		$this->assertSame( array(), $client->requests );
		$this->assertSame( '1', $this->links->get( $excluded )->ignored );
		$this->assertSame( 'unverifiable', $this->links->get( $blocked )->status );
		$this->assertSame( 'host_rule', $this->links->get( $blocked )->error_code );
		$this->assertSame( array(), $this->links->due( gmdate( 'Y-m-d H:i:s', (int) $this->clock->time ), 10 ) );
	}

	/**
	 * Internal links to existing content are ok without any request.
	 */
	public function test_internal_link_resolved_without_http() {
		$post_id = self::factory()->post->create();
		$id      = $this->add( get_permalink( $post_id ) );
		$client  = new SeekFix_Test_Http_Client( array() );

		$this->checker( $client )->run_batch();

		$this->assertSame( array(), $client->requests );
		$this->assertSame( 'ok', $this->links->get( $id )->status );
	}

	/**
	 * An unresolved internal link is requested; its 404 is broken at once.
	 */
	public function test_unresolved_internal_404_is_broken_at_once() {
		$url    = home_url( '/no-existe/' );
		$id     = $this->add( $url );
		$client = new SeekFix_Test_Http_Client( array( $url => SeekFix_Test_Http_Client::response( 404 ) ) );

		$this->checker( $client )->run_batch();

		$this->assertNotEmpty( $client->requests );
		$this->assertSame( 'broken', $this->links->get( $id )->status );
	}

	/**
	 * At most 4 requests per round, and never two to the same host within a second.
	 */
	public function test_parallel_limit_and_host_courtesy() {
		foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $host ) {
			$this->add( "https://{$host}.test/1" );
		}
		$this->add( 'https://a.test/2' );

		$client = new SeekFix_Test_Http_Client( array(), $this->clock );
		$this->assertSame( 6, $this->checker( $client )->run_batch() );

		$rounds = array_count_values( wp_list_pluck( $client->requests, 'round' ) );
		$this->assertLessThanOrEqual( Checker::PARALLEL, max( $rounds ) );

		$times_by_host = array();
		foreach ( $client->requests as $request ) {
			$times_by_host[ wp_parse_url( $request['url'], PHP_URL_HOST ) ][] = $request['round'];
		}
		$this->assertCount( 2, $times_by_host['a.test'] );
		$this->assertNotSame( $times_by_host['a.test'][0], $times_by_host['a.test'][1] );
		$this->assertNotEmpty( $this->clock->sleeps, 'The second request to a.test waited.' );
		$this->assertGreaterThanOrEqual( 0.79, array_sum( $this->clock->sleeps ) );
	}

	/**
	 * When the time budget runs out, unfinished URLs stay due for the next batch.
	 */
	public function test_budget_leaves_unfinished_urls_due() {
		$this->add( 'https://slow-a.test/' );
		$this->add( 'https://slow-b.test/' );
		$client = new SeekFix_Test_Http_Client(
			array(
				'https://slow-a.test/' => SeekFix_Test_Http_Client::response( 302, array( 'location' => 'https://slow-a.test/next' ) ),
			),
			$this->clock
		);

		// A budget smaller than one simulated round (0.2 s) allows a single round.
		$saved = $this->checker( $client )->run_batch( 25, 0.1 );

		$this->assertSame( 1, $saved, 'slow-b answered in the first round; slow-a was mid-redirect.' );
		$this->assertCount( 1, $this->links->due( gmdate( 'Y-m-d H:i:s', (int) $this->clock->time ), 10 ) );
	}

	/**
	 * Check now returns the updated row.
	 */
	public function test_check_now() {
		$id     = $this->add( 'https://ahora.test/' );
		$client = new SeekFix_Test_Http_Client( array( 'https://ahora.test/' => SeekFix_Test_Http_Client::response( 410 ) ) );

		$row = $this->checker( $client )->check_now( $id );

		$this->assertSame( '410', $row->http_code );
		$this->assertSame( '1', $row->fail_count );
		$this->assertNull( $this->checker( $client )->check_now( 999999 ) );
	}

	/*
	 * InternalResolver.
	 */

	/**
	 * Home, published posts, pages, uploads on disk and term archives resolve; the rest does not.
	 */
	public function test_internal_resolver() {
		$resolver = new InternalResolver();
		$post     = self::factory()->post->create();
		$page     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft    = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$category = self::factory()->category->create( array( 'slug' => 'noticias' ) );

		$this->set_permalink_structure( '/%postname%/' );

		// Taxonomies registered under plain permalinks have no rewrite slug; re-register them.
		create_initial_taxonomies();

		$uploads = wp_get_upload_dir();
		wp_mkdir_p( $uploads['basedir'] . '/2026/11' );
		file_put_contents( $uploads['basedir'] . '/2026/11/seekfix-test.pdf', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertTrue( $resolver->resolves( home_url( '/' ) ) );
		$this->assertTrue( $resolver->resolves( get_permalink( $post ) ) );
		$this->assertTrue( $resolver->resolves( get_permalink( $page ) ) );
		$this->assertTrue( $resolver->resolves( $uploads['baseurl'] . '/2026/11/seekfix-test.pdf' ) );
		$this->assertTrue( $resolver->resolves( get_category_link( $category ) ) );
		$this->assertTrue( $resolver->resolves( home_url( '/category/noticias/page/2/' ) ) );

		$this->assertFalse( $resolver->resolves( home_url( '/no-existe/' ) ) );
		$this->assertFalse( $resolver->resolves( $uploads['baseurl'] . '/2026/11/falta.pdf' ) );
		$this->assertFalse( $resolver->resolves( $uploads['baseurl'] . '/../wp-config.php' ) );
		$this->assertFalse( $resolver->resolves( home_url( '/category/otra/' ) ) );
		$this->assertFalse( $resolver->resolves( home_url( '/?p=' . $draft ) ) );

		wp_delete_file( $uploads['basedir'] . '/2026/11/seekfix-test.pdf' );
	}

	/*
	 * HostRules and error mapping.
	 */

	/**
	 * A rule covers the host and its subdomains, and exclusion wins.
	 */
	public function test_host_rule_matching() {
		$rules = new HostRules(
			array(
				HostRules::UNVERIFIABLE => array( 'https://www.Facebook.com/', 'linkedin.com', 'both.test' ),
				HostRules::EXCLUDE      => array( '.both.test', 'cdn.example.test:443' ),
			)
		);

		$this->assertSame( HostRules::UNVERIFIABLE, $rules->rule_for( 'www.facebook.com' ) );
		$this->assertNull( $rules->rule_for( 'facebook.com' ), 'The rule was for www.facebook.com only.' );
		$this->assertSame( HostRules::UNVERIFIABLE, $rules->rule_for( 'es.linkedin.com' ) );
		$this->assertNull( $rules->rule_for( 'notlinkedin.com' ) );
		$this->assertSame( HostRules::EXCLUDE, $rules->rule_for( 'both.test' ) );
		$this->assertSame( HostRules::EXCLUDE, $rules->rule_for( 'cdn.example.test' ) );
	}

	/**
	 * Transport messages map to error codes.
	 */
	public function test_error_codes() {
		$this->assertSame( 'dns', RequestsClient::error_code( 'cURL error 6: Could not resolve host: nope.invalid' ) );
		$this->assertSame( 'connection', RequestsClient::error_code( 'cURL error 7: Failed to connect to x port 443' ) );
		$this->assertSame( 'timeout', RequestsClient::error_code( 'cURL error 28: Operation timed out after 10001 milliseconds' ) );
		$this->assertSame( 'ssl', RequestsClient::error_code( 'cURL error 60: SSL certificate problem: certificate has expired' ) );
		$this->assertSame( 'connection', RequestsClient::error_code( 'cURL error 56: Recv failure: Connection reset by peer' ) );
	}

	/**
	 * Private and local addresses are refused before any request (SSRF protection).
	 */
	public function test_private_hosts_are_refused() {
		$responses = ( new RequestsClient() )->send(
			array(
				'local' => array(
					'url'        => 'http://127.0.0.1:9/',
					'method'     => 'HEAD',
					'user_agent' => 'x',
					'verify_ssl' => true,
				),
				'lan'   => array(
					'url'        => 'http://192.168.1.10/',
					'method'     => 'HEAD',
					'user_agent' => 'x',
					'verify_ssl' => true,
				),
			)
		);

		$this->assertSame( 'private_host', $responses['local']['error'] );
		$this->assertSame( 'private_host', $responses['lan']['error'] );
	}

	/**
	 * Public hosts on non-standard ports are allowed; private ones stay refused on any port.
	 */
	public function test_non_standard_ports() {
		$this->assertNull( RequestsClient::validation_error( 'https://93.184.215.14:3001/pdfs/ley.pdf' ) );
		$this->assertSame( 'private_host', RequestsClient::validation_error( 'http://10.0.0.5:3001/' ) );
		$this->assertSame( 'private_host', RequestsClient::validation_error( 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertSame( 'private_host', RequestsClient::validation_error( 'http://169.254.169.254:8080/latest/meta-data/' ) );
		$this->assertSame( 'private_host', RequestsClient::validation_error( 'http://100.64.0.1/' ) );
		$this->assertSame( 'private_host', RequestsClient::validation_error( 'http://100.100.100.200/latest/meta-data/' ) );
		$this->assertNull( RequestsClient::validation_error( 'http://100.128.0.1/' ), 'Just outside 100.64.0.0/10.' );
		$this->assertNull( RequestsClient::validation_error( home_url( '/pagina/' ) ), 'The site itself is always allowed.' );
		$this->assertFalse( has_filter( 'http_allowed_safe_ports' ), 'The port filter is removed after the check.' );
	}
}
