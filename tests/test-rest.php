<?php
/**
 * Tests for the REST API, ReportQuery, Settings and FixService::fix_instances().
 *
 * @package SeekFix
 */

use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;
use SeekFix\FixService;
use SeekFix\Rest\Controller;

/**
 * REST routes under seekfix/v1, called with rest_do_request().
 */
class RestTest extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Instance rows.
	 *
	 * @var InstanceRepository
	 */
	private $instances;

	/**
	 * Logs in an administrator and registers the routes.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}

		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init', rest_get_server() );

		$this->links     = new LinkRepository();
		$this->instances = new InstanceRepository();
	}

	/**
	 * Sends a request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path under seekfix/v1.
	 * @param array  $params Query or body parameters.
	 * @return WP_REST_Response
	 */
	private function request( $method, $path, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/seekfix/v1' . $path );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Creates a post and gives its URLs a status.
	 *
	 * @param string $content  Content.
	 * @param array  $statuses Status by URL.
	 * @return int Post ID.
	 */
	private function post( $content, array $statuses = array() ) {
		$post_id = self::factory()->post->create( array( 'post_content' => wp_slash( $content ) ) );

		foreach ( $statuses as $url => $status ) {
			$this->links->save_check(
				(int) $this->links->get_by_url( $url )->id,
				array(
					'status'          => $status,
					'last_checked_at' => current_time( 'mysql', true ),
					'next_check_at'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				)
			);
		}

		return $post_id;
	}

	/**
	 * Every route needs manage_options (undo: edit_posts).
	 */
	public function test_permissions() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 403, $this->request( 'GET', '/summary' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/instances' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/scan', array( 'action' => 'start' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/reset' )->get_status() );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/actions/1/undo' )->get_status() );
	}

	/**
	 * The list shows broken links by default; pending ones never; ignored ones only on request.
	 */
	public function test_instances_filters() {
		$post_id = $this->post(
			'<p><a href="https://example.test/a">A</a> <a href="https://example.test/b">B</a> <img src="https://example.test/c.png" alt="C"> <a href="https://example.test/p">P</a> <a href="https://example.test/i">I</a></p>',
			array(
				'https://example.test/a'     => 'broken',
				'https://example.test/b'     => 'ok',
				'https://example.test/c.png' => 'broken',
				'https://example.test/i'     => 'broken',
			)
		);
		$this->links->set_ignored( (int) $this->links->get_by_url( 'https://example.test/i' )->id, true );

		$response = $this->request( 'GET', '/instances' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( array( 'https://example.test/a', 'https://example.test/c.png' ), wp_list_pluck( $data, 'url' ) );
		$this->assertSame( array( 'unlink', 'replace_url' ), $data[0]['actions'] );
		$this->assertSame( array( 'replace_url' ), $data[1]['actions'] );
		$this->assertSame( $post_id, $data[0]['post_id'] );
		$this->assertStringContainsString( 'post.php?post=' . $post_id, $data[0]['edit_url'] );

		$all = $this->request( 'GET', '/instances', array( 'status' => array( 'broken', 'ok', 'redirect', 'unverifiable' ) ) )->get_data();
		$this->assertNotContains( 'https://example.test/p', wp_list_pluck( $all, 'url' ), 'Pending URLs are never listed.' );
		$this->assertCount( 3, $all );

		$ignored = $this->request( 'GET', '/instances', array( 'status' => array( 'ignored' ) ) )->get_data();
		$this->assertSame( array( 'https://example.test/i' ), wp_list_pluck( $ignored, 'url' ) );

		$images = $this->request( 'GET', '/instances', array( 'element' => array( 'image' ) ) )->get_data();
		$this->assertSame( array( 'https://example.test/c.png' ), wp_list_pluck( $images, 'url' ) );

		$search = $this->request( 'GET', '/instances', array( 'search' => 'c.png' ) )->get_data();
		$this->assertCount( 1, $search );

		$sorted = $this->request(
			'GET',
			'/instances',
			array(
				'orderby' => 'url',
				'order'   => 'desc',
			)
		)->get_data();
		$this->assertSame( 'https://example.test/c.png', $sorted[0]['url'] );
	}

	/**
	 * Grouped by URL: one row per URL with post and link counts.
	 */
	public function test_group_by_url() {
		$this->post( '<p><a href="https://example.test/x">1</a> <a href="https://example.test/x">2</a></p>', array( 'https://example.test/x' => 'broken' ) );
		$this->post( '<p><a href="https://example.test/x">3</a></p>' );

		$response = $this->request( 'GET', '/instances', array( 'group_by_url' => true ) );
		$rows     = $response->get_data();

		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( 2, $rows[0]['posts'] );
		$this->assertSame( 3, $rows[0]['instances'] );
		$this->assertSame( array( 'link' ), $rows[0]['elements'] );
	}

	/**
	 * Fix one instance, then undo it, through the API.
	 */
	public function test_fix_and_undo() {
		$content = '<p><a href="https://example.test/a">A</a></p>';
		$post_id = $this->post( $content, array( 'https://example.test/a' => 'broken' ) );
		$item    = $this->request( 'GET', '/instances' )->get_data()[0];

		$fix = $this->request(
			'POST',
			'/instances/' . $item['id'] . '/fix',
			array(
				'action'  => 'replace_url',
				'new_url' => 'https://example.test/b',
			)
		);

		$this->assertSame( 200, $fix->get_status() );
		$this->assertSame( '<p><a href="https://example.test/b">A</a></p>', get_post( $post_id )->post_content );

		$undo = $this->request( 'POST', '/actions/' . $fix->get_data()['action_id'] . '/undo' );

		$this->assertSame( 200, $undo->get_status() );
		clean_post_cache( $post_id );
		$this->assertSame( $content, get_post( $post_id )->post_content );

		$again = $this->request( 'POST', '/actions/' . $fix->get_data()['action_id'] . '/undo' );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'seekfix_already_undone', $again->get_data()['code'] );
	}

	/**
	 * The history lists fixes newest first and tells which can still be undone.
	 */
	public function test_history() {
		$first  = $this->post( '<p><a href="https://example.test/h1">A</a></p>', array( 'https://example.test/h1' => 'broken' ) );
		$second = $this->post( '<p><a href="https://example.test/h2">B</a></p>', array( 'https://example.test/h2' => 'broken' ) );
		$ids    = array();

		foreach ( $this->request( 'GET', '/instances' )->get_data() as $item ) {
			$ids[ $item['post_id'] ] = $this->request( 'POST', '/instances/' . $item['id'] . '/fix', array( 'action' => 'unlink' ) )->get_data()['action_id'];
		}

		$this->assertSame( 200, $this->request( 'POST', '/actions/' . $ids[ $first ] . '/undo' )->get_status() );

		wp_update_post(
			array(
				'ID'           => $second,
				'post_content' => '<p>Edited by hand</p>',
			)
		);

		$response = $this->request( 'GET', '/actions', array( 'per_page' => 1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
		$this->assertCount( 1, $response->get_data() );

		$states = array();

		foreach ( $this->request( 'GET', '/actions' )->get_data() as $row ) {
			$states[ $row['post_id'] ] = $row['state'];
			$this->assertSame( 'unlink', $row['action'] );
			$this->assertNotEmpty( $row['created_at'] );
		}

		$this->assertSame(
			array(
				$second => 'edited',
				$first  => 'undone',
			),
			$states,
			'Newest first.'
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->request( 'GET', '/actions' )->get_status() );
	}

	/**
	 * Errors keep their HTTP status and code.
	 */
	public function test_fix_errors() {
		$this->post( '<p><a href="https://example.test/a">A</a></p>', array( 'https://example.test/a' => 'broken' ) );
		$item = $this->request( 'GET', '/instances' )->get_data()[0];

		$bad = $this->request( 'POST', '/instances/' . $item['id'] . '/fix', array( 'action' => 'delete' ) );
		$this->assertSame( 400, $bad->get_status() );

		$count = $this->request(
			'POST',
			'/links/' . $item['link_id'] . '/fix',
			array(
				'action'   => 'replace_url',
				'new_url'  => 'https://example.test/b',
				'expected' => 0,
			)
		);
		$this->assertSame( 409, $count->get_status() );
		$this->assertSame( 'seekfix_count_changed', $count->get_data()['code'] );

		$plan = $this->request( 'GET', '/links/' . $item['link_id'] . '/plan', array( 'action' => 'replace_url' ) )->get_data();
		$this->assertSame( 1, $plan['instances'] );
		$this->assertSame( 1, $plan['posts'] );
	}

	/**
	 * A bulk fix over several URLs of one post writes once per URL and undoes in reverse.
	 */
	public function test_fix_instances_in_one_post() {
		$content = '<p><a href="https://example.test/a">A</a> <a href="https://example.test/b">B</a> <a href="https://example.test/a">A2</a></p>';
		$post_id = $this->post(
			$content,
			array(
				'https://example.test/a' => 'broken',
				'https://example.test/b' => 'broken',
			)
		);
		$ids     = wp_list_pluck( $this->instances->for_post( $post_id ), 'id' );

		$response = $this->request(
			'POST',
			'/instances/fix',
			array(
				'ids'    => array_map( 'intval', $ids ),
				'action' => 'unlink',
				'force'  => true,
			)
		);
		$summary  = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $summary['fixed'] );
		$this->assertSame( array(), $summary['skipped'] );
		$this->assertSame( '<p>A B A2</p>', get_post( $post_id )->post_content );

		foreach ( array_reverse( wp_list_pluck( $summary['fixed'], 'action_id' ) ) as $action_id ) {
			$this->assertSame( 200, $this->request( 'POST', '/actions/' . $action_id . '/undo' )->get_status() );
		}

		clean_post_cache( $post_id );
		$this->assertSame( $content, get_post( $post_id )->post_content );
	}

	/**
	 * A selection with buttons and links skips what the action does not apply to.
	 */
	public function test_fix_instances_skips_other_elements() {
		$post_id = $this->post(
			'<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="https://example.test/a">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons --><p><a href="https://example.test/a">A</a></p>'
		);
		$ids     = array_map( 'intval', wp_list_pluck( $this->instances->for_post( $post_id ), 'id' ) );

		$summary = ( new FixService() )->fix_instances( $ids, FixService::UNLINK, array( 'force' => true ) );

		$this->assertCount( 1, $summary['fixed'] );
		$this->assertSame( 'seekfix_action_not_allowed', $summary['skipped'][0]['code'] );
		$this->assertStringContainsString( '<p>A</p>', get_post( $post_id )->post_content );
		$this->assertStringContainsString( 'href="https://example.test/a">Go', get_post( $post_id )->post_content );

		$replace = ( new FixService() )->fix_instances( $ids, FixService::REPLACE_URL );
		$this->assertSame( 'seekfix_invalid_action', $replace->get_error_code() );
	}

	/**
	 * The export is raw CSV and neutralizes formulas.
	 */
	public function test_export() {
		$this->post( '<p><a href="https://example.test/a">=SUM(1)</a></p>', array( 'https://example.test/a' => 'broken' ) );

		$response = $this->request( 'GET', '/export' );

		$this->assertSame( 'text/csv; charset=utf-8', $response->get_headers()['Content-Type'] );
		$this->assertStringContainsString( "'=SUM(1)", $response->get_data() );
		$this->assertStringStartsWith( 'URL,Status', $response->get_data() );

		$this->assertSame( "'+1", Controller::csv_cell( '+1' ) );
		$this->assertSame( "'@x", Controller::csv_cell( '@x' ) );
		$this->assertSame( 'plain', Controller::csv_cell( 'plain' ) );
		$this->assertSame( '', Controller::csv_cell( '' ) );
	}

	/**
	 * Settings are sanitized: unknown types, bad e-mails and hosts are dropped.
	 */
	public function test_settings() {
		$saved = $this->request(
			'POST',
			'/settings',
			array(
				'post_types'       => array( 'post', 'nope' ),
				'include_drafts'   => true,
				'schedule'         => 'hourly',
				'email_recipients' => array( 'a@example.org', 'not-an-email' ),
				'host_rules'       => array(
					'unverifiable' => array( 'https://www.Facebook.com/x', '' ),
					'exclude'      => array( 'example.net' ),
				),
				'unknown'          => 'x',
			)
		)->get_data();

		$this->assertSame( array( 'post' ), $saved['post_types'] );
		$this->assertTrue( $saved['include_drafts'] );
		$this->assertSame( 'off', $saved['schedule'] );
		$this->assertSame( array( 'a@example.org' ), $saved['email_recipients'] );
		$this->assertSame( array( 'www.facebook.com' ), $saved['host_rules']['unverifiable'] );
		$this->assertArrayNotHasKey( 'unknown', get_option( 'seekfix_settings' ) );
		$this->assertSame( $saved, $this->request( 'GET', '/settings' )->get_data() );
	}

	/**
	 * Reset empties results and history, keeps settings and posts.
	 */
	public function test_reset() {
		update_option( 'seekfix_settings', array( 'include_drafts' => true ), false );
		$post_id = $this->post( '<p><a href="https://example.test/a">A</a></p>', array( 'https://example.test/a' => 'broken' ) );

		$this->assertSame( 200, $this->request( 'POST', '/reset' )->get_status() );

		$this->assertSame( array(), $this->instances->for_post( $post_id ) );
		$this->assertNull( $this->links->get_by_url( 'https://example.test/a' ) );
		$this->assertSame( array( 'include_drafts' => true ), get_option( 'seekfix_settings' ) );
		$this->assertStringContainsString( 'example.test/a', get_post( $post_id )->post_content );
	}

	/**
	 * The summary counts only URLs that appear in posts.
	 */
	public function test_summary() {
		$this->post( '<p><a href="https://example.test/a">A</a> <a href="https://example.test/b">B</a></p>', array( 'https://example.test/a' => 'broken' ) );
		$this->links->find_or_create( 'https://example.test/orphan', sha1( 'https://example.test/orphan' ), 'example.test', false );

		$data = $this->request( 'GET', '/summary' )->get_data();

		$this->assertSame( 1, $data['counts']['broken'] );
		$this->assertSame( 1, $data['counts']['pending'] );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 'idle', $data['scan']['status'] );
	}
}
