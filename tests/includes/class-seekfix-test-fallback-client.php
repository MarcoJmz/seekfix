<?php
/**
 * HttpClient for FixService tests: some URLs work, the rest are 404.
 *
 * @package SeekFix
 */

/**
 * HTTP client that answers 200 for some URLs and 404 for the rest.
 */
class SeekFix_Test_Fallback_Client implements SeekFix\Http\HttpClient {

	/**
	 * URLs that answer 200.
	 *
	 * @var string[]
	 */
	private $working;

	/**
	 * Creates the client.
	 *
	 * @param string[] $working URLs that answer 200.
	 */
	public function __construct( array $working ) {
		$this->working = $working;
	}

	/**
	 * Answers each request.
	 *
	 * @param array $requests Requests.
	 * @return array
	 */
	public function send( array $requests ) {
		$responses = array();

		foreach ( $requests as $key => $request ) {
			$responses[ $key ] = SeekFix_Test_Http_Client::response( in_array( $request['url'], $this->working, true ) ? 200 : 404 );
		}

		return $responses;
	}
}
