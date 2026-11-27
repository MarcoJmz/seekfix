<?php
/**
 * Tests for PostIndexer, the repositories and the Installer.
 *
 * @package SeekFix
 */

use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;
use SeekFix\Installer;
use SeekFix\Plugin;

/**
 * Indexing posts into the plugin tables.
 */
class PostIndexerTest extends WP_UnitTestCase {

	/**
	 * Instance rows.
	 *
	 * @var InstanceRepository
	 */
	private $instances;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Sets up the repositories.
	 */
	public function set_up() {
		parent::set_up();
		$this->instances = new InstanceRepository();
		$this->links     = new LinkRepository();
	}

	/**
	 * Raw hrefs stored for a post, in order.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private function stored_hrefs( $post_id ) {
		return wp_list_pluck( $this->instances->for_post( $post_id ), 'raw_href' );
	}

	/**
	 * The three tables exist and the schema version is stored without autoload.
	 */
	public function test_installer_creates_tables() {
		global $wpdb;

		foreach ( array( 'links', 'instances', 'actions' ) as $name ) {
			$table = Installer::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}

		$this->assertSame( Installer::DB_VERSION, get_option( Installer::DB_VERSION_OPTION ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Installer::DB_VERSION_OPTION ) );
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	/**
	 * The test site meets the requirements.
	 */
	public function test_requirements_met() {
		$this->assertSame( array(), Installer::requirement_problems() );
	}

	/**
	 * Saving a published post stores its links; relative URLs resolve; skipped ones are left out.
	 */
	public function test_save_post_indexes_links() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="https://example.test/a">A</a> <a href="/interna">B</a> <a href="mailto:x@example.test">C</a> <a href="#top">D</a></p>',
			)
		);

		$rows = $this->instances->for_post( $post_id );

		$this->assertCount( 2, $rows );
		$this->assertSame( array( 'https://example.test/a', '/interna' ), wp_list_pluck( $rows, 'raw_href' ) );

		$external = $this->links->get( $rows[0]->link_id );
		$internal = $this->links->get( $rows[1]->link_id );

		$this->assertSame( 'pending', $external->status );
		$this->assertSame( '0', $external->is_internal );
		$this->assertSame( trailingslashit( home_url() ) . 'interna', $internal->url );
		$this->assertSame( '1', $internal->is_internal );
		$this->assertSame( sha1( get_post( $post_id )->post_content ), $rows[0]->content_hash );
	}

	/**
	 * A link in a shortcode stores the shortcode's tag; any other element stores NULL.
	 */
	public function test_shortcode_tag_is_null_outside_shortcodes() {
		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p><a href="https://example.test/a">A</a></p>[et_pb_button button_url="https://example.test/precios" button_text="Ver precios"][/et_pb_button]',
			)
		);

		$rows = $this->instances->for_post( $post_id );

		$this->assertSame( array( 'link', 'shortcode' ), wp_list_pluck( $rows, 'element' ) );
		$this->assertNull( $rows[0]->shortcode_tag );
		$this->assertSame( 'et_pb_button', $rows[1]->shortcode_tag );
	}

	/**
	 * One URL row per unique URL, shared by posts; its status survives re-indexing.
	 */
	public function test_links_are_shared_and_status_is_kept() {
		global $wpdb;

		$first  = self::factory()->post->create( array( 'post_content' => '<a href="https://example.test/compartida">x</a>' ) );
		$second = self::factory()->post->create( array( 'post_content' => '<a href="https://EXAMPLE.test/compartida#a">y</a>' ) );

		$link_id = (int) $this->instances->for_post( $first )[0]->link_id;
		$this->assertSame( $link_id, (int) $this->instances->for_post( $second )[0]->link_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Installer::table( 'links' ), array( 'status' => 'broken' ), array( 'id' => $link_id ) );

		Plugin::indexer()->index_post( $first );

		$this->assertSame( 'broken', $this->links->get( $link_id )->status );
	}

	/**
	 * Editing a post replaces its instances; trashing and deleting remove them.
	 */
	public function test_instances_follow_post_lifecycle() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="https://example.test/viejo">v</a>' ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<a href="https://example.test/nuevo">n</a><a href="https://example.test/nuevo">n</a>',
			)
		);

		$rows = $this->instances->for_post( $post_id );
		$this->assertSame( array( 'https://example.test/nuevo', 'https://example.test/nuevo' ), wp_list_pluck( $rows, 'raw_href' ) );
		$this->assertSame( array( '1', '2' ), wp_list_pluck( $rows, 'occurrence' ) );

		wp_trash_post( $post_id );
		$this->assertSame( array(), $this->stored_hrefs( $post_id ) );

		wp_untrash_post( $post_id );
		wp_publish_post( $post_id );
		$this->assertCount( 2, $this->stored_hrefs( $post_id ) );

		wp_delete_post( $post_id, true );
		$this->assertSame( array(), $this->stored_hrefs( $post_id ) );
	}

	/**
	 * Drafts, revisions and non-public post types are not indexed.
	 */
	public function test_skips_drafts_and_non_public_types() {
		$draft = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_content' => '<a href="https://example.test/borrador">b</a>',
			)
		);
		$this->assertSame( array(), $this->stored_hrefs( $draft ) );

		register_post_type( 'seekfix_private', array( 'public' => false ) );
		$hidden = self::factory()->post->create(
			array(
				'post_type'    => 'seekfix_private',
				'post_content' => '<a href="https://example.test/oculto">o</a>',
			)
		);
		$this->assertSame( array(), $this->stored_hrefs( $hidden ) );

		update_option( 'seekfix_settings', array( 'include_drafts' => true ), false );
		Plugin::indexer()->index_post( $draft );
		$this->assertSame( array( 'https://example.test/borrador' ), $this->stored_hrefs( $draft ) );
	}

	/**
	 * Batches walk posts in ID order and report where to resume.
	 */
	public function test_index_batch_resumes_by_id() {
		$ids = self::factory()->post->create_many( 3, array( 'post_content' => '<a href="https://example.test/lote">l</a>' ) );

		// Start from scratch for these posts.
		foreach ( $ids as $id ) {
			$this->instances->delete_for_post( $id );
		}

		$indexer = Plugin::indexer();
		$last    = $indexer->index_batch( $ids[0] - 1, 2 );

		$this->assertSame( $ids[1], $last );
		$this->assertCount( 1, $this->stored_hrefs( $ids[0] ) );
		$this->assertSame( array(), $this->stored_hrefs( $ids[2] ) );

		$this->assertSame( $ids[2], $indexer->index_batch( $last, 2 ) );
		$this->assertCount( 1, $this->stored_hrefs( $ids[2] ) );
		$this->assertNull( $indexer->index_batch( $ids[2], 2 ) );
	}

	/**
	 * The seekfix_skip_url filter keeps URLs out of the tables.
	 */
	public function test_skip_url_filter() {
		$skip = static function ( $skip, $url ) {
			return $skip || false !== strpos( $url, 'excluido' );
		};
		add_filter( 'seekfix_skip_url', $skip, 10, 2 );

		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="https://excluido.test/">x</a><a href="https://example.test/">y</a>' ) );

		remove_filter( 'seekfix_skip_url', $skip, 10 );

		$this->assertSame( array( 'https://example.test/' ), $this->stored_hrefs( $post_id ) );
	}

	/**
	 * URLs with no instances left are removed by the cleanup.
	 */
	public function test_delete_orphans() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<a href="https://example.test/huerfana">h</a>' ) );
		$link_id = (int) $this->instances->for_post( $post_id )[0]->link_id;

		wp_delete_post( $post_id, true );
		$this->links->delete_orphans();

		$this->assertNull( $this->links->get( $link_id ) );
	}
}
