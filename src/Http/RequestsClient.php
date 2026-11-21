<?php
/**
 * HttpClient implementation on the Requests library bundled with WordPress.
 *
 * @package SeekFix
 */

namespace SeekFix\Http;

/**
 * Parallel requests with WordPress's SSRF protection applied to every URL.
 *
 * The wp_safe_remote_*() functions cannot run requests in parallel, so this client calls
 * Requests::request_multiple() directly and applies the same check first:
 * wp_http_validate_url() rejects private and local IPs unless the host is the
 * site itself.
 */
class RequestsClient implements HttpClient {

	/**
	 * Request timeout in seconds.
	 */
	const TIMEOUT = 10;

	/**
	 * Maximum body bytes read from a GET response.
	 */
	const MAX_BYTES = 65536;

	/**
	 * IPv4 ranges never requested, besides PHP's private and reserved ranges.
	 *
	 * Listed explicitly because PHP's filter flags differ between versions.
	 */
	const BLOCKED_IPV4 = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',   // Shared address space (CGNAT); some clouds serve metadata here.
		'127.0.0.0/8',
		'169.254.0.0/16',  // Link-local, including cloud metadata at 169.254.169.254.
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'224.0.0.0/3',     // Multicast and reserved.
	);

	/**
	 * Sends requests in parallel.
	 *
	 * @param array<int|string, array<string, mixed>> $requests Requests, keyed by any ID.
	 * @return array<int|string, array<string, mixed>> Responses with the same keys.
	 */
	public function send( array $requests ) {
		$responses = array();
		$outgoing  = array();

		foreach ( $requests as $key => $request ) {
			$error = self::validation_error( $request['url'] );

			if ( null !== $error ) {
				$responses[ $key ] = self::error_response( $error );
				continue;
			}

			$outgoing[ $key ] = array(
				'url'     => $request['url'],
				'type'    => 'GET' === $request['method'] ? 'GET' : 'HEAD',
				'headers' => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
				'options' => array(
					'useragent'        => $request['user_agent'],
					'timeout'          => self::TIMEOUT,
					'connect_timeout'  => self::TIMEOUT,
					'follow_redirects' => false,
					'verify'           => empty( $request['verify_ssl'] ) ? false : ABSPATH . WPINC . '/certificates/ca-bundle.crt',
					'max_bytes'        => self::MAX_BYTES,
				),
			);
		}

		if ( empty( $outgoing ) ) {
			return $responses;
		}

		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : '\Requests';

		foreach ( $class::request_multiple( $outgoing ) as $key => $result ) {
			$responses[ $key ] = $this->to_response( $result );
		}

		return $responses;
	}

	/**
	 * Checks a URL before sending it: SSRF protection and DNS.
	 *
	 * Uses wp_http_validate_url(), which refuses private and local IPs. That
	 * function also refuses every port except 80, 443 and 8080; public sites on
	 * other ports are legitimate links, so the URL's own port is allowed for
	 * this one check. Private and local addresses stay refused on any port.
	 *
	 * @param string $url URL.
	 * @return string|null Error code ("dns" or "private_host"), or null when the URL may be requested.
	 */
	public static function validation_error( $url ) {
		/**
		 * Filters whether URLs on private or local networks may be checked.
		 *
		 * @param bool $allow Default false.
		 */
		if ( apply_filters( 'seekfix_allow_internal_hosts', false ) ) {
			return null;
		}

		$port       = wp_parse_url( $url, PHP_URL_PORT );
		$allow_port = static function ( $ports ) use ( $port ) {
			$ports[] = (int) $port;
			return $ports;
		};

		if ( $port ) {
			add_filter( 'http_allowed_safe_ports', $allow_port );
		}

		$valid = wp_http_validate_url( $url );

		if ( $port ) {
			remove_filter( 'http_allowed_safe_ports', $allow_port );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $valid ) {
			return self::is_public_or_site_host( (string) $host ) ? null : 'private_host';
		}

		if ( is_string( $host ) && ! filter_var( $host, FILTER_VALIDATE_IP ) && gethostbyname( $host ) === $host ) {
			return 'dns';
		}

		return 'private_host';
	}

	/**
	 * Whether a host is this site or resolves to a public IP address.
	 *
	 * A second check after wp_http_validate_url(), which in older WordPress
	 * versions lets link-local (169.254.0.0/16, cloud metadata) and reserved
	 * ranges through. The site's own host is allowed even when it resolves to a
	 * local address, as it does on development machines.
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_public_or_site_host( $host ) {
		$host      = strtolower( trim( $host, '[]' ) );
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		if ( '' !== $host && $host === $site_host ) {
			return true;
		}

		$ip = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );

			foreach ( self::BLOCKED_IPV4 as $range ) {
				list( $subnet, $bits ) = explode( '/', $range );
				$mask                  = -1 << ( 32 - (int) $bits );

				if ( ( $long & $mask ) === ( ip2long( $subnet ) & $mask ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Converts a Requests result (response or exception) into a response array.
	 *
	 * @param mixed $result Response object or exception.
	 * @return array<string, mixed>
	 */
	private function to_response( $result ) {
		if ( $result instanceof \Exception ) {
			return self::error_response( self::error_code( $result->getMessage() ) );
		}

		$headers = array();

		if ( isset( $result->headers ) ) {
			foreach ( $result->headers->getAll() as $name => $values ) {
				$headers[ strtolower( $name ) ] = implode( ', ', (array) $values );
			}
		}

		return array(
			'code'    => (int) $result->status_code,
			'headers' => $headers,
			'body'    => (string) $result->body,
			'error'   => null,
		);
	}

	/**
	 * Maps a transport error message to an error code.
	 *
	 * @param string $message Error message from cURL or fsockopen.
	 * @return string
	 */
	public static function error_code( $message ) {
		$message = strtolower( $message );

		if ( preg_match( '/curl error (6|7):|could not resolve|name or service not known|getaddrinfo|no such host/', $message ) ) {
			return false !== strpos( $message, 'curl error 7:' ) ? 'connection' : 'dns';
		}

		if ( preg_match( '/curl error 28:|timed out|timeout/', $message ) ) {
			return 'timeout';
		}

		if ( preg_match( '/curl error (35|51|58|60|77):|ssl|certificate|tls/', $message ) ) {
			return 'ssl';
		}

		return 'connection';
	}

	/**
	 * A response that carries only an error.
	 *
	 * @param string $error Error code.
	 * @return array<string, mixed>
	 */
	private static function error_response( $error ) {
		return array(
			'code'    => 0,
			'headers' => array(),
			'body'    => '',
			'error'   => $error,
		);
	}
}
