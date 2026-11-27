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
	 * Sortable columns, by the name the API accepts. Each one is a column of
	 * the SELECT, so ORDER BY can use the name without a table alias.
	 */
	const SORT_INSTANCES = array( 'url', 'status', 'http_code', 'element', 'anchor_text', 'post_title', 'last_checked_at' );

	/**
	 * Sortable columns when grouped by URL.
	 */
	const SORT_LINKS = array( 'url', 'status', 'http_code', 'last_checked_at', 'posts', 'instances' );

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

		$values = $this->filter_values( $filters );

		/*
		 * Every part of the SQL below is a literal string or comes from
		 * sprintf(), implode() or sanitize_sql_orderby() over literal strings;
		 * filter values only reach $args. The host, internal and search filters
		 * are always in the WHERE clause and switch themselves off with an
		 * empty value, so the clause list never depends on a value.
		 */
		$clauses = array(
			'l.status <> %s',
			'l.ignored = %d',
			"( %s = '' OR l.host = %s )",
			'( %d < 0 OR l.is_internal = %d )',
			"( %s = '' OR l.url LIKE %s OR i.anchor_text LIKE %s )",
		);
		$args    = array(
			'pending',
			$values['ignored'] ? 1 : 0,
			$values['host'],
			$values['host'],
			$values['internal'],
			$values['internal'],
			$values['search'],
			$values['like'],
			$values['like'],
		);

		if ( ! empty( $values['status'] ) ) {
			$clauses[] = sprintf( 'l.status IN (%s)', implode( ', ', array_fill( 0, count( $values['status'] ), '%s' ) ) );
			$args      = array_merge( $args, $values['status'] );
		}

		if ( ! empty( $values['post_type'] ) ) {
			$clauses[] = sprintf( 'p.post_type IN (%s)', implode( ', ', array_fill( 0, count( $values['post_type'] ), '%s' ) ) );
			$args      = array_merge( $args, $values['post_type'] );
		}

		if ( ! empty( $values['element'] ) ) {
			$clauses[] = sprintf( 'i.element IN (%s)', implode( ', ', array_fill( 0, count( $values['element'] ), '%s' ) ) );
			$args      = array_merge( $args, $values['element'] );
		}

		$where = implode( ' AND ', $clauses );

		$sort      = $by_url ? self::SORT_LINKS : self::SORT_INSTANCES;
		$column    = in_array( $orderby, $sort, true ) ? $orderby : 'url';
		$direction = 'desc' === strtolower( (string) $order ) ? 'DESC' : 'ASC';
		$tie       = $by_url ? 'link_id' : 'id';
		$order_sql = sanitize_sql_orderby( "{$column} {$direction}, {$tie} ASC" );

		$tables = array( Installer::table( 'instances' ), Installer::table( 'links' ), $wpdb->posts );
		$limit  = array( (int) $per_page, max( 0, ( (int) $page - 1 ) * (int) $per_page ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The WHERE placeholders are built above; ORDER BY is a fixed column checked by sanitize_sql_orderby().
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Report over custom tables; must be fresh.
		if ( $by_url ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(DISTINCT l.id) FROM %i i JOIN %i l ON l.id = i.link_id JOIN %i p ON p.ID = i.post_id WHERE ' . $where,
					array_merge( $tables, $args )
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT l.id AS link_id, l.url, l.host, l.is_internal, l.status, l.http_code, l.error_code, l.ssl_invalid, l.final_url, l.redirect_chain, l.last_checked_at, l.ignored, COUNT(*) AS instances, COUNT(DISTINCT i.post_id) AS posts, GROUP_CONCAT(DISTINCT i.element) AS elements FROM %i i JOIN %i l ON l.id = i.link_id JOIN %i p ON p.ID = i.post_id WHERE ' . $where . ' GROUP BY l.id ORDER BY ' . $order_sql . ' LIMIT %d OFFSET %d',
					array_merge( $tables, $args, $limit )
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i i JOIN %i l ON l.id = i.link_id JOIN %i p ON p.ID = i.post_id WHERE ' . $where,
					array_merge( $tables, $args )
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT i.id, i.link_id, i.post_id, i.element, i.shortcode_tag, i.anchor_text, l.url, l.host, l.is_internal, l.status, l.http_code, l.error_code, l.ssl_invalid, l.final_url, l.redirect_chain, l.last_checked_at, l.ignored, p.post_title, p.post_type FROM %i i JOIN %i l ON l.id = i.link_id JOIN %i p ON p.ID = i.post_id WHERE ' . $where . ' ORDER BY ' . $order_sql . ' LIMIT %d OFFSET %d',
					array_merge( $tables, $args, $limit )
				)
			);
		}
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
	 * Reads the filters into the values query() passes to prepare().
	 *
	 * "ignored" in the status filter lists the ignored URLs and drops the
	 * other statuses. An empty host or search, and an internal value of -1,
	 * turn that filter off.
	 *
	 * @param array<string, mixed> $filters Filters (see query()).
	 * @return array{ignored:bool,status:string[],post_type:string[],element:string[],host:string,internal:int,search:string,like:string}
	 */
	private function filter_values( array $filters ) {
		global $wpdb;

		$status  = isset( $filters['status'] ) ? array_map( 'strval', (array) $filters['status'] ) : array();
		$ignored = in_array( 'ignored', $status, true );
		$search  = isset( $filters['search'] ) && is_string( $filters['search'] ) ? trim( $filters['search'] ) : '';
		$inside  = isset( $filters['internal'] ) ? $filters['internal'] : '';

		return array(
			'ignored'   => $ignored,
			'status'    => $ignored ? array() : array_values( array_intersect( $status, self::STATUSES ) ),
			'post_type' => isset( $filters['post_type'] ) ? array_values( array_filter( array_map( 'strval', (array) $filters['post_type'] ) ) ) : array(),
			'element'   => isset( $filters['element'] ) ? array_values( array_intersect( array_map( 'strval', (array) $filters['element'] ), self::ELEMENTS ) ) : array(),
			'host'      => ! empty( $filters['host'] ) && is_string( $filters['host'] ) ? strtolower( $filters['host'] ) : '',
			'internal'  => 'internal' === $inside ? 1 : ( 'external' === $inside ? 0 : -1 ),
			'search'    => $search,
			'like'      => '' === $search ? '' : '%' . $wpdb->esc_like( $search ) . '%',
		);
	}
}
