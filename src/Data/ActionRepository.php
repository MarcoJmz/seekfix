<?php
/**
 * Access to the seekfix_actions table.
 *
 * @package SeekFix
 */

namespace SeekFix\Data;

use SeekFix\Installer;

/**
 * History of fixes, one row per post written.
 */
class ActionRepository {

	/**
	 * Stores a fix and returns its ID.
	 *
	 * @param array<string, mixed> $row Columns: post_id, link_id, action, old_url, new_url,
	 *                                  revision_id, old_content, content_hash, user_id.
	 * @return int Row ID, or 0 on a database error.
	 */
	public function insert( array $row ) {
		global $wpdb;

		$allowed = array( 'post_id', 'link_id', 'action', 'old_url', 'new_url', 'revision_id', 'old_content', 'content_hash', 'user_id' );
		$row     = array_intersect_key( $row, array_flip( $allowed ) );

		$row['created_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$result = $wpdb->insert( Installer::table( 'actions' ), $row );

		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Returns a row by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table( 'actions' ), $id ) );
	}

	/**
	 * Marks a fix as undone.
	 *
	 * Only a fix that is not undone yet changes, so two undo requests at the
	 * same time cannot both succeed.
	 *
	 * @param int $id Row ID.
	 * @return bool Whether this call marked it.
	 */
	public function mark_undone( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET undone_at = %s WHERE id = %d AND undone_at IS NULL',
				Installer::table( 'actions' ),
				current_time( 'mysql', true ),
				$id
			)
		);

		return 1 === $updated;
	}

	/**
	 * Deletes fixes older than a date (daily cleanup).
	 *
	 * @param string $before UTC time, "Y-m-d H:i:s".
	 * @return int Rows deleted.
	 */
	public function delete_older_than( $before ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Installer::table( 'actions' ), $before ) );
	}
}
