<?php
/**
 * REST API for the admin screen.
 *
 * @package SeekFix
 */

namespace SeekFix\Rest;

use SeekFix\Admin\AdminPage;
use SeekFix\Checker;
use SeekFix\Data\LinkRepository;
use SeekFix\Data\ReportQuery;
use SeekFix\FixService;
use SeekFix\Installer;
use SeekFix\ScanController;
use SeekFix\Settings;
use SeekFix\UndoService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Routes under seekfix/v1. Every route checks a capability in its
 * permission_callback and uses the standard wp_rest nonce; fixes and undo
 * also check edit_post on each post (in FixService and UndoService).
 */
class Controller {

	/**
	 * Namespace.
	 */
	const NAMESPACE = 'seekfix/v1';

	/**
	 * Maximum rows in a CSV export.
	 */
	const EXPORT_LIMIT = 20000;

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$admin = array( $this, 'can_manage' );
		$id    = array(
			'id' => array(
				'type'     => 'integer',
				'minimum'  => 1,
				'required' => true,
			),
		);
		$fix   = array(
			'action'  => array(
				'type'     => 'string',
				'enum'     => array( FixService::UNLINK, FixService::REMOVE_BUTTON, FixService::REPLACE_URL, FixService::USE_FINAL_URL ),
				'required' => true,
			),
			'new_url' => array(
				'type'    => 'string',
				'default' => '',
			),
			'force'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);

		$this->route( '/summary', WP_REST_Server::READABLE, 'summary', $admin );
		$this->route( '/instances', WP_REST_Server::READABLE, 'instances', $admin, $this->list_args() );
		$this->route( '/hosts', WP_REST_Server::READABLE, 'hosts', $admin );
		$this->route(
			'/scan',
			WP_REST_Server::CREATABLE,
			'scan',
			$admin,
			array(
				'action' => array(
					'type'     => 'string',
					'enum'     => array( 'start', 'pause', 'resume', 'cancel', 'step' ),
					'required' => true,
				),
			)
		);
		$this->route( '/links/(?P<id>\d+)/recheck', WP_REST_Server::CREATABLE, 'recheck', $admin, $id );
		$this->route(
			'/links/(?P<id>\d+)/ignore',
			WP_REST_Server::CREATABLE,
			'ignore',
			$admin,
			$id + array(
				'ignored' => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
		$this->route( '/links/(?P<id>\d+)/plan', WP_REST_Server::READABLE, 'plan', $admin, $id + array( 'action' => $fix['action'] ) );
		$this->route( '/instances/(?P<id>\d+)/fix', WP_REST_Server::CREATABLE, 'fix_instance', $admin, $id + $fix );
		$this->route(
			'/instances/fix',
			WP_REST_Server::CREATABLE,
			'fix_instances',
			$admin,
			array(
				'ids'    => array(
					'type'     => 'array',
					'items'    => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems' => 1,
					'maxItems' => 100,
					'required' => true,
				),
				'action' => $fix['action'],
				'force'  => $fix['force'],
			)
		);
		$this->route(
			'/links/(?P<id>\d+)/fix',
			WP_REST_Server::CREATABLE,
			'fix_link',
			$admin,
			$id + $fix + array(
				'expected' => array(
					'type'     => 'integer',
					'minimum'  => 0,
					'required' => true,
				),
			)
		);
		$this->route( '/actions/(?P<id>\d+)/undo', WP_REST_Server::CREATABLE, 'undo', array( $this, 'can_edit' ), $id );
		$this->route( '/export', WP_REST_Server::READABLE, 'export', $admin, $this->list_args() );
		$this->route( '/settings', WP_REST_Server::READABLE, 'get_settings', $admin );
		$this->route( '/settings', WP_REST_Server::CREATABLE, 'update_settings', $admin );
		$this->route( '/reset', WP_REST_Server::CREATABLE, 'reset', $admin );

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_csv' ), 10, 4 );
	}

	/**
	 * Permission: manage the plugin.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Permission for undo; UndoService also checks edit_post on the post.
	 *
	 * @return bool
	 */
	public function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * GET /summary: counts by status and scan progress.
	 *
	 * @return WP_REST_Response
	 */
	public function summary() {
		$counts = array_merge(
			array_fill_keys( array( 'broken', 'unverifiable', 'redirect', 'ok', 'pending', 'ignored' ), 0 ),
			( new LinkRepository() )->count_by_status()
		);

		return rest_ensure_response(
			array(
				'counts' => $counts,
				'total'  => array_sum( $counts ),
				'scan'   => $this->format_progress( ( new ScanController() )->progress() ),
			)
		);
	}

	/**
	 * GET /instances: one page of instances, or of URLs with group_by_url.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function instances( WP_REST_Request $request ) {
		$by_url = (bool) $request['group_by_url'];
		$result = ( new ReportQuery() )->query(
			$this->filters( $request ),
			$by_url,
			(int) $request['page'],
			(int) $request['per_page'],
			(string) $request['orderby'],
			(string) $request['order']
		);

		$items    = array_map( array( $this, $by_url ? 'format_link_row' : 'format_instance' ), $result['rows'] );
		$response = rest_ensure_response( $items );

		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $result['total'] / max( 1, (int) $request['per_page'] ) ) ) );

		return $response;
	}

	/**
	 * GET /hosts: hosts for the filter, most frequent first.
	 *
	 * @return WP_REST_Response
	 */
	public function hosts() {
		return rest_ensure_response( ( new ReportQuery() )->hosts() );
	}

	/**
	 * POST /scan: start, pause, resume, cancel, or run one step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function scan( WP_REST_Request $request ) {
		$scan     = new ScanController();
		$action   = (string) $request['action'];
		$progress = $scan->$action();

		AdminPage::forget_count();

		return rest_ensure_response( $this->format_progress( $progress ) );
	}

	/**
	 * POST /links/{id}/recheck: checks a URL now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function recheck( WP_REST_Request $request ) {
		$link = ( new Checker() )->check_now( (int) $request['id'] );

		if ( ! $link ) {
			return $this->link_not_found();
		}

		AdminPage::forget_count();

		return rest_ensure_response( $this->format_link( $link ) );
	}

	/**
	 * POST /links/{id}/ignore: ignores a URL or puts it back in the queue.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ignore( WP_REST_Request $request ) {
		$links = new LinkRepository();

		if ( ! $links->get( (int) $request['id'] ) ) {
			return $this->link_not_found();
		}

		$links->set_ignored( (int) $request['id'], (bool) $request['ignored'] );
		AdminPage::forget_count();

		return rest_ensure_response( $this->format_link( $links->get( (int) $request['id'] ) ) );
	}

	/**
	 * GET /links/{id}/plan: what fixing every instance would change.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function plan( WP_REST_Request $request ) {
		$plan = ( new FixService() )->plan_link( (int) $request['id'], (string) $request['action'] );

		return is_wp_error( $plan ) ? $plan : rest_ensure_response( $plan );
	}

	/**
	 * POST /instances/{id}/fix.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fix_instance( WP_REST_Request $request ) {
		$result = ( new FixService() )->fix_instance( (int) $request['id'], (string) $request['action'], $this->fix_args( $request ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		AdminPage::forget_count();

		return rest_ensure_response( $result );
	}

	/**
	 * POST /instances/fix: a selection of instances (bulk action on a page).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fix_instances( WP_REST_Request $request ) {
		$summary = ( new FixService() )->fix_instances( (array) $request['ids'], (string) $request['action'], $this->fix_args( $request ) );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		AdminPage::forget_count();

		return rest_ensure_response( $summary );
	}

	/**
	 * POST /links/{id}/fix: every instance of a URL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function fix_link( WP_REST_Request $request ) {
		$args             = $this->fix_args( $request );
		$args['expected'] = (int) $request['expected'];
		$summary          = ( new FixService() )->fix_link( (int) $request['id'], (string) $request['action'], $args );

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		AdminPage::forget_count();

		return rest_ensure_response( $summary );
	}

	/**
	 * POST /actions/{id}/undo.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( WP_REST_Request $request ) {
		$result = ( new UndoService() )->undo( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		AdminPage::forget_count();

		return rest_ensure_response( $result );
	}

	/**
	 * GET /export: CSV of the current filter. serve_csv() sends it as is.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export( WP_REST_Request $request ) {
		$query   = new ReportQuery();
		$filters = $this->filters( $request );
		$handle  = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory buffer for fputcsv().
		$page    = 1;
		$written = 0;

		fputcsv( $handle, array( 'URL', 'Status', 'HTTP code', 'Element', 'Link text', 'Post', 'Post type', 'Edit URL', 'Final URL', 'Last checked (UTC)' ), ',', '"', '\\' );

		do {
			$rows = $query->query( $filters, false, $page, 1000, (string) $request['orderby'], (string) $request['order'] )['rows'];

			foreach ( $rows as $row ) {
				$item = $this->format_instance( $row );

				fputcsv(
					$handle,
					array_map(
						array( __CLASS__, 'csv_cell' ),
						array( $item['url'], $item['status'], (string) $item['http_code'], $item['element'], $item['anchor_text'], $item['post_title'], $item['post_type'], (string) $item['edit_url'], (string) $item['final_url'], (string) $item['last_checked_at'] )
					),
					',',
					'"',
					'\\'
				);
				++$written;
			}

			++$page;
			$full = 1000 === count( $rows );
		} while ( $full && $written < self::EXPORT_LIMIT );

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="seekfix-' . gmdate( 'Y-m-d' ) . '.csv"' );

		return $response;
	}

	/**
	 * Sends the export as raw CSV instead of a JSON string.
	 *
	 * @param bool            $served  Whether the request was served.
	 * @param mixed           $result  Response.
	 * @param WP_REST_Request $request Request.
	 * @param WP_REST_Server  $server  Server.
	 * @return bool
	 */
	public function serve_csv( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Filter signature.
		if ( $served || '/' . self::NAMESPACE . '/export' !== $request->get_route() || ! $result instanceof WP_REST_Response || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}

		echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body; cells are neutralized by csv_cell().

		return true;
	}

	/**
	 * Neutralizes a CSV cell that a spreadsheet would run as a formula.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * GET /settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( Settings::for_editing() );
	}

	/**
	 * POST /settings: saves the fields sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$input = $request->get_json_params();

		return rest_ensure_response( Settings::update( is_array( $input ) ? $input : array() ) );
	}

	/**
	 * POST /reset: deletes results, history and scan state.
	 *
	 * @return WP_REST_Response
	 */
	public function reset() {
		Installer::reset_data();

		return rest_ensure_response( array( 'reset' => true ) );
	}

	/**
	 * Formats an instance row.
	 *
	 * @param object $row Row from ReportQuery.
	 * @return array<string, mixed>
	 */
	public function format_instance( $row ) {
		$type    = get_post_type_object( $row->post_type );
		$item    = $this->format_link( $row );
		$actions = FixService::ELEMENT_ACTIONS[ isset( FixService::ELEMENT_ACTIONS[ $row->element ] ) ? $row->element : 'link' ];

		return array_merge(
			$item,
			array(
				'id'              => (int) $row->id,
				'link_id'         => (int) $row->link_id,
				'post_id'         => (int) $row->post_id,
				'element'         => (string) $row->element,
				'shortcode_tag'   => $row->shortcode_tag,
				'anchor_text'     => (string) $row->anchor_text,
				'post_title'      => '' === (string) $row->post_title ? sprintf( '#%d', $row->post_id ) : html_entity_decode( (string) $row->post_title, ENT_QUOTES, 'UTF-8' ),
				'post_type'       => (string) $row->post_type,
				'post_type_label' => $type ? $type->labels->singular_name : (string) $row->post_type,
				'edit_url'        => get_edit_post_link( (int) $row->post_id, 'raw' ),
				'view_url'        => get_permalink( (int) $row->post_id ),
				'actions'         => $this->available( $actions, $item ),
			)
		);
	}

	/**
	 * Formats a row grouped by URL.
	 *
	 * @param object $row Row from ReportQuery.
	 * @return array<string, mixed>
	 */
	public function format_link_row( $row ) {
		$item     = $this->format_link( $row );
		$elements = array_filter( explode( ',', (string) $row->elements ) );
		$actions  = array();

		foreach ( $elements as $element ) {
			$actions = array_merge( $actions, FixService::ELEMENT_ACTIONS[ isset( FixService::ELEMENT_ACTIONS[ $element ] ) ? $element : 'link' ] );
		}

		return array_merge(
			$item,
			array(
				'id'        => (int) $row->link_id,
				'link_id'   => (int) $row->link_id,
				'posts'     => (int) $row->posts,
				'instances' => (int) $row->instances,
				'elements'  => array_values( $elements ),
				'actions'   => $this->available( array_values( array_unique( $actions ) ), $item ),
			)
		);
	}

	/**
	 * Formats the URL columns shared by every row.
	 *
	 * @param object $row Row with seekfix_links columns.
	 * @return array<string, mixed>
	 */
	private function format_link( $row ) {
		$chain = empty( $row->redirect_chain ) ? array() : json_decode( (string) $row->redirect_chain, true );

		return array(
			'link_id'         => isset( $row->link_id ) ? (int) $row->link_id : (int) $row->id,
			'url'             => (string) $row->url,
			'host'            => (string) $row->host,
			'is_internal'     => (bool) $row->is_internal,
			'status'          => (string) $row->status,
			'http_code'       => null === $row->http_code ? null : (int) $row->http_code,
			'error_code'      => $row->error_code,
			'ssl_invalid'     => (bool) $row->ssl_invalid,
			'final_url'       => $row->final_url,
			'redirect_chain'  => is_array( $chain ) ? $chain : array(),
			'last_checked_at' => empty( $row->last_checked_at ) ? null : mysql_to_rfc3339( $row->last_checked_at ),
			'ignored'         => (bool) $row->ignored,
		);
	}

	/**
	 * Actions the UI may offer for a row.
	 *
	 * @param string[]             $actions Actions the element supports.
	 * @param array<string, mixed> $link    Formatted URL columns.
	 * @return string[]
	 */
	private function available( array $actions, array $link ) {
		return array_values(
			array_filter(
				$actions,
				static function ( $action ) use ( $link ) {
					return FixService::USE_FINAL_URL !== $action || ( 'redirect' === $link['status'] && ! empty( $link['final_url'] ) );
				}
			)
		);
	}

	/**
	 * Progress with dates in RFC 3339.
	 *
	 * @param array<string, mixed> $progress From ScanController.
	 * @return array<string, mixed>
	 */
	private function format_progress( array $progress ) {
		foreach ( array( 'started_at', 'last_finished_at' ) as $key ) {
			$progress[ $key ] = empty( $progress[ $key ] ) ? null : mysql_to_rfc3339( $progress[ $key ] );
		}

		return $progress;
	}

	/**
	 * Filters from a list request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function filters( WP_REST_Request $request ) {
		return array(
			'status'    => $request['status'],
			'post_type' => $request['post_type'],
			'element'   => $request['element'],
			'host'      => $request['host'],
			'internal'  => $request['internal'],
			'search'    => $request['search'],
		);
	}

	/**
	 * Arguments of the list and export routes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function list_args() {
		$list = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			'page'         => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
			'per_page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
				'default' => 20,
			),
			'status'       => $list + array( 'default' => array( 'broken' ) ),
			'post_type'    => $list,
			'element'      => $list,
			'host'         => array( 'type' => 'string' ),
			'internal'     => array(
				'type' => 'string',
				'enum' => array( '', 'internal', 'external' ),
			),
			'search'       => array( 'type' => 'string' ),
			'group_by_url' => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'orderby'      => array(
				'type'    => 'string',
				'default' => 'url',
			),
			'order'        => array(
				'type'    => 'string',
				'enum'    => array( 'asc', 'desc' ),
				'default' => 'asc',
			),
		);
	}

	/**
	 * Fix arguments from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string, mixed>
	 */
	private function fix_args( WP_REST_Request $request ) {
		return array(
			'new_url' => (string) $request['new_url'],
			'force'   => (bool) $request['force'],
		);
	}

	/**
	 * Error for an unknown URL row.
	 *
	 * @return WP_Error
	 */
	private function link_not_found() {
		return new WP_Error( 'seekfix_link_not_in_list', __( 'This URL is no longer in the SeekFix list.', 'seekfix' ), array( 'status' => 404 ) );
	}

	/**
	 * Registers one route.
	 *
	 * @param string   $path       Path.
	 * @param string   $methods    Methods.
	 * @param string   $callback   Method name on this class.
	 * @param callable $permission Permission callback.
	 * @param array    $args       Arguments.
	 */
	private function route( $path, $methods, $callback, $permission, array $args = array() ) {
		register_rest_route(
			self::NAMESPACE,
			$path,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => $permission,
				'args'                => $args,
			)
		);
	}
}
