<?php
/**
 * Tests for FixService, UndoService and PostWriter.
 *
 * @package SeekFix
 */

use SeekFix\Checker;
use SeekFix\Data\ActionRepository;
use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;
use SeekFix\FixService;
use SeekFix\HostRules;
use SeekFix\InternalResolver;
use SeekFix\UndoService;

/**
 * Fixes go through wp_update_post() and change only the link's bytes.
 */
class FixServiceTest extends WP_UnitTestCase {

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
	 * Fix history.
	 *
	 * @var ActionRepository
	 */
	private $actions;

	/**
	 * Logs in an administrator (super admin on multisite, for unfiltered_html).
	 */
	public function set_up() {
		parent::set_up();

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}

		wp_set_current_user( $this->admin );

		$this->links     = new LinkRepository();
		$this->instances = new InstanceRepository();
		$this->actions   = new ActionRepository();
	}

	/**
	 * Reads a fixture file byte for byte.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string
	 */
	private function fixture( $name ) {
		return file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Creates a published post with exact content.
	 *
	 * @param string $content Content.
	 * @param array  $args    Extra post fields.
	 * @return int
	 */
	private function post( $content, array $args = array() ) {
		return self::factory()->post->create( array_merge( array( 'post_content' => wp_slash( $content ) ), $args ) );
	}

	/**
	 * Current content of a post, from the database.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function content( $post_id ) {
		clean_post_cache( $post_id );

		return get_post( $post_id )->post_content;
	}

	/**
	 * Instance of a URL in a post, by element and occurrence.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $raw_href   Raw value.
	 * @param int    $occurrence Occurrence.
	 * @return object
	 */
	private function instance( $post_id, $raw_href, $occurrence = 1 ) {
		foreach ( $this->instances->for_post( $post_id ) as $instance ) {
			if ( $instance->raw_href === $raw_href && (int) $instance->occurrence === $occurrence ) {
				return $instance;
			}
		}

		$this->fail( "No instance of {$raw_href} #{$occurrence} in post {$post_id}." );
	}

	/**
	 * Replaces the Nth occurrence of a substring; fails if it does not exist.
	 *
	 * @param string $haystack    Original content.
	 * @param string $needle      Exact bytes to replace.
	 * @param string $replacement Replacement bytes.
	 * @param int    $nth         1-based occurrence.
	 * @return string
	 */
	private function replace_nth( $haystack, $needle, $replacement, $nth = 1 ) {
		$offset = -1;

		for ( $i = 0; $i < $nth; $i++ ) {
			$offset = strpos( $haystack, $needle, $offset + 1 );
			$this->assertNotFalse( $offset, "Content does not contain occurrence {$nth} of: {$needle}" );
		}

		return substr_replace( $haystack, $replacement, $offset, strlen( $needle ) );
	}

	/**
	 * FixService whose check before a fix answers from a script.
	 *
	 * Unscripted URLs answer 404, so they are still broken.
	 *
	 * @param array $working URLs that answer 200.
	 * @return FixService
	 */
	private function service( array $working = array() ) {
		$client = new SeekFix_Test_Fallback_Client( $working );
		$clock  = new SeekFix_Test_Clock( time() + 5 );

		$checker = new Checker( $client, $this->links, new InternalResolver(), new HostRules( array() ), array( $clock, 'now' ), array( $clock, 'sleep' ) );

		return new FixService( $checker );
	}

	/**
	 * Unlink changes only the link tags, logs the fix and re-indexes the post.
	 */
	public function test_unlink_changes_only_the_link() {
		$original = $this->fixture( 'nested.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/precios', 2 );

		$result = $this->service()->fix_instance( (int) $instance->id, FixService::UNLINK );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$expected = $this->replace_nth( $original, '<a href="https://example.test/precios">otra vez precios</a>', 'otra vez precios' );
		$this->assertSame( $expected, $this->content( $post_id ) );

		$action = $this->actions->get( $result['action_id'] );
		$this->assertSame( 'unlink', $action->action );
		$this->assertSame( 'https://example.test/precios', $action->old_url );
		$this->assertSame( (string) $this->admin, $action->user_id );
		$this->assertSame( sha1( $expected ), $action->content_hash );
		$this->assertSame( $original, get_post( (int) $action->revision_id )->post_content );
		$this->assertNull( $action->old_content );

		// save_post re-extracted the post: the unlinked copy is gone, the others remain.
		$hrefs = wp_list_pluck( $this->instances->for_post( $post_id ), 'raw_href' );
		$this->assertSame( 2, count( array_keys( $hrefs, 'https://example.test/precios', true ) ) );
	}

	/**
	 * Undo restores the content byte for byte, once.
	 */
	public function test_undo_restores_original() {
		$original = $this->fixture( 'nested.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/buscar?a=1&b=2' );
		$result   = $this->service()->fix_instance( (int) $instance->id, FixService::REPLACE_URL, array( 'new_url' => 'https://example.test/search?a=1&b=2' ) );

		$this->assertIsArray( $result );
		$this->assertStringContainsString( 'href="https://example.test/search?a=1&#038;b=2"', $this->content( $post_id ) );

		$undo = new UndoService();
		$this->assertSame(
			array(
				'action_id' => $result['action_id'],
				'post_id'   => $post_id,
			),
			$undo->undo( $result['action_id'] )
		);
		$this->assertSame( $original, $this->content( $post_id ) );
		$this->assertNotNull( $this->actions->get( $result['action_id'] )->undone_at );

		$again = $undo->undo( $result['action_id'] );
		$this->assertWPError( $again );
		$this->assertSame( 'seekfix_already_undone', $again->get_error_code() );
	}

	/**
	 * A removal is refused when the URL works again, unless forced.
	 */
	public function test_link_that_works_again() {
		$original = $this->fixture( 'classic.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/viejo', 2 );
		$service  = $this->service( array( 'https://example.test/viejo' ) );

		$result = $service->fix_instance( (int) $instance->id, FixService::UNLINK );

		$this->assertWPError( $result );
		$this->assertSame( 'seekfix_link_works', $result->get_error_code() );
		$this->assertSame( $original, $this->content( $post_id ) );
		$this->assertSame( 'ok', $this->links->get( (int) $instance->link_id )->status );

		$forced = $service->fix_instance( (int) $instance->id, FixService::UNLINK, array( 'force' => true ) );

		$this->assertIsArray( $forced );
		$this->assertSame( $this->replace_nth( $original, '<a href="https://example.test/viejo">otro igual</a>', 'otro igual' ), $this->content( $post_id ) );
	}

	/**
	 * Buttons are removed as blocks; unlink is not offered for them.
	 */
	public function test_buttons() {
		$original = $this->fixture( 'buttons.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/roto' );
		$service  = $this->service();

		$this->assertSame( 'button', $instance->element );

		$refused = $service->fix_instance( (int) $instance->id, FixService::UNLINK );
		$this->assertSame( 'seekfix_action_not_allowed', $refused->get_error_code() );

		$this->assertIsArray( $service->fix_instance( (int) $instance->id, FixService::REMOVE_BUTTON ) );

		$content = $this->content( $post_id );
		$this->assertStringNotContainsString( 'example.test/roto', $content );
		$this->assertStringContainsString( 'example.test/ok', $content );
		// Saving adds nothing: the stored content is exactly what BlockEditor produced.
		$this->assertSame( ( new SeekFix\BlockEditor() )->remove_button( $original, 'https://example.test/roto', 1 ), $content );
	}

	/**
	 * Image sources are replaced; the action log keeps the new URL.
	 */
	public function test_replace_image_src() {
		$original = $this->fixture( 'classic.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/foto.jpg' );

		$this->assertSame( 'image', $instance->element );

		$result = $this->service()->fix_instance( (int) $instance->id, FixService::REPLACE_URL, array( 'new_url' => 'https://example.test/foto-nueva.jpg' ) );

		$this->assertIsArray( $result );
		$this->assertSame( $this->replace_nth( $original, 'src="https://example.test/foto.jpg"', 'src="https://example.test/foto-nueva.jpg"' ), $this->content( $post_id ) );
		$this->assertSame( 'https://example.test/foto-nueva.jpg', $this->actions->get( $result['action_id'] )->new_url );
	}

	/**
	 * Invalid input is refused before anything is touched.
	 */
	public function test_validation() {
		$post_id  = $this->post( $this->fixture( 'classic.html' ) );
		$instance = $this->instance( $post_id, 'https://example.test/viejo' );
		$service  = $this->service();

		$this->assertSame( 'seekfix_invalid_action', $service->fix_instance( (int) $instance->id, 'delete' )->get_error_code() );
		$this->assertSame( 'seekfix_invalid_url', $service->fix_instance( (int) $instance->id, FixService::REPLACE_URL, array( 'new_url' => 'javascript:alert(1)' ) )->get_error_code() );
		$this->assertSame( 'seekfix_no_final_url', $service->fix_instance( (int) $instance->id, FixService::USE_FINAL_URL )->get_error_code() );
		$this->assertSame( 'seekfix_instance_not_found', $service->fix_instance( 999999, FixService::UNLINK )->get_error_code() );

		$same_id = $this->post( '<p><a href="https://example.test/same">x</a></p>' );
		$same    = $this->instance( $same_id, 'https://example.test/same' );
		$this->assertSame( 'seekfix_no_change', $service->fix_instance( (int) $same->id, FixService::REPLACE_URL, array( 'new_url' => 'https://example.test/same' ) )->get_error_code() );

		$unclosed = $this->instance( $post_id, 'https://example.test/abierto' );
		$error    = $service->fix_instance( (int) $unclosed->id, FixService::UNLINK );
		$this->assertSame( 'seekfix_unclosed_link', $error->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $error->get_error_data() );
	}

	/**
	 * A redirect is updated to its final URL.
	 */
	public function test_use_final_url() {
		$post_id  = $this->post( '<p><a href="http://example.test/old">Old</a></p>' );
		$instance = $this->instance( $post_id, 'http://example.test/old' );

		$this->links->save_check(
			(int) $instance->link_id,
			array(
				'status'    => 'redirect',
				'final_url' => 'https://example.test/new',
			)
		);

		$this->assertIsArray( $this->service()->fix_instance( (int) $instance->id, FixService::USE_FINAL_URL ) );
		$this->assertSame( '<p><a href="https://example.test/new">Old</a></p>', $this->content( $post_id ) );
	}

	/**
	 * A post edited after the scan is extracted again before the fix.
	 */
	public function test_post_changed_since_scan() {
		global $wpdb;

		$post_id = $this->post( '<p><a href="https://example.test/a">A</a> <a href="https://example.test/b">B</a></p>' );
		$target  = $this->instance( $post_id, 'https://example.test/b' );
		$gone    = $this->instance( $post_id, 'https://example.test/a' );
		$edited  = '<p>New intro.</p><p><a href="https://example.test/b">B</a></p>';
		$service = $this->service();

		// Change the content without save_post, as another plugin writing to the table would.
		$wpdb->update( $wpdb->posts, array( 'post_content' => $edited ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		clean_post_cache( $post_id );

		$this->assertIsArray( $service->fix_instance( (int) $target->id, FixService::UNLINK ) );
		$this->assertSame( '<p>New intro.</p><p>B</p>', $this->content( $post_id ) );

		$error = $service->fix_instance( (int) $gone->id, FixService::UNLINK );
		$this->assertSame( 'seekfix_instance_not_found', $error->get_error_code() );
	}

	/**
	 * A link removed from the post since the scan is reported, not guessed.
	 */
	public function test_link_no_longer_in_content() {
		global $wpdb;

		$post_id  = $this->post( '<p><a href="https://example.test/a">A</a></p>' );
		$instance = $this->instance( $post_id, 'https://example.test/a' );

		$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>A</p>' ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		clean_post_cache( $post_id );

		$error = $this->service()->fix_instance( (int) $instance->id, FixService::UNLINK );

		$this->assertSame( 'seekfix_link_not_found', $error->get_error_code() );
		$this->assertSame( '<p>A</p>', $this->content( $post_id ) );
	}

	/**
	 * Users who cannot edit the post, and locked posts, are refused.
	 */
	public function test_permissions_and_lock() {
		$post_id  = $this->post( '<p><a href="https://example.test/a">A</a></p>' );
		$instance = $this->instance( $post_id, 'https://example.test/a' );
		$service  = $this->service();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 'seekfix_forbidden', $service->fix_instance( (int) $instance->id, FixService::UNLINK )->get_error_code() );

		$editor = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Ana',
			)
		);
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $editor );
		wp_set_current_user( $this->admin );

		$locked = $service->fix_instance( (int) $instance->id, FixService::UNLINK );
		$this->assertSame( 'seekfix_post_locked', $locked->get_error_code() );
		$this->assertStringContainsString( 'Ana', $locked->get_error_message() );
		$this->assertSame( '<p><a href="https://example.test/a">A</a></p>', $this->content( $post_id ) );
	}

	/**
	 * A bulk fix writes each post once and skips posts the user cannot edit.
	 */
	public function test_fix_link_bulk() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$url    = 'https://example.test/roto';
		$mine   = $this->post( "<p><a href=\"{$url}\">uno</a> y <a href=\"{$url}\">dos</a></p>", array( 'post_author' => $author ) );
		$theirs = $this->post( "<p><a href=\"{$url}\">tres</a></p>", array( 'post_author' => $other ) );
		$button = $this->post( $this->fixture( 'buttons.html' ), array( 'post_author' => $author ) );
		$link   = $this->links->get_by_url( $url );

		wp_set_current_user( $author );
		$service = $this->service();

		$this->assertSame(
			array(
				'posts'     => 1,
				'instances' => 2,
			),
			array_intersect_key( $service->plan_link( (int) $link->id, FixService::UNLINK ), array_flip( array( 'posts', 'instances' ) ) )
		);

		$refused = $service->fix_link( (int) $link->id, FixService::UNLINK, array( 'expected' => 1 ) );
		$this->assertSame( 'seekfix_count_changed', $refused->get_error_code() );

		$summary = $service->fix_link( (int) $link->id, FixService::UNLINK, array( 'expected' => 2 ) );

		$this->assertFalse( $summary['works_again'] );
		$this->assertCount( 1, $summary['fixed'] );
		$this->assertSame( $mine, $summary['fixed'][0]['post_id'] );
		$this->assertSame( 2, $summary['fixed'][0]['instances'] );
		$this->assertSame( '<p>uno y dos</p>', $this->content( $mine ) );
		$this->assertSame( "<p><a href=\"{$url}\">tres</a></p>", $this->content( $theirs ) );

		$skipped = wp_list_pluck( $summary['skipped'], 'code', 'post_id' );
		$this->assertSame( 'seekfix_forbidden', $skipped[ $theirs ] );
		$this->assertSame( 'seekfix_action_not_allowed', $skipped[ $button ] );
		$this->assertSame( $this->fixture( 'buttons.html' ), $this->content( $button ) );
	}

	/**
	 * A bulk fix on a URL that works again changes nothing and says so.
	 */
	public function test_fix_link_works_again() {
		$url     = 'https://example.test/vuelve';
		$post_id = $this->post( "<p><a href=\"{$url}\">x</a></p>" );
		$link    = $this->links->get_by_url( $url );

		$summary = $this->service( array( $url ) )->fix_link( (int) $link->id, FixService::UNLINK );

		$this->assertTrue( $summary['works_again'] );
		$this->assertSame( array(), $summary['fixed'] );
		$this->assertSame( "<p><a href=\"{$url}\">x</a></p>", $this->content( $post_id ) );
	}

	/**
	 * A core/file block holds its URL three times; one replace covers all of them.
	 */
	public function test_fix_link_core_file() {
		$original = $this->fixture( 'file.html' );
		$post_id  = $this->post( $original );
		$old      = 'https://example.test/wp-content/uploads/guia.pdf';
		$link     = $this->links->get_by_url( $old );

		$summary = $this->service()->fix_link( (int) $link->id, FixService::REPLACE_URL, array( 'new_url' => 'https://example.test/guia-2026.pdf' ) );

		$this->assertCount( 1, $summary['fixed'] );
		$this->assertSame( 2, $summary['fixed'][0]['instances'] );
		$this->assertSame( str_replace( $old, 'https://example.test/guia-2026.pdf', $original ), $this->content( $post_id ) );
		$this->assertSame( array(), $summary['skipped'] );
	}

	/**
	 * Undo is refused after a later edit and points to the revisions screen.
	 */
	public function test_undo_after_later_edit() {
		$post_id  = $this->post( '<p><a href="https://example.test/a">A</a></p>' );
		$instance = $this->instance( $post_id, 'https://example.test/a' );
		$result   = $this->service()->fix_instance( (int) $instance->id, FixService::UNLINK );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>A, edited</p>',
			)
		);

		$error = ( new UndoService() )->undo( $result['action_id'] );

		$this->assertSame( 'seekfix_post_changed', $error->get_error_code() );
		$this->assertStringContainsString( 'revision.php?revision=', $error->get_error_data()['revisions_url'] );
		$this->assertSame( '<p>A, edited</p>', $this->content( $post_id ) );
	}

	/**
	 * Without revisions, the old content is kept in the action row.
	 */
	public function test_undo_without_revisions() {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );

		$original = $this->fixture( 'nested.html' );
		$post_id  = $this->post( $original );
		$instance = $this->instance( $post_id, 'https://example.test/precios-2025' );
		$result   = $this->service()->fix_instance( (int) $instance->id, FixService::UNLINK );
		$action   = $this->actions->get( $result['action_id'] );

		$this->assertNull( $action->revision_id );
		$this->assertSame( $original, $action->old_content );

		$this->assertIsArray( ( new UndoService() )->undo( $result['action_id'] ) );
		$this->assertSame( $original, $this->content( $post_id ) );
	}

	/**
	 * Without unfiltered_html, a fix that kses would spread beyond the link is refused.
	 */
	public function test_kses_would_change_content() {
		$original = '<p><a href="https://example.test/a">A</a></p><script>track();</script>';
		$post_id  = $this->post( $original );
		$clean_id = $this->post( '<p><a href="https://example.test/a">A</a></p>' );
		$instance = $this->instance( $post_id, 'https://example.test/a' );
		$clean    = $this->instance( $clean_id, 'https://example.test/a' );
		$service  = $this->service();

		$deny = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		kses_init();

		$error = $service->fix_instance( (int) $instance->id, FixService::UNLINK );
		$this->assertSame( 'seekfix_content_filtered', $error->get_error_code() );
		$this->assertSame( $original, $this->content( $post_id ) );

		$this->assertIsArray( $service->fix_instance( (int) $clean->id, FixService::UNLINK ) );
		$this->assertSame( '<p>A</p>', $this->content( $clean_id ) );

		remove_filter( 'map_meta_cap', $deny, 10 );
		kses_init();
	}
}
