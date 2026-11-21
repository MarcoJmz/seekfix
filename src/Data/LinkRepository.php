<?php
/**
 * Access to the seekfix_links table.
 *
 * @package SeekFix
 */

namespace SeekFix\Data;

use SeekFix\Installer;

/**
 * One row per unique normalized URL.
 */
class LinkRepository {

	/**
	 * Returns the ID of a URL's row, creating it as pending when new.
	 *
	 * An existing row is left untouched: a new scan never resets status or
	 * fail_count.
	 *
	 * @param string $url         Normalized URL.
	 * @param string $url_hash    SHA-1 of the URL.
	 * @param string $host        Lowercase host.
	 * @param bool   $is_internal Whether the URL points to this site.
	 * @return int Row ID, or 0 on a database error.
	 */
	public function find_or_create( $url, $url_hash, $host, $is_internal ) {
		global $wpdb;

		$table = Installer::table( 'links' );

		// LAST_INSERT_ID(id) makes insert_id return the existing row's ID on a duplicate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; writes are not cached.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (url, url_hash, host, is_internal, status, next_check_at) VALUES (%s, %s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
				$table,
				$url,
				$url_hash,
				substr( $host, 0, 191 ),
				$is_internal ? 1 : 0,
				'pending',
				current_time( 'mysql', true )
			)
		);

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
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Installer::table( 'links' ), $id ) );
	}

	/**
	 * Returns a row by normalized URL.
	 *
	 * @param string $url Normalized URL.
	 * @return object|null
	 */
	public function get_by_url( $url ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE url_hash = %s', Installer::table( 'links' ), sha1( $url ) ) );
	}

	/**
	 * Deletes URLs that no longer appear in any post (daily cleanup).
	 *
	 * @return int Rows deleted.
	 */
	public function delete_orphans() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE l FROM %i l LEFT JOIN %i i ON i.link_id = l.id WHERE i.id IS NULL',
				Installer::table( 'links' ),
				Installer::table( 'instances' )
			)
		);

		return (int) $deleted;
	}
}
