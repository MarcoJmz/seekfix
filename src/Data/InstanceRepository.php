<?php
/**
 * Access to the seekfix_instances table.
 *
 * @package SeekFix
 */

namespace SeekFix\Data;

use SeekFix\Installer;

/**
 * One row per appearance of a URL in a post.
 */
class InstanceRepository {

	/**
	 * Rows per INSERT statement.
	 */
	const INSERT_CHUNK = 100;

	/**
	 * Replaces all instances of a post.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $instances Rows with link_id, element, shortcode_tag, anchor_text,
	 *                         raw_href, occurrence and content_hash.
	 */
	public function replace_for_post( $post_id, array $instances ) {
		global $wpdb;

		$this->delete_for_post( $post_id );

		$table = Installer::table( 'instances' );

		foreach ( array_chunk( $instances, self::INSERT_CHUNK ) as $chunk ) {
			$placeholders = array();
			$values       = array( $table );

			foreach ( $chunk as $row ) {
				$placeholders[] = '(%d, %d, %s, ' . ( null === $row['shortcode_tag'] ? 'NULL' : '%s' ) . ', %s, %s, %d, %s)';
				$values[]       = (int) $row['link_id'];
				$values[]       = (int) $post_id;
				$values[]       = $row['element'];

				if ( null !== $row['shortcode_tag'] ) {
					$values[] = substr( $row['shortcode_tag'], 0, 64 );
				}

				$values[] = $row['anchor_text'];
				$values[] = $row['raw_href'];
				$values[] = min( 65535, (int) $row['occurrence'] );
				$values[] = $row['content_hash'];
			}

			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders are built above, one group per row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (link_id, post_id, element, shortcode_tag, anchor_text, raw_href, occurrence, content_hash) VALUES ' . implode( ', ', $placeholders ),
					$values
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Deletes all instances of a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_for_post( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE post_id = %d', Installer::table( 'instances' ), $post_id ) );
	}

	/**
	 * Returns all instances of a post, in insertion order.
	 *
	 * @param int $post_id Post ID.
	 * @return object[]
	 */
	public function for_post( $post_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE post_id = %d ORDER BY id', Installer::table( 'instances' ), $post_id ) );
	}
}
