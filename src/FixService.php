<?php
/**
 * Fixes broken links in post content.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\ActionRepository;
use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;
use WP_Error;
use WP_Post;

/**
 * The one path that writes fixes into post content.
 *
 * Each fix checks permissions and the post lock, checks the URL again,
 * makes sure the post did not change since the scan, keeps a revision,
 * changes only the link's bytes with BlockEditor, saves with
 * wp_update_post() and logs the fix in seekfix_actions. If any step fails,
 * the post is not touched.
 */
class FixService {

	/**
	 * Actions.
	 */
	const UNLINK        = 'unlink';
	const REMOVE_BUTTON = 'remove_button';
	const REPLACE_URL   = 'replace_url';
	const USE_FINAL_URL = 'use_final_url';

	/**
	 * Actions each element supports. Shortcodes are fixed in the editor only.
	 */
	const ELEMENT_ACTIONS = array(
		'link'      => array( self::UNLINK, self::REPLACE_URL, self::USE_FINAL_URL ),
		'button'    => array( self::REMOVE_BUTTON, self::REPLACE_URL, self::USE_FINAL_URL ),
		'image'     => array( self::REPLACE_URL, self::USE_FINAL_URL ),
		'shortcode' => array(),
	);

	/**
	 * Content editor.
	 *
	 * @var BlockEditor
	 */
	private $editor;

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
	 * Checker, created on first use.
	 *
	 * @var Checker|null
	 */
	private $checker;

	/**
	 * Post indexer.
	 *
	 * @var PostIndexer
	 */
	private $indexer;

	/**
	 * Permission checks and saving.
	 *
	 * @var PostWriter
	 */
	private $writer;

	/**
	 * Creates the service. Every dependency can be replaced in tests.
	 *
	 * @param Checker|null            $checker   Checker for the check before a fix.
	 * @param LinkRepository|null     $links     URL rows.
	 * @param InstanceRepository|null $instances Instance rows.
	 * @param ActionRepository|null   $actions   Fix history.
	 * @param PostIndexer|null        $indexer   Post indexer.
	 * @param BlockEditor|null        $editor    Content editor.
	 * @param PostWriter|null         $writer    Permission checks and saving.
	 */
	public function __construct( $checker = null, $links = null, $instances = null, $actions = null, $indexer = null, $editor = null, $writer = null ) {
		$this->checker   = $checker;
		$this->links     = $links ? $links : new LinkRepository();
		$this->instances = $instances ? $instances : new InstanceRepository();
		$this->actions   = $actions ? $actions : new ActionRepository();
		$this->indexer   = $indexer ? $indexer : Plugin::indexer();
		$this->editor    = $editor ? $editor : new BlockEditor();
		$this->writer    = $writer ? $writer : new PostWriter();
	}

	/**
	 * Fixes one instance of a link.
	 *
	 * @param int                  $instance_id Row ID in seekfix_instances.
	 * @param string               $action      One of the action constants.
	 * @param array<string, mixed> $args        `new_url` for replace_url; `force` (bool) to fix
	 *                                          a link that works again.
	 * @return array<string, mixed>|WP_Error `action_id`, `post_id` and `instances` (bytes
	 *                                       changed for this many links), or an error.
	 */
	public function fix_instance( $instance_id, $action, array $args = array() ) {
		$instance = $this->instances->get( $instance_id );
		$link     = $instance ? $this->links->get( (int) $instance->link_id ) : null;

		if ( ! $instance || ! $link ) {
			return new WP_Error( 'seekfix_instance_not_found', __( 'This link is no longer in the SeekFix list. Scan the post again.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$new_url = $this->validate( $action, $link, $args );

		if ( is_wp_error( $new_url ) ) {
			return $new_url;
		}

		if ( ! in_array( $action, self::ELEMENT_ACTIONS[ $this->element( $instance ) ], true ) ) {
			return $this->not_allowed( $instance );
		}

		$post_id = (int) $instance->post_id;
		$error   = $this->writer->permission_error( $post_id );
		$error   = $error ? $error : $this->writer->lock_error( $post_id );

		if ( $error ) {
			return $error;
		}

		if ( empty( $args['force'] ) && $this->works_again( $link, $action ) ) {
			return new WP_Error(
				'seekfix_link_works',
				__( 'This link works again. Do you want to fix it anyway?', 'seekfix' ),
				array( 'status' => 409 )
			);
		}

		return $this->fix_post( $post_id, $link, $action, $new_url, array( $instance ), false );
	}

	/**
	 * Fixes every instance of a URL, with one write and one revision per post.
	 *
	 * Instances whose element does not support the action, and posts the user
	 * cannot edit, are skipped and reported. Pass `expected` (from plan_link())
	 * to refuse the fix when more instances would change than the user
	 * confirmed.
	 *
	 * @param int                  $link_id Row ID in seekfix_links.
	 * @param string               $action  One of the action constants.
	 * @param array<string, mixed> $args    `new_url`, `force` and `expected` (int).
	 * @return array<string, mixed>|WP_Error Summary: `fixed` (post_id, action_id,
	 *                                       instances), `skipped` (post_id, code, message) and
	 *                                       `works_again` (bool, nothing fixed when true).
	 */
	public function fix_link( $link_id, $action, array $args = array() ) {
		$link = $this->links->get( $link_id );

		if ( ! $link ) {
			return new WP_Error( 'seekfix_link_not_in_list', __( 'This URL is no longer in the SeekFix list.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$new_url = $this->validate( $action, $link, $args );

		if ( is_wp_error( $new_url ) ) {
			return $new_url;
		}

		$plan = $this->plan( $link, $action );

		if ( isset( $args['expected'] ) && $plan['instances'] > (int) $args['expected'] ) {
			return new WP_Error(
				'seekfix_count_changed',
				__( 'More links would change than you confirmed, because posts changed in the meantime. Review the count and try again.', 'seekfix' ),
				array(
					'status'    => 409,
					'instances' => $plan['instances'],
				)
			);
		}

		$summary = array(
			'fixed'       => array(),
			'skipped'     => $plan['skipped'],
			'works_again' => false,
		);

		if ( empty( $plan['posts'] ) ) {
			return $summary;
		}

		if ( empty( $args['force'] ) && $this->works_again( $link, $action ) ) {
			$summary['works_again'] = true;
			return $summary;
		}

		foreach ( $plan['posts'] as $post_id => $instances ) {
			$result = $this->fix_post( $post_id, $link, $action, $new_url, $instances, true );

			if ( is_wp_error( $result ) ) {
				$summary['skipped'][] = $this->skip_entry( $post_id, $result );
			} else {
				$summary['fixed'][] = $result;
			}
		}

		return $summary;
	}

	/**
	 * Fixes a selection of instances, which may hold several URLs.
	 *
	 * All instances are read before the first write: saving a post indexes
	 * it again and replaces its instance IDs. Each post gets one write per URL
	 * (one revision and one history row each, so they undo in reverse order);
	 * after the first write, the next URL is found again by raw value and
	 * occurrence, which editing another URL does not move.
	 *
	 * @param int[]                $instance_ids Row IDs in seekfix_instances.
	 * @param string               $action       Removal or use_final_url; replace_url
	 *                                           takes one URL at a time.
	 * @param array<string, mixed> $args         `force` (bool).
	 * @return array<string, mixed>|WP_Error Summary: `fixed`, `skipped` and `works_again`
	 *                                       (link IDs left untouched because they work).
	 */
	public function fix_instances( array $instance_ids, $action, array $args = array() ) {
		if ( self::REPLACE_URL === $action ) {
			return new WP_Error( 'seekfix_invalid_action', __( 'Replace one URL at a time.', 'seekfix' ), array( 'status' => 400 ) );
		}

		$summary = array(
			'fixed'       => array(),
			'skipped'     => array(),
			'works_again' => array(),
		);
		$groups  = array();
		$links   = array();

		foreach ( array_unique( array_map( 'intval', $instance_ids ) ) as $id ) {
			$instance = $this->instances->get( $id );

			if ( ! $instance ) {
				$summary['skipped'][] = $this->skip_entry( 0, new WP_Error( 'seekfix_instance_not_found', __( 'This link is no longer in the SeekFix list. Scan the post again.', 'seekfix' ) ), $id );
				continue;
			}

			if ( ! in_array( $action, self::ELEMENT_ACTIONS[ $this->element( $instance ) ], true ) ) {
				$summary['skipped'][] = $this->skip_entry( (int) $instance->post_id, $this->not_allowed( $instance ), $id );
				continue;
			}

			$link_id = (int) $instance->link_id;

			if ( ! isset( $links[ $link_id ] ) ) {
				$links[ $link_id ] = $this->links->get( $link_id );
			}

			$groups[ (int) $instance->post_id ][ $link_id ][] = $instance;
		}

		$targets = array();

		foreach ( $links as $link_id => $link ) {
			$new_url = $link ? $this->validate( $action, $link, $args ) : new WP_Error( 'seekfix_link_not_in_list', __( 'This URL is no longer in the SeekFix list.', 'seekfix' ) );

			if ( ! is_wp_error( $new_url ) && empty( $args['force'] ) && $this->works_again( $link, $action ) ) {
				$summary['works_again'][] = $link_id;
				$new_url                  = null;
			}

			$targets[ $link_id ] = $new_url;
		}

		foreach ( $groups as $post_id => $by_link ) {
			foreach ( $by_link as $link_id => $instances ) {
				$new_url = $targets[ $link_id ];

				if ( null === $new_url ) {
					continue;
				}

				if ( is_wp_error( $new_url ) ) {
					$summary['skipped'][] = $this->skip_entry( $post_id, $new_url );
					continue;
				}

				// A final URL equal to another selected URL would shift that URL's occurrences.
				$collides = '' !== $new_url && array_filter(
					$by_link,
					static function ( $other ) use ( $new_url ) {
						return $other[0]->raw_href === $new_url;
					}
				);

				$result = $collides
					? new WP_Error( 'seekfix_fix_separately', __( 'Two of the selected links in this post end at the same URL. Fix them one at a time.', 'seekfix' ) )
					: $this->fix_post( $post_id, $links[ $link_id ], $action, $new_url, $instances, false );

				if ( is_wp_error( $result ) ) {
					$summary['skipped'][] = $this->skip_entry( $post_id, $result );
				} else {
					$summary['fixed'][] = $result;
				}
			}
		}

		return $summary;
	}

	/**
	 * Counts what fix_link() would change, for the confirmation dialog.
	 *
	 * @param int    $link_id Row ID in seekfix_links.
	 * @param string $action  One of the action constants.
	 * @return array{posts:int,instances:int,skipped:array}|WP_Error
	 */
	public function plan_link( $link_id, $action ) {
		$link = $this->links->get( $link_id );

		if ( ! $link ) {
			return new WP_Error( 'seekfix_link_not_in_list', __( 'This URL is no longer in the SeekFix list.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$plan = $this->plan( $link, $action );

		return array(
			'posts'     => count( $plan['posts'] ),
			'instances' => $plan['instances'],
			'skipped'   => $plan['skipped'],
		);
	}

	/**
	 * Groups a URL's instances by the posts the user can fix.
	 *
	 * @param object $link   Row of seekfix_links.
	 * @param string $action Action.
	 * @return array{posts:array<int, object[]>,instances:int,skipped:array}
	 */
	private function plan( $link, $action ) {
		$posts   = array();
		$skipped = array();
		$count   = 0;
		$denied  = array();

		foreach ( $this->instances->for_link( (int) $link->id ) as $instance ) {
			$post_id = (int) $instance->post_id;

			if ( ! in_array( $action, self::ELEMENT_ACTIONS[ $this->element( $instance ) ], true ) ) {
				$skipped[] = $this->skip_entry( $post_id, $this->not_allowed( $instance ), (int) $instance->id );
				continue;
			}

			if ( isset( $denied[ $post_id ] ) ) {
				continue;
			}

			$error = $this->writer->permission_error( $post_id );

			if ( $error ) {
				$denied[ $post_id ] = true;
				$skipped[]          = $this->skip_entry( $post_id, $error );
				continue;
			}

			$posts[ $post_id ][] = $instance;
			++$count;
		}

		return array(
			'posts'     => $posts,
			'instances' => $count,
			'skipped'   => $skipped,
		);
	}

	/**
	 * Applies an action to some instances of one URL in one post, and saves.
	 *
	 * @param int      $post_id   Post ID.
	 * @param object   $link      Row of seekfix_links.
	 * @param string   $action    Action.
	 * @param string   $new_url   New URL for replace actions, else ''.
	 * @param object[] $instances Instances of the link in this post.
	 * @param bool     $all       Whether every instance of the link in the post is meant
	 *                            (bulk fix) rather than the given ones.
	 * @return array<string, mixed>|WP_Error
	 */
	private function fix_post( $post_id, $link, $action, $new_url, array $instances, $all ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seekfix_post_not_found', __( 'The post no longer exists.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$error = $this->writer->permission_error( $post_id );
		$error = $error ? $error : $this->writer->lock_error( $post_id );

		if ( $error ) {
			return $error;
		}

		$content = $post->post_content;

		if ( sha1( $content ) !== $instances[0]->content_hash ) {
			$instances = $this->relocate( $post, $link, $action, $instances, $all );

			if ( is_wp_error( $instances ) ) {
				return $instances;
			}
		}

		$new_content = $this->apply_all( $content, $action, $new_url, $instances );

		if ( is_wp_error( $new_content ) ) {
			return $new_content;
		}

		if ( $new_content === $content ) {
			return new WP_Error( 'seekfix_no_change', __( 'The link already has that URL. Nothing was changed.', 'seekfix' ), array( 'status' => 400 ) );
		}

		$snapshot = $this->writer->snapshot( $post );
		$saved    = $this->writer->write( $post, $new_content );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$action_id = $this->actions->insert(
			array(
				'post_id'      => $post_id,
				'link_id'      => (int) $link->id,
				'action'       => $action,
				'old_url'      => (string) $link->url,
				'new_url'      => '' === $new_url ? null : $new_url,
				'revision_id'  => $snapshot['revision_id'],
				'old_content'  => $snapshot['old_content'],
				'content_hash' => sha1( $saved ),
				'user_id'      => get_current_user_id(),
			)
		);

		return array(
			'action_id' => $action_id,
			'post_id'   => $post_id,
			'instances' => count( $instances ),
		);
	}

	/**
	 * Finds the instances again after the post changed since the scan.
	 *
	 * The post is extracted again. A single fix keeps its target only if the
	 * same raw value is still at the same occurrence; a bulk fix takes every
	 * instance of the URL, but never more than were planned, so it cannot
	 * change more links than the user confirmed.
	 *
	 * @param WP_Post  $post      Post.
	 * @param object   $link      Row of seekfix_links.
	 * @param string   $action    Action.
	 * @param object[] $instances Instances found by the scan.
	 * @param bool     $all       Bulk fix.
	 * @return object[]|WP_Error
	 */
	private function relocate( WP_Post $post, $link, $action, array $instances, $all ) {
		$this->indexer->index_post( $post );

		$fresh = array();

		foreach ( $this->instances->for_post( $post->ID ) as $candidate ) {
			if ( (int) $candidate->link_id !== (int) $link->id || ! in_array( $action, self::ELEMENT_ACTIONS[ $this->element( $candidate ) ], true ) ) {
				continue;
			}

			foreach ( $instances as $instance ) {
				if ( $all || ( $candidate->raw_href === $instance->raw_href && (int) $candidate->occurrence === (int) $instance->occurrence && $candidate->element === $instance->element ) ) {
					$fresh[] = $candidate;
					break;
				}
			}
		}

		if ( empty( $fresh ) ) {
			return new WP_Error( 'seekfix_link_not_found', __( 'The link is no longer in the content.', 'seekfix' ), array( 'status' => 409 ) );
		}

		if ( count( $fresh ) > count( $instances ) ) {
			return new WP_Error(
				'seekfix_post_changed',
				__( 'The post changed since the scan and now has more copies of this link. Check it again before fixing.', 'seekfix' ),
				array( 'status' => 409 )
			);
		}

		return $fresh;
	}

	/**
	 * Applies the action to each instance, last occurrence first.
	 *
	 * Editing a later occurrence never moves an earlier one, so going
	 * backwards keeps every occurrence number valid. An earlier occurrence can
	 * only vanish when an edit already covered it (a core/file block holds the
	 * same URL twice and both copies change together); that is not an error.
	 *
	 * @param string   $content   Post content.
	 * @param string   $action    Action.
	 * @param string   $new_url   New URL for replace actions.
	 * @param object[] $instances Instances to fix.
	 * @return string|WP_Error
	 */
	private function apply_all( $content, $action, $new_url, array $instances ) {
		usort(
			$instances,
			static function ( $a, $b ) {
				return (int) $b->occurrence - (int) $a->occurrence;
			}
		);

		$edited = 0;

		foreach ( $instances as $instance ) {
			$result = $this->apply( $content, $action, $new_url, $instance );

			if ( is_wp_error( $result ) ) {
				if ( 'seekfix_link_not_found' === $result->get_error_code() && $edited > 0 ) {
					continue;
				}

				$result->add_data( array( 'status' => 409 ) );

				return $result;
			}

			$content = $result;
			++$edited;
		}

		return $content;
	}

	/**
	 * Applies the action to one instance.
	 *
	 * @param string $content  Post content.
	 * @param string $action   Action.
	 * @param string $new_url  New URL for replace actions.
	 * @param object $instance Instance.
	 * @return string|WP_Error
	 */
	private function apply( $content, $action, $new_url, $instance ) {
		$raw        = (string) $instance->raw_href;
		$occurrence = (int) $instance->occurrence;

		switch ( $action ) {
			case self::UNLINK:
				return $this->editor->unlink( $content, $raw, $occurrence );

			case self::REMOVE_BUTTON:
				return $this->editor->remove_button( $content, $raw, $occurrence );

			default:
				return $this->editor->replace_url( $content, $raw, $occurrence, $new_url, 'image' === $instance->element ? 'img' : 'a' );
		}
	}

	/**
	 * Checks the action and returns the URL to write ('' for removals).
	 *
	 * @param string               $action Action.
	 * @param object               $link   Row of seekfix_links.
	 * @param array<string, mixed> $args   Arguments.
	 * @return string|WP_Error
	 */
	private function validate( $action, $link, array $args ) {
		switch ( $action ) {
			case self::UNLINK:
			case self::REMOVE_BUTTON:
				return '';

			case self::REPLACE_URL:
				$new_url = isset( $args['new_url'] ) && is_string( $args['new_url'] ) ? esc_url_raw( trim( $args['new_url'] ) ) : '';

				if ( '' === $new_url ) {
					return new WP_Error( 'seekfix_invalid_url', __( 'The new URL is not valid.', 'seekfix' ), array( 'status' => 400 ) );
				}

				return $new_url;

			case self::USE_FINAL_URL:
				if ( 'redirect' !== $link->status || empty( $link->final_url ) ) {
					return new WP_Error( 'seekfix_no_final_url', __( 'This URL does not redirect, so there is no final URL to use.', 'seekfix' ), array( 'status' => 400 ) );
				}

				return (string) $link->final_url;
		}

		return new WP_Error( 'seekfix_invalid_action', __( 'Unknown fix action.', 'seekfix' ), array( 'status' => 400 ) );
	}

	/**
	 * Checks the URL again before removing it.
	 *
	 * Only for removals: replacing a URL is the user's choice either way.
	 *
	 * @param object $link   Row of seekfix_links.
	 * @param string $action Action.
	 * @return bool Whether the URL answers correctly now.
	 */
	private function works_again( $link, $action ) {
		if ( ! in_array( $action, array( self::UNLINK, self::REMOVE_BUTTON ), true ) ) {
			return false;
		}

		if ( null === $this->checker ) {
			$this->checker = new Checker();
		}

		$fresh = $this->checker->check_now( (int) $link->id );

		return $fresh && in_array( $fresh->status, array( 'ok', 'redirect' ), true );
	}

	/**
	 * Element of an instance, defaulting to link.
	 *
	 * @param object $instance Instance.
	 * @return string
	 */
	private function element( $instance ) {
		return isset( self::ELEMENT_ACTIONS[ $instance->element ] ) ? $instance->element : 'link';
	}

	/**
	 * Error for an action the instance's element does not support.
	 *
	 * @param object $instance Instance.
	 * @return WP_Error
	 */
	private function not_allowed( $instance ) {
		$message = 'shortcode' === $instance->element
			? __( 'Links inside page builder shortcodes are fixed in the editor.', 'seekfix' )
			: __( 'This action is not available for this element.', 'seekfix' );

		return new WP_Error( 'seekfix_action_not_allowed', $message, array( 'status' => 400 ) );
	}

	/**
	 * Summary entry for a skipped post or instance.
	 *
	 * @param int      $post_id     Post ID.
	 * @param WP_Error $error       Reason.
	 * @param int|null $instance_id Instance ID, when one instance is skipped.
	 * @return array<string, mixed>
	 */
	private function skip_entry( $post_id, WP_Error $error, $instance_id = null ) {
		return array(
			'post_id'     => (int) $post_id,
			'instance_id' => $instance_id,
			'code'        => $error->get_error_code(),
			'message'     => $error->get_error_message(),
		);
	}
}
