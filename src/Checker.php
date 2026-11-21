<?php
/**
 * Link checking.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\LinkRepository;
use SeekFix\Http\HttpClient;
use SeekFix\Http\RequestsClient;

/**
 * Checks the URLs whose next_check_at is due and saves their new status.
 *
 * Internal links are resolved from the database first. Everything else goes
 * over HTTP: HEAD first, GET when HEAD is refused or fails, redirects followed
 * by hand so the full chain is kept, and a retry without SSL verification when
 * the certificate is invalid. ResponseClassifier turns the result into a
 * status.
 */
class Checker {

	/**
	 * URLs per batch.
	 */
	const BATCH_SIZE = 25;

	/**
	 * Time budget per batch, in seconds.
	 */
	const BUDGET = 20;

	/**
	 * Requests in flight at once.
	 */
	const PARALLEL = 4;

	/**
	 * Minimum seconds between two requests to the same host.
	 */
	const HOST_INTERVAL = 1.0;

	/**
	 * Redirects followed before giving up.
	 */
	const MAX_REDIRECTS = 5;

	/**
	 * Share of memory_limit at which a batch stops.
	 */
	const MEMORY_SHARE = 0.8;

	/**
	 * HEAD answers that are retried with GET (many servers refuse HEAD).
	 */
	const RETRY_WITH_GET = array( 403, 404, 405, 429 );

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	private $client;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Internal link resolver.
	 *
	 * @var InternalResolver
	 */
	private $resolver;

	/**
	 * Per-domain rules.
	 *
	 * @var HostRules|null
	 */
	private $host_rules;

	/**
	 * Returns the current time as a float (Unix seconds).
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Waits a number of seconds.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Creates the checker. Every dependency can be replaced in tests.
	 *
	 * @param HttpClient|null       $client     HTTP client.
	 * @param LinkRepository|null   $links      URL rows.
	 * @param InternalResolver|null $resolver   Internal link resolver.
	 * @param HostRules|null        $host_rules Per-domain rules; read from settings when null.
	 * @param callable|null         $clock      Returns the time as a float.
	 * @param callable|null         $sleep      Waits a number of seconds.
	 */
	public function __construct( $client = null, $links = null, $resolver = null, $host_rules = null, $clock = null, $sleep = null ) {
		$this->client     = $client ? $client : new RequestsClient();
		$this->links      = $links ? $links : new LinkRepository();
		$this->resolver   = $resolver ? $resolver : new InternalResolver();
		$this->host_rules = $host_rules;
		$this->clock      = $clock ? $clock : 'microtime';
		$this->sleep      = $sleep ? $sleep : array( __CLASS__, 'wait' );
	}

	/**
	 * Checks the next batch of due URLs.
	 *
	 * URLs left unfinished when the budget runs out stay due for the next batch.
	 *
	 * @param int $limit  URLs per batch.
	 * @param int $budget Seconds available.
	 * @return int Number of URLs whose status was saved.
	 */
	public function run_batch( $limit = self::BATCH_SIZE, $budget = self::BUDGET ) {
		$now = $this->now();

		return $this->check_links( $this->links->due( gmdate( 'Y-m-d H:i:s', (int) $now ), $limit ), $now + $budget );
	}

	/**
	 * Checks one URL now ("Check again now"). Same rules as a scheduled check.
	 *
	 * @param int $link_id Row ID in seekfix_links.
	 * @return object|null The updated row, or null when it does not exist.
	 */
	public function check_now( $link_id ) {
		$link = $this->links->get( $link_id );

		if ( ! $link ) {
			return null;
		}

		$this->check_links( array( $link ), $this->now() + self::BUDGET );

		return $this->links->get( $link_id );
	}

	/**
	 * Checks a list of link rows until done or out of time.
	 *
	 * @param object[] $links    Rows of seekfix_links.
	 * @param float    $deadline Unix time to stop at.
	 * @return int Number of rows saved.
	 */
	private function check_links( array $links, $deadline ) {
		$rules = $this->host_rules ? $this->host_rules : new HostRules();
		$saved = 0;
		$jobs  = array();

		foreach ( $links as $link ) {
			$rule = $rules->rule_for( (string) $link->host );

			if ( HostRules::EXCLUDE === $rule ) {
				$this->links->set_ignored( (int) $link->id, true );
				++$saved;
				continue;
			}

			if ( HostRules::UNVERIFIABLE === $rule ) {
				$this->save( $link, ResponseClassifier::KIND_BLOCKED, array( 'error' => 'host_rule' ) );
				++$saved;
				continue;
			}

			if ( ! empty( $link->is_internal ) && $this->resolver->resolves( $link->url ) ) {
				$this->save( $link, ResponseClassifier::KIND_OK, array() );
				++$saved;
				continue;
			}

			$jobs[] = $this->new_job( $link );
		}

		$this->run_jobs( $jobs, $deadline );

		foreach ( $jobs as $job ) {
			if ( null === $job['result'] ) {
				continue;
			}

			$this->save( $job['link'], ResponseClassifier::classify( $job['result'] ), $job['result'] );
			++$saved;
		}

		return $saved;
	}

	/**
	 * Creates the request state for one link.
	 *
	 * The confirmation of a 404, 410 or DNS error goes straight to GET with a
	 * plain browser User-Agent.
	 *
	 * @param object $link Row of seekfix_links.
	 * @return array<string, mixed>
	 */
	private function new_job( $link ) {
		$confirming = ResponseClassifier::is_confirming( $link );

		return array(
			'link'        => $link,
			'url'         => $link->url,
			'method'      => $confirming ? 'GET' : 'HEAD',
			'user_agent'  => $confirming ? self::browser_user_agent() : self::user_agent(),
			'verify_ssl'  => true,
			'ssl_invalid' => false,
			'chain'       => array(),
			'result'      => null,
		);
	}

	/**
	 * Sends the jobs' requests in rounds of up to PARALLEL, one per host per second.
	 *
	 * @param array $jobs     Jobs, by reference.
	 * @param float $deadline Unix time to stop at.
	 */
	private function run_jobs( array &$jobs, $deadline ) {
		$last_hit = array();

		while ( $this->now() < $deadline && ! $this->memory_exhausted() ) {
			$pending = array_keys(
				array_filter(
					$jobs,
					static function ( $job ) {
						return null === $job['result'];
					}
				)
			);

			if ( empty( $pending ) ) {
				return;
			}

			$now      = $this->now();
			$round    = array();
			$hosts    = array();
			$earliest = null;

			foreach ( $pending as $key ) {
				$host  = strtolower( (string) wp_parse_url( $jobs[ $key ]['url'], PHP_URL_HOST ) );
				$ready = isset( $last_hit[ $host ] ) ? $last_hit[ $host ] + self::HOST_INTERVAL : 0;

				if ( isset( $hosts[ $host ] ) ) {
					continue;
				}

				if ( $ready > $now ) {
					$earliest = null === $earliest ? $ready : min( $earliest, $ready );
					continue;
				}

				$hosts[ $host ] = true;
				$round[ $key ]  = array(
					'url'        => $jobs[ $key ]['url'],
					'method'     => $jobs[ $key ]['method'],
					'user_agent' => $jobs[ $key ]['user_agent'],
					'verify_ssl' => $jobs[ $key ]['verify_ssl'],
				);

				if ( count( $round ) >= self::PARALLEL ) {
					break;
				}
			}

			if ( empty( $round ) ) {
				call_user_func( $this->sleep, max( 0.05, ( null === $earliest ? $now + self::HOST_INTERVAL : $earliest ) - $now ) );
				continue;
			}

			$responses = $this->client->send( $round );
			$sent_at   = $this->now();

			foreach ( $round as $key => $request ) {
				$last_hit[ strtolower( (string) wp_parse_url( $request['url'], PHP_URL_HOST ) ) ] = $sent_at;

				$response = isset( $responses[ $key ] ) ? $responses[ $key ] : array(
					'code'  => 0,
					'error' => 'connection',
				);

				$this->advance( $jobs[ $key ], $response );
			}
		}
	}

	/**
	 * Moves a job forward with a response: next hop, retry or final result.
	 *
	 * @param array $job      Job, by reference.
	 * @param array $response Response from the HttpClient.
	 */
	private function advance( array &$job, array $response ) {
		$code    = isset( $response['code'] ) ? (int) $response['code'] : 0;
		$error   = isset( $response['error'] ) ? $response['error'] : null;
		$headers = isset( $response['headers'] ) && is_array( $response['headers'] ) ? $response['headers'] : array();

		// Invalid certificate: retry without verification and flag it.
		if ( 'ssl' === $error && $job['verify_ssl'] ) {
			$job['verify_ssl']  = false;
			$job['ssl_invalid'] = true;
			return;
		}

		if ( $code >= 300 && $code < 400 && ! empty( $headers['location'] ) ) {
			if ( count( $job['chain'] ) >= self::MAX_REDIRECTS ) {
				$this->finish( $job, 0, 'too_many_redirects', $headers, '' );
				return;
			}

			$job['chain'][] = array(
				'url'  => $job['url'],
				'code' => $code,
			);
			$job['url']     = \WP_Http::make_absolute_url( trim( $headers['location'] ), $job['url'] );
			return;
		}

		$retry = 'HEAD' === $job['method'] && (
			in_array( $code, self::RETRY_WITH_GET, true )
			|| $code >= 500
			|| ( null !== $error && ! in_array( $error, array( 'dns', 'private_host' ), true ) )
		);

		if ( $retry ) {
			$job['method'] = 'GET';
			return;
		}

		$this->finish( $job, $code, $error, $headers, isset( $response['body'] ) ? (string) $response['body'] : '' );
	}

	/**
	 * Stores the final result of a job.
	 *
	 * @param array       $job     Job, by reference.
	 * @param int         $code    Final HTTP status.
	 * @param string|null $error   Error code.
	 * @param array       $headers Final response headers.
	 * @param string      $body    Final response body (first 64 KB).
	 */
	private function finish( array &$job, $code, $error, array $headers, $body ) {
		$job['result'] = array(
			'code'        => $code,
			'error'       => $error,
			'chain'       => $job['chain'],
			'final_url'   => empty( $job['chain'] ) ? null : $job['url'],
			'ssl_invalid' => $job['ssl_invalid'],
			'waf'         => self::is_waf_challenge( $code, $headers, $body ),
		);
	}

	/**
	 * Whether a response is a Cloudflare (or similar) bot challenge.
	 *
	 * @param int    $code    HTTP status.
	 * @param array  $headers Lowercase headers.
	 * @param string $body    Body.
	 * @return bool
	 */
	public static function is_waf_challenge( $code, array $headers, $body ) {
		if ( isset( $headers['cf-mitigated'] ) && false !== stripos( $headers['cf-mitigated'], 'challenge' ) ) {
			return true;
		}

		if ( ! in_array( (int) $code, array( 403, 429, 503 ), true ) || '' === $body ) {
			return false;
		}

		$server = isset( $headers['server'] ) ? strtolower( $headers['server'] ) : '';

		if ( false !== strpos( $server, 'cloudflare' ) && preg_match( '/challenge-platform|cf-chl-|<title>\s*just a moment/i', $body ) ) {
			return true;
		}

		// Other WAFs (Sucuri, Imperva, DataDome) serve similar verification pages.
		return (bool) preg_match( '/sucuri website firewall|_incapsula_resource|captcha-delivery\.com/i', $body );
	}

	/**
	 * Saves the outcome of a check.
	 *
	 * @param object $link   Row of seekfix_links.
	 * @param string $kind   ResponseClassifier kind.
	 * @param array  $result Fetch result.
	 */
	private function save( $link, $kind, array $result ) {
		$this->links->save_check( (int) $link->id, ResponseClassifier::apply( $link, $kind, $result, (int) $this->now() ) );
	}

	/**
	 * Whether memory use passed MEMORY_SHARE of memory_limit.
	 *
	 * @return bool
	 */
	private function memory_exhausted() {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		return $limit > 0 && memory_get_usage( true ) > $limit * self::MEMORY_SHARE;
	}

	/**
	 * Current time from the clock.
	 *
	 * @return float
	 */
	private function now() {
		return (float) call_user_func( $this->clock, true );
	}

	/**
	 * User-Agent for regular checks: a common browser plus the plugin's name.
	 *
	 * @return string
	 */
	public static function user_agent() {
		return self::browser_user_agent() . ' SeekFix/' . ( defined( 'SEEKFIX_VERSION' ) ? SEEKFIX_VERSION : '0' ) . ' (+https://seekfix.app)';
	}

	/**
	 * Plain browser User-Agent, used for confirmation retries.
	 *
	 * @return string
	 */
	public static function browser_user_agent() {
		return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
	}

	/**
	 * Default sleep.
	 *
	 * @param float $seconds Seconds.
	 */
	public static function wait( $seconds ) {
		usleep( (int) ( $seconds * 1000000 ) );
	}
}
