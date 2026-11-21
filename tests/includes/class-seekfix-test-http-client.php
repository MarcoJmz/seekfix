<?php
/**
 * Scripted HttpClient for tests.
 *
 * @package SeekFix
 */

use SeekFix\Http\HttpClient;

/**
 * Answers requests from a script and records every request it receives.
 */
class SeekFix_Test_Http_Client implements HttpClient {

	/**
	 * Responses by "METHOD url", or by "url" for any method. A list is consumed in order.
	 *
	 * @var array<string, array|array[]>
	 */
	private $script;

	/**
	 * Every request sent, in order, with the round it was part of.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $requests = array();

	/**
	 * Number of send() calls.
	 *
	 * @var int
	 */
	public $rounds = 0;

	/**
	 * Optional clock advanced on every round, to simulate request time.
	 *
	 * @var SeekFix_Test_Clock|null
	 */
	private $clock;

	/**
	 * Creates the client.
	 *
	 * @param array                   $script Responses (see $script).
	 * @param SeekFix_Test_Clock|null $clock  Clock to advance by 0.2 s per round.
	 */
	public function __construct( array $script, $clock = null ) {
		$this->script = $script;
		$this->clock  = $clock;
	}

	/**
	 * Builds a response array.
	 *
	 * @param int    $code    HTTP status.
	 * @param array  $headers Lowercase headers.
	 * @param string $body    Body.
	 * @return array<string, mixed>
	 */
	public static function response( $code, array $headers = array(), $body = '' ) {
		return array(
			'code'    => $code,
			'headers' => $headers,
			'body'    => $body,
			'error'   => null,
		);
	}

	/**
	 * Builds an error response.
	 *
	 * @param string $error Error code.
	 * @return array<string, mixed>
	 */
	public static function error( $error ) {
		return array(
			'code'    => 0,
			'headers' => array(),
			'body'    => '',
			'error'   => $error,
		);
	}

	/**
	 * Sends requests: looks each one up in the script.
	 *
	 * @param array $requests Requests.
	 * @return array Responses.
	 */
	public function send( array $requests ) {
		++$this->rounds;

		if ( $this->clock ) {
			$this->clock->advance( 0.2 );
		}

		$responses = array();

		foreach ( $requests as $key => $request ) {
			$this->requests[]  = $request + array( 'round' => $this->rounds );
			$responses[ $key ] = $this->next_response( $request );
		}

		return $responses;
	}

	/**
	 * Methods and URLs sent, as "METHOD url" strings.
	 *
	 * @return string[]
	 */
	public function sent() {
		return array_map(
			static function ( $request ) {
				return $request['method'] . ' ' . $request['url'];
			},
			$this->requests
		);
	}

	/**
	 * Finds the scripted response for a request; unscripted URLs answer 200.
	 *
	 * @param array $request Request.
	 * @return array
	 */
	private function next_response( array $request ) {
		foreach ( array( $request['method'] . ' ' . $request['url'], $request['url'] ) as $key ) {
			if ( ! isset( $this->script[ $key ] ) ) {
				continue;
			}

			$entry = $this->script[ $key ];

			if ( isset( $entry[0] ) && is_array( $entry[0] ) ) {
				$response = count( $entry ) > 1 ? array_shift( $this->script[ $key ] ) : $entry[0];
				return $response;
			}

			return $entry;
		}

		return self::response( 200 );
	}
}
