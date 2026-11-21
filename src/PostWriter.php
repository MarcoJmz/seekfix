<?php
/**
 * Checks and writes shared by fixes and undo.
 *
 * @package SeekFix
 */

namespace SeekFix;

use WP_Error;
use WP_Post;

/**
 * Saves new post content without letting anything else in the post change.
 *
 * FixService and UndoService are the only callers. Neither of them writes
 * post content any other way.
 */
class PostWriter {

	/**
	 * Error when the current user cannot edit the post.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Error|null
	 */
	public function permission_error( $post_id ) {
		if ( current_user_can( 'edit_post', $post_id ) ) {
			return null;
		}

		return new WP_Error(
			'seekfix_forbidden',
			__( 'You are not allowed to edit this post.', 'seekfix' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Error when another user has the post open in the editor.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Error|null
	 */
	public function lock_error( $post_id ) {
		if ( ! function_exists( 'wp_check_post_lock' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$user_id = wp_check_post_lock( $post_id );

		if ( ! $user_id ) {
			return null;
		}

		$user = get_userdata( $user_id );

		return new WP_Error(
			'seekfix_post_locked',
			sprintf(
				/* translators: %s: name of the user who is editing the post. */
				__( '%s is editing this post right now. Try again when they are done.', 'seekfix' ),
				$user ? $user->display_name : __( 'Another user', 'seekfix' )
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * Keeps a copy of the current content so the change can be undone.
	 *
	 * Uses a WordPress revision when the post type has revisions. When it does
	 * not (or WP_POST_REVISIONS is false), the content itself is returned so
	 * the caller can store it.
	 *
	 * @param WP_Post $post Post before the change.
	 * @return array{revision_id:int|null,old_content:string|null}
	 */
	public function snapshot( WP_Post $post ) {
		if ( wp_revisions_enabled( $post ) ) {
			$revision_id = wp_save_post_revision( $post->ID );

			if ( ! $revision_id || is_wp_error( $revision_id ) ) {
				// No new revision when the latest one already matches the post.
				$latest      = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) );
				$revision_id = empty( $latest ) ? 0 : (int) reset( $latest )->ID;
			}

			$revision = $revision_id ? get_post( $revision_id ) : null;

			if ( $revision && $revision->post_content === $post->post_content ) {
				return array(
					'revision_id' => (int) $revision_id,
					'old_content' => null,
				);
			}
		}

		return array(
			'revision_id' => null,
			'old_content' => $post->post_content,
		);
	}

	/**
	 * Saves new content with wp_update_post().
	 *
	 * Before saving, the content goes through the same filters that
	 * wp_insert_post() applies (kses for users without unfiltered_html, and any
	 * plugin on content_save_pre). If they would change it, nothing is saved:
	 * those filters could strip HTML far from the link.
	 *
	 * @param WP_Post $post        Post.
	 * @param string  $new_content New content, unslashed.
	 * @return string|WP_Error The content as stored, or an error.
	 */
	public function write( WP_Post $post, $new_content ) {
		$filtered = wp_unslash( sanitize_post_field( 'post_content', wp_slash( $new_content ), $post->ID, 'db' ) );

		if ( $filtered !== $new_content ) {
			return new WP_Error(
				'seekfix_content_filtered',
				current_user_can( 'unfiltered_html' )
					? __( 'Saving this post would change more than the link, because a plugin filters post content on save. Open the editor to fix it.', 'seekfix' )
					: __( 'Saving this post with your role would remove HTML that only administrators can add. Ask an administrator to fix it, or open the editor.', 'seekfix' ),
				array( 'status' => 409 )
			);
		}

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				)
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'seekfix_save_failed',
				/* translators: %s: error message from WordPress. */
				sprintf( __( 'WordPress could not save the post: %s', 'seekfix' ), $result->get_error_message() ),
				array( 'status' => 500 )
			);
		}

		clean_post_cache( $post->ID );
		$saved = get_post( $post->ID );

		return $saved ? $saved->post_content : $new_content;
	}
}
