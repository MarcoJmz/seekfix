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
	 * Returns the URLs due for a check, oldest first, skipping ignored ones.
	 *
	 * @param string $now   Current UTC time, "Y-m-d H:i:s".
	 * @param int    $limit Maximum rows.
	 * @return object[]
	 */
	public function due( $now, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Work queue; must not be cached.
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ignored = 0 AND next_check_at <= %s ORDER BY next_check_at ASC, id ASC LIMIT %d',
				Installer::table( 'links' ),
				$now,
				$limit
			)
		);
	}

	/**
	 * Saves the outcome of a check.
	 *
	 * @param int                  $id  Row ID.
	 * @param array<string, mixed> $row Columns from ResponseClassifier::apply().
	 * @return bool
	 */
	public function save_check( $id, array $row ) {
		global $wpdb;

		$allowed = array( 'status', 'http_code', 'error_code', 'ssl_invalid', 'final_url', 'redirect_chain', 'fail_count', 'fail_days', 'first_failure_at', 'last_checked_at', 'next_check_at' );
		$row     = array_intersect_key( $row, array_flip( $allowed ) );

		if ( empty( $row ) ) {
			return false;
		}

		if ( isset( $row['status'] ) && 'broken' !== $row['status'] ) {
			// A URL that works again is reported again if it breaks later.
			$row['notified_at'] = null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->update( Installer::table( 'links' ), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Returns the IDs of broken URLs not yet sent by e-mail.
	 *
	 * Only URLs that still appear in some post and are not ignored count.
	 *
	 * @return int[] Row IDs, oldest first.
	 */
	public function unnotified_broken_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must be fresh.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT l.id FROM %i l WHERE l.status = %s AND l.ignored = 0 AND l.notified_at IS NULL AND EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id) ORDER BY l.id',
				Installer::table( 'links' ),
				'broken',
				Installer::table( 'instances' )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Records that some URLs were sent by e-mail.
	 *
	 * @param int[]  $ids Row IDs.
	 * @param string $now UTC time, "Y-m-d H:i:s".
	 * @return int Rows changed.
	 */
	public function mark_notified( array $ids, $now ) {
		global $wpdb;

		$changed = 0;

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table; one %d per ID.
			$changed += (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET notified_at = %s WHERE id IN ({$placeholders})", array_merge( array( Installer::table( 'links' ), $now ), $chunk ) ) );
		}

		return $changed;
	}

	/**
	 * Marks a URL as ignored (taken out of the queue) or not.
	 *
	 * @param int  $id      Row ID.
	 * @param bool $ignored Whether to ignore it.
	 * @return bool
	 */
	public function set_ignored( $id, $ignored ) {
		global $wpdb;

		$row = array( 'ignored' => $ignored ? 1 : 0 );

		if ( ! $ignored ) {
			// Back in the queue: check it again soon.
			$row['next_check_at'] = current_time( 'mysql', true );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->update( Installer::table( 'links' ), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Makes every URL due now, for a full scan. Status and counters stay.
	 *
	 * @param string $now UTC time, "Y-m-d H:i:s".
	 * @return int Rows changed.
	 */
	public function make_all_due( $now ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET next_check_at = %s WHERE ignored = 0 AND next_check_at > %s', Installer::table( 'links' ), $now, $now ) );
	}

	/**
	 * Counts URLs in the queue (not ignored), and how many were checked since a time.
	 *
	 * @param string $since UTC time, "Y-m-d H:i:s".
	 * @return array{total:int,checked:int}
	 */
	public function count_checked_since( $since ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Progress count; must be fresh.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(last_checked_at IS NOT NULL AND last_checked_at >= %s) AS checked FROM %i WHERE ignored = 0',
				$since,
				Installer::table( 'links' )
			)
		);

		return array(
			'total'   => $row ? (int) $row->total : 0,
			'checked' => $row ? (int) $row->checked : 0,
		);
	}

	/**
	 * Counts URLs due before a time, skipping ignored ones.
	 *
	 * @param string $before UTC time, "Y-m-d H:i:s".
	 * @return int
	 */
	public function count_due_before( $before ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Work queue; must be fresh.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ignored = 0 AND next_check_at <= %s', Installer::table( 'links' ), $before ) );
	}

	/**
	 * Counts confirmed broken URLs that still appear in some post.
	 *
	 * @return int
	 */
	public function count_broken_in_use() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cached by the caller.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i l WHERE l.status = %s AND l.ignored = 0 AND EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id)',
				Installer::table( 'links' ),
				'broken',
				Installer::table( 'instances' )
			)
		);
	}

	/**
	 * Counts URLs that appear in some post, by status, ignored ones apart.
	 *
	 * @return array<string, int> Counts keyed by status, plus "ignored".
	 */
	public function count_by_status() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT IF(l.ignored = 1, %s, l.status) AS s, COUNT(*) AS n FROM %i l WHERE EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id) GROUP BY s',
				'ignored',
				Installer::table( 'links' ),
				Installer::table( 'instances' )
			)
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ $row->s ] = (int) $row->n;
		}

		return $counts;
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
