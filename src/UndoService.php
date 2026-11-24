<?php
/**
 * Undoes fixes.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\ActionRepository;
use WP_Error;
use WP_Post;

/**
 * Restores a post's content to what it was before a fix.
 *
 * Undo only works while the post is exactly as the fix left it. If someone
 * edited it afterwards, restoring blindly would throw their work away, so
 * the user is sent to the WordPress revisions screen instead.
 */
class UndoService {

	/**
	 * States of a fix in the history (see state()).
	 */
	const AVAILABLE   = 'available';
	const UNDONE      = 'undone';
	const EDITED      = 'edited';
	const NO_POST     = 'no_post';
	const NO_SNAPSHOT = 'no_snapshot';

	/**
	 * Fix history.
	 *
	 * @var ActionRepository
	 */
	private $actions;

	/**
	 * Permission checks and saving.
	 *
	 * @var PostWriter
	 */
	private $writer;

	/**
	 * Creates the service.
	 *
	 * @param ActionRepository|null $actions Fix history.
	 * @param PostWriter|null       $writer  Permission checks and saving.
	 */
	public function __construct( $actions = null, $writer = null ) {
		$this->actions = $actions ? $actions : new ActionRepository();
		$this->writer  = $writer ? $writer : new PostWriter();
	}

	/**
	 * Undoes a fix.
	 *
	 * @param int $action_id Row ID in seekfix_actions.
	 * @return array<string, int>|WP_Error `action_id` and `post_id`, or an error. When the post
	 *                                     changed after the fix, the error data holds
	 *                                     `revisions_url`.
	 */
	public function undo( $action_id ) {
		$action = $this->actions->get( $action_id );

		if ( ! $action ) {
			return new WP_Error( 'seekfix_action_not_found', __( 'This fix is not in the history any more.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$post_id = (int) $action->post_id;
		$error   = $this->writer->permission_error( $post_id );

		if ( $error ) {
			return $error;
		}

		if ( ! empty( $action->undone_at ) ) {
			return new WP_Error( 'seekfix_already_undone', __( 'This fix was already undone.', 'seekfix' ), array( 'status' => 409 ) );
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seekfix_post_not_found', __( 'The post no longer exists.', 'seekfix' ), array( 'status' => 404 ) );
		}

		$error = $this->writer->lock_error( $post_id );

		if ( $error ) {
			return $error;
		}

		if ( sha1( $post->post_content ) !== $action->content_hash ) {
			$data = array( 'status' => 409 );

			if ( $action->revision_id && get_post( (int) $action->revision_id ) ) {
				$data['revisions_url'] = admin_url( 'revision.php?revision=' . (int) $action->revision_id );
			}

			return new WP_Error(
				'seekfix_post_changed',
				__( 'The post was edited after this fix, so it cannot be undone automatically. Compare the revisions to restore it by hand.', 'seekfix' ),
				$data
			);
		}

		$old_content = $this->old_content( $action, $post_id );

		if ( null === $old_content ) {
			return new WP_Error(
				'seekfix_no_snapshot',
				__( 'The revision saved before this fix was deleted, so it cannot be undone.', 'seekfix' ),
				array( 'status' => 410 )
			);
		}

		$saved = $this->writer->write( $post, $old_content );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->actions->mark_undone( (int) $action->id );

		return array(
			'action_id' => (int) $action->id,
			'post_id'   => $post_id,
		);
	}

	/**
	 * Whether a fix can still be undone, without changing anything.
	 *
	 * @param object $action Row of seekfix_actions; old_content may be left out
	 *                       when the row has has_old_content.
	 * @return string One of the state constants.
	 */
	public function state( $action ) {
		if ( ! empty( $action->undone_at ) ) {
			return self::UNDONE;
		}

		$post_id = (int) $action->post_id;
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return self::NO_POST;
		}

		if ( sha1( $post->post_content ) !== $action->content_hash ) {
			return self::EDITED;
		}

		$has_old = isset( $action->has_old_content ) ? (bool) $action->has_old_content : isset( $action->old_content );

		if ( null === $this->revision( $action, $post_id ) && ! $has_old ) {
			return self::NO_SNAPSHOT;
		}

		return self::AVAILABLE;
	}

	/**
	 * Content before the fix, from its revision or from old_content.
	 *
	 * @param object $action  Row of seekfix_actions.
	 * @param int    $post_id Post the fix belongs to.
	 * @return string|null
	 */
	private function old_content( $action, $post_id ) {
		$revision = $this->revision( $action, $post_id );

		if ( $revision ) {
			return $revision->post_content;
		}

		return null === $action->old_content ? null : (string) $action->old_content;
	}

	/**
	 * Revision saved before the fix, if it still exists.
	 *
	 * @param object $action  Row of seekfix_actions.
	 * @param int    $post_id Post the fix belongs to.
	 * @return WP_Post|null
	 */
	private function revision( $action, $post_id ) {
		if ( ! $action->revision_id ) {
			return null;
		}

		$revision = get_post( (int) $action->revision_id );

		return $revision instanceof WP_Post && 'revision' === $revision->post_type && (int) $revision->post_parent === $post_id ? $revision : null;
	}
}
