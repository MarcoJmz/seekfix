<?php
/**
 * Stores the links of posts in the plugin tables.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;
use WP_Post;

/**
 * Extracts a post's links and saves them as URLs plus instances.
 *
 * No HTTP happens here; new URLs are stored as pending for the Checker.
 */
class PostIndexer {

	/**
	 * Posts per extraction batch.
	 */
	const BATCH_SIZE = 50;

	/**
	 * Extractor.
	 *
	 * @var Extractor
	 */
	private $extractor;

	/**
	 * URL normalizer.
	 *
	 * @var UrlNormalizer
	 */
	private $normalizer;

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
	 * Creates the indexer.
	 *
	 * @param Extractor|null          $extractor  Extractor.
	 * @param UrlNormalizer|null      $normalizer URL normalizer.
	 * @param LinkRepository|null     $links      URL rows.
	 * @param InstanceRepository|null $instances  Instance rows.
	 */
	public function __construct( $extractor = null, $normalizer = null, $links = null, $instances = null ) {
		$this->extractor  = $extractor ? $extractor : new Extractor();
		$this->normalizer = $normalizer ? $normalizer : new UrlNormalizer();
		$this->links      = $links ? $links : new LinkRepository();
		$this->instances  = $instances ? $instances : new InstanceRepository();
	}

	/**
	 * Hooks that keep the tables in sync with post edits.
	 */
	public function register_hooks() {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( $this, 'on_deleted_post' ) );
	}

	/**
	 * Re-extracts one post when it is saved. Cheap: no HTTP.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->index_post( $post );
	}

	/**
	 * Removes a deleted post's instances.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_deleted_post( $post_id ) {
		$this->instances->delete_for_post( $post_id );
	}

	/**
	 * Extracts and stores the links of one post.
	 *
	 * A post outside the scanned types or statuses (a draft, a trashed post)
	 * loses its instances.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return int Number of instances stored.
	 */
	public function index_post( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return 0;
		}

		if ( ! $this->is_scannable( $post ) ) {
			$this->instances->delete_for_post( $post->ID );
			return 0;
		}

		$rows = $this->build_instances( $post );

		$this->instances->replace_for_post( $post->ID, $rows );

		return count( $rows );
	}

	/**
	 * Indexes the next batch of posts after a given ID.
	 *
	 * @param int $after_id Last post ID already processed (0 to start).
	 * @param int $limit    Posts per batch.
	 * @return int|null Last post ID processed, or null when there is nothing left.
	 */
	public function index_batch( $after_id, $limit = self::BATCH_SIZE ) {
		$ids = $this->next_post_ids( $after_id, $limit );

		if ( empty( $ids ) ) {
			return null;
		}

		foreach ( $ids as $id ) {
			$this->index_post( $id );
		}

		return (int) end( $ids );
	}

	/**
	 * Whether a post's type and status are scanned.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function is_scannable( WP_Post $post ) {
		return in_array( $post->post_type, Settings::post_types(), true )
			&& in_array( $post->post_status, Settings::post_statuses(), true );
	}

	/**
	 * Turns the extracted links of a post into instance rows.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_instances( WP_Post $post ) {
		$items = $this->extractor->extract( $post->post_content );

		/**
		 * Filters the links extracted from a post, to add or remove URLs.
		 *
		 * Each item has element, raw_href, occurrence, anchor_text and shortcode_tag.
		 *
		 * @param array   $items Extracted links.
		 * @param WP_Post $post  Post.
		 */
		$items = (array) apply_filters( 'seekfix_extract_urls', $items, $post );

		$base = $this->base_url( $post );
		$hash = sha1( $post->post_content );
		$ids  = array();
		$rows = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['raw_href'] ) || ! is_string( $item['raw_href'] ) ) {
				continue;
			}

			$url = $this->normalizer->normalize( $item['raw_href'], $base );

			/**
			 * Filters whether a URL is skipped: not stored and never checked.
			 *
			 * @param bool    $skip Whether to skip it. Default false.
			 * @param string  $url  Normalized URL.
			 * @param WP_Post $post Post where it appears.
			 */
			if ( null === $url || apply_filters( 'seekfix_skip_url', false, $url, $post ) ) {
				continue;
			}

			if ( ! isset( $ids[ $url ] ) ) {
				$ids[ $url ] = $this->links->find_or_create(
					$url,
					UrlNormalizer::hash( $url ),
					UrlNormalizer::host( $url ),
					$this->normalizer->is_internal( $url )
				);
			}

			if ( 0 === $ids[ $url ] ) {
				continue;
			}

			$rows[] = array(
				'link_id'       => $ids[ $url ],
				'element'       => isset( $item['element'] ) ? (string) $item['element'] : 'link',
				'shortcode_tag' => isset( $item['shortcode_tag'] ) ? $item['shortcode_tag'] : null,
				'anchor_text'   => isset( $item['anchor_text'] ) ? mb_substr( (string) $item['anchor_text'], 0, Extractor::MAX_ANCHOR_TEXT ) : '',
				'raw_href'      => $item['raw_href'],
				'occurrence'    => isset( $item['occurrence'] ) ? (int) $item['occurrence'] : 1,
				'content_hash'  => $hash,
			);
		}

		return $rows;
	}

	/**
	 * URL that relative links in a post resolve against.
	 *
	 * Uses the permalink; unpublished posts fall back to the home URL so that
	 * `?p=123` previews do not become the base.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private function base_url( WP_Post $post ) {
		$permalink = 'publish' === $post->post_status ? get_permalink( $post ) : false;

		return $permalink ? $permalink : trailingslashit( home_url() );
	}

	/**
	 * IDs of the next scannable posts after an ID, in ID order.
	 *
	 * @param int $after_id Last processed ID.
	 * @param int $limit    Maximum IDs.
	 * @return int[]
	 */
	private function next_post_ids( $after_id, $limit ) {
		global $wpdb;

		$types    = Settings::post_types();
		$statuses = Settings::post_statuses();

		if ( empty( $types ) ) {
			return array();
		}

		$type_in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$status_in = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders built above; values passed as one array.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch cursor over posts; caching would serve stale IDs.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ({$type_in}) AND post_status IN ({$status_in}) ORDER BY ID ASC LIMIT %d",
				array_merge( array( (int) $after_id ), $types, $statuses, array( (int) $limit ) )
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}
}
