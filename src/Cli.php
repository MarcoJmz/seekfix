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
	 * Fixes one instance of a link. Needs --user with permission to edit the post.
	 *
	 * ## OPTIONS
	 *
	 * <instance-id>
	 * : Row ID in the instances table.
	 *
	 * --action=<action>
	 * : What to do.
	 * ---
	 * options:
	 *   - unlink
	 *   - remove_button
	 *   - replace_url
	 *   - use_final_url
	 * ---
	 *
	 * [--new-url=<url>]
	 * : New URL, for replace_url.
	 *
	 * [--force]
	 * : Fix it even if the URL works again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seekfix fix 42 --action=unlink --user=admin
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function fix( $args, $assoc_args ) {
		$result = ( new FixService() )->fix_instance( (int) $args[0], (string) $assoc_args['action'], $this->fix_args( $assoc_args ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Fixed post %d. Undo with: wp seekfix undo %d', $result['post_id'], $result['action_id'] ) );
	}

	/**
	 * Fixes every instance of a URL. Needs --user with permission to edit the posts.
	 *
	 * ## OPTIONS
	 *
	 * <link-id>
	 * : Row ID in the links table.
	 *
	 * --action=<action>
	 * : What to do.
	 * ---
	 * options:
	 *   - unlink
	 *   - remove_button
	 *   - replace_url
	 *   - use_final_url
	 * ---
	 *
	 * [--new-url=<url>]
	 * : New URL, for replace_url.
	 *
	 * [--force]
	 * : Fix it even if the URL works again.
	 *
	 * [--dry-run]
	 * : Only count the posts and links that would change.
	 *
	 * @subcommand fix-link
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function fix_link( $args, $assoc_args ) {
		$service = new FixService();
		$action  = (string) $assoc_args['action'];

		if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			$plan = $service->plan_link( (int) $args[0], $action );

			if ( is_wp_error( $plan ) ) {
				WP_CLI::error( $plan->get_error_message() );
			}

			WP_CLI::log( sprintf( '%d links in %d posts would change; %d skipped.', $plan['instances'], $plan['posts'], count( $plan['skipped'] ) ) );
			return;
		}

		$summary = $service->fix_link( (int) $args[0], $action, $this->fix_args( $assoc_args ) );

		if ( is_wp_error( $summary ) ) {
			WP_CLI::error( $summary->get_error_message() );
		}

		if ( $summary['works_again'] ) {
			WP_CLI::warning( 'This URL works again, so nothing was changed. Use --force to fix it anyway.' );
			return;
		}

		foreach ( $summary['fixed'] as $fixed ) {
			WP_CLI::log( sprintf( 'Post %d: %d links fixed (undo: wp seekfix undo %d).', $fixed['post_id'], $fixed['instances'], $fixed['action_id'] ) );
		}

		foreach ( $summary['skipped'] as $skipped ) {
			WP_CLI::log( sprintf( 'Post %d skipped: %s', $skipped['post_id'], $skipped['message'] ) );
		}

		WP_CLI::success( sprintf( '%d posts fixed, %d skipped.', count( $summary['fixed'] ), count( $summary['skipped'] ) ) );
	}

	/**
	 * Undoes a fix. Needs --user with permission to edit the post.
	 *
	 * ## OPTIONS
	 *
	 * <action-id>
	 * : Row ID in the actions table, as printed by fix.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function undo( $args, $assoc_args ) {
		$result = ( new UndoService() )->undo( (int) $args[0] );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			$hint = is_array( $data ) && isset( $data['revisions_url'] ) ? ' ' . $data['revisions_url'] : '';

			WP_CLI::error( $result->get_error_message() . $hint );
		}

		WP_CLI::success( sprintf( 'Post %d restored.', $result['post_id'] ) );
	}

	/**
	 * Arguments for FixService from command options.
	 *
	 * @param array $assoc_args Named arguments.
	 * @return array<string, mixed>
	 */
	private function fix_args( $assoc_args ) {
		return array(
			'new_url' => isset( $assoc_args['new-url'] ) ? (string) $assoc_args['new-url'] : '',
			'force'   => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false ),
		);
	}

	/**
	 * Sends the broken link e-mail now, if it is on and there is something new.
	 *
	 * ## EXAMPLES
	 *
	 *     wp seekfix notify
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function notify( $args, $assoc_args ) {
		if ( ! Settings::notification_recipients() ) {
			WP_CLI::warning( 'Broken link e-mails are off.' );
			return;
		}

		$sent = ( new Notifier() )->send();

		if ( $sent > 0 ) {
			WP_CLI::success( sprintf( 'Reported %d broken URLs.', $sent ) );
		} else {
			WP_CLI::log( 'Nothing sent: no new broken URLs, or wp_mail() failed.' );
		}
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
