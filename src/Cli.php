<?php
/**
 * WP-CLI commands.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\LinkRepository;
use WP_CLI;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI passes ( $args, $assoc_args ) to every command.

/**
 * Scan and check links from the command line.
 */
class Cli {

	/**
	 * Extracts the links of every scannable post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seekfix index
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function index( $args, $assoc_args ) {
		$indexer = Plugin::indexer();
		$last    = 0;
		$batches = 0;

		do {
			$last = $indexer->index_batch( $last );
			++$batches;
		} while ( null !== $last );

		WP_CLI::success( sprintf( 'Indexed posts in %d batches.', $batches - 1 ) );
		$this->status( array(), array() );
	}

	/**
	 * Checks the URLs that are due.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : URLs per batch.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--all]
	 * : Keep running batches until no URL is due.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seekfix check --all
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function check( $args, $assoc_args ) {
		$checker = new Checker();
		$limit   = max( 1, (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', Checker::BATCH_SIZE ) );
		$all     = (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'all', false );
		$total   = 0;
		$start   = microtime( true );

		do {
			$done   = $checker->run_batch( $limit );
			$total += $done;

			if ( $all && $done > 0 ) {
				WP_CLI::log( sprintf( 'Checked %d URLs (%.0f s).', $total, microtime( true ) - $start ) );
			}
		} while ( $all && $done > 0 );

		WP_CLI::success( sprintf( 'Checked %d URLs in %.1f s.', $total, microtime( true ) - $start ) );
		$this->status( array(), array() );
	}

	/**
	 * Checks one URL now, with the same rules as a scheduled check.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL as stored (normalized), or its row ID.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function recheck( $args, $assoc_args ) {
		$links = new LinkRepository();
		$link  = ctype_digit( $args[0] ) ? $links->get( (int) $args[0] ) : $links->get_by_url( $args[0] );

		if ( ! $link ) {
			WP_CLI::error( 'That URL is not in the SeekFix tables. Run "wp seekfix index" first.' );
		}

		$link = ( new Checker() )->check_now( (int) $link->id );

		WP_CLI\Utils\format_items( 'yaml', array( (array) $link ), array( 'url', 'status', 'http_code', 'error_code', 'ssl_invalid', 'final_url', 'redirect_chain', 'fail_count', 'fail_days', 'next_check_at' ) );
	}

	/**
	 * Shows how many URLs there are in each status.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$rows = array();

		foreach ( ( new LinkRepository() )->count_by_status() as $status => $count ) {
			$rows[] = array(
				'status' => $status,
				'urls'   => $count,
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'status', 'urls' ) );
	}
}
