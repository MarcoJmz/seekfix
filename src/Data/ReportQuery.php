<?php
/**
 * Read-only queries for the admin report and the CSV export.
 *
 * @package SeekFix
 */

namespace SeekFix\Data;

use SeekFix\Installer;

/**
 * Lists instances (one row per link in a post) or URLs (one row per URL).
 *
 * Pending URLs are never listed: they are counted in the summary until the
 * Checker gives them a status. Every filter value is passed to prepare();
 * sort columns come from a fixed list.
 */
class ReportQuery {

	/**
	 * Statuses that can be listed.
	 */
	const STATUSES = array( 'broken', 'unverifiable', 'redirect', 'ok' );

	/**
	 * Elements.
	 */
	const ELEMENTS = array( 'link', 'button', 'image', 'shortcode' );

	/**
	 * Sortable columns, by the name the API accepts.
	 */
	const SORT_INSTANCES = array(
		'url'             => 'l.url',
		'status'          => 'l.status',
		'http_code'       => 'l.http_code',
		'element'         => 'i.element',
		'anchor_text'     => 'i.anchor_text',
		'post_title'      => 'p.post_title',
		'last_checked_at' => 'l.last_checked_at',
	);

	/**
	 * Sortable columns when grouped by URL.
	 */
	const SORT_LINKS = array(
		'url'             => 'l.url',
		'status'          => 'l.status',
		'http_code'       => 'l.http_code',
		'last_checked_at' => 'l.last_checked_at',
		'posts'           => 'posts',
		'instances'       => 'instances',
	);

	/**
	 * Lists instances or URLs.
	 *
	 * @param array<string, mixed> $filters  `status` (string[] of STATUSES, or "ignored"),
	 *                                       `post_type` (string[]), `element` (string[]),
	 *                                       `host` (string), `internal` ("internal"|"external"),
	 *                                       `search` (string).
	 * @param bool                 $by_url   One row per URL instead of per instance.
	 * @param int                  $page     1-based page.
	 * @param int                  $per_page Rows per page.
	 * @param string               $orderby  Key of SORT_INSTANCES or SORT_LINKS.
	 * @param string               $order    "asc" or "desc".
	 * @return array{rows:object[],total:int}
	 */
	public function query( array $filters, $by_url, $page, $per_page, $orderby = 'url', $order = 'asc' ) {
		global $wpdb;

		list( $where, $args ) = $this->where( $filters );

		$sort    = $by_url ? self::SORT_LINKS : self::SORT_INSTANCES;
		$column  = isset( $sort[ $orderby ] ) ? $sort[ $orderby ] : 'l.url';
		$dir     = 'desc' === strtolower( (string) $order ) ? 'DESC' : 'ASC';
		$tie     = $by_url ? 'l.id' : 'i.id';
		$offset  = max( 0, ( (int) $page - 1 ) * (int) $per_page );
		$from    = 'FROM %i i JOIN %i l ON l.id = i.link_id JOIN %i p ON p.ID = i.post_id';
		$tables  = array( Installer::table( 'instances' ), Installer::table( 'links' ), $wpdb->posts );
		$select  = $by_url
			? 'SELECT l.id AS link_id, l.url, l.host, l.is_internal, l.status, l.http_code, l.error_code, l.ssl_invalid, l.final_url, l.redirect_chain, l.last_checked_at, l.ignored, COUNT(*) AS instances, COUNT(DISTINCT i.post_id) AS posts, GROUP_CONCAT(DISTINCT i.element) AS elements'
			: 'SELECT i.id, i.link_id, i.post_id, i.element, i.shortcode_tag, i.anchor_text, l.url, l.host, l.is_internal, l.status, l.http_code, l.error_code, l.ssl_invalid, l.final_url, l.redirect_chain, l.last_checked_at, l.ignored, p.post_title, p.post_type';
		$group   = $by_url ? ' GROUP BY l.id' : '';
		$counter = $by_url ? 'SELECT COUNT(DISTINCT l.id) ' : 'SELECT COUNT(*) ';

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WHERE placeholders built in where(); sort column and direction come from fixed lists.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Report over custom tables; must be fresh.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $counter . $from . $where, array_merge( $tables, $args ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"{$select} {$from}{$where}{$group} ORDER BY {$column} {$dir}, {$tie} ASC LIMIT %d OFFSET %d",
				array_merge( $tables, $args, array( (int) $per_page, $offset ) )
			)
		);
		// phpcs:enable

		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Hosts of the URLs that can be listed, most frequent first.
	 *
	 * @param int $limit Maximum hosts.
	 * @return string[]
	 */
	public function hosts( $limit = 200 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Filter options; small and fresh.
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT l.host FROM %i l WHERE l.status <> %s AND EXISTS (SELECT 1 FROM %i i WHERE i.link_id = l.id) GROUP BY l.host ORDER BY COUNT(*) DESC, l.host ASC LIMIT %d',
				Installer::table( 'links' ),
				'pending',
				Installer::table( 'instances' ),
				$limit
			)
		);
	}

	/**
	 * Builds the WHERE clause for the filters.
	 *
	 * @param array<string, mixed> $filters Filters (see query()).
	 * @return array{0:string,1:array}
	 */
	private function where( array $filters ) {
		global $wpdb;

		$clauses = array( 'l.status <> %s' );
		$args    = array( 'pending' );

		$status = isset( $filters['status'] ) ? array_map( 'strval', (array) $filters['status'] ) : array();

		if ( in_array( 'ignored', $status, true ) ) {
			$clauses[] = 'l.ignored = 1';
		} else {
			$clauses[] = 'l.ignored = 0';
			$status    = array_values( array_intersect( $status, self::STATUSES ) );

			if ( ! empty( $status ) ) {
				$clauses[] = 'l.status IN (' . implode( ', ', array_fill( 0, count( $status ), '%s' ) ) . ')';
				$args      = array_merge( $args, $status );
			}
		}

		$types = isset( $filters['post_type'] ) ? array_values( array_filter( array_map( 'strval', (array) $filters['post_type'] ) ) ) : array();

		if ( ! empty( $types ) ) {
			$clauses[] = 'p.post_type IN (' . implode( ', ', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$args      = array_merge( $args, $types );
		}

		$elements = isset( $filters['element'] ) ? array_values( array_intersect( array_map( 'strval', (array) $filters['element'] ), self::ELEMENTS ) ) : array();

		if ( ! empty( $elements ) ) {
			$clauses[] = 'i.element IN (' . implode( ', ', array_fill( 0, count( $elements ), '%s' ) ) . ')';
			$args      = array_merge( $args, $elements );
		}

		if ( ! empty( $filters['host'] ) && is_string( $filters['host'] ) ) {
			$clauses[] = 'l.host = %s';
			$args[]    = strtolower( $filters['host'] );
		}

		if ( isset( $filters['internal'] ) && in_array( $filters['internal'], array( 'internal', 'external' ), true ) ) {
			$clauses[] = 'l.is_internal = %d';
			$args[]    = 'internal' === $filters['internal'] ? 1 : 0;
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== trim( $filters['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( trim( $filters['search'] ) ) . '%';
			$clauses[] = '(l.url LIKE %s OR i.anchor_text LIKE %s)';
			$args[]    = $like;
			$args[]    = $like;
		}

		return array( ' WHERE ' . implode( ' AND ', $clauses ), $args );
	}
}
