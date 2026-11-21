<?php
/**
 * Per-domain check rules.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Hosts that are always unverifiable, and hosts that are excluded entirely.
 *
 * A rule for `example.com` also covers its subdomains (`www.example.com`).
 * The preloaded list is empty until the 500-URL corpus shows which hosts
 * block checks; in the competitor test LinkedIn and cloudflare.com answered
 * 200 and Facebook answered 400.
 */
class HostRules {

	const UNVERIFIABLE = 'unverifiable';
	const EXCLUDE      = 'exclude';

	/**
	 * Rules by kind: lists of lowercase hosts.
	 *
	 * @var array<string, string[]>
	 */
	private $rules;

	/**
	 * Creates the rules from settings, the preloaded list and the filter.
	 *
	 * @param array<string, string[]>|null $rules Rules to use instead of settings (for tests).
	 */
	public function __construct( $rules = null ) {
		if ( null === $rules ) {
			$settings = Settings::all();
			$saved    = isset( $settings['host_rules'] ) && is_array( $settings['host_rules'] ) ? $settings['host_rules'] : array();
			$rules    = array(
				self::UNVERIFIABLE => isset( $saved[ self::UNVERIFIABLE ] ) ? (array) $saved[ self::UNVERIFIABLE ] : array(),
				self::EXCLUDE      => isset( $saved[ self::EXCLUDE ] ) ? (array) $saved[ self::EXCLUDE ] : array(),
			);
		}

		/**
		 * Filters the per-domain rules.
		 *
		 * @param array<string, string[]> $rules Hosts by rule: "unverifiable" and "exclude".
		 */
		$rules = (array) apply_filters( 'seekfix_host_rules', $rules );

		$this->rules = array();

		foreach ( array( self::UNVERIFIABLE, self::EXCLUDE ) as $kind ) {
			$hosts = isset( $rules[ $kind ] ) ? (array) $rules[ $kind ] : array();

			$this->rules[ $kind ] = array_values( array_filter( array_map( array( __CLASS__, 'clean_host' ), $hosts ) ) );
		}
	}

	/**
	 * Returns the rule that applies to a host, or null.
	 *
	 * Exclusion wins over "always unverifiable".
	 *
	 * @param string $host Lowercase host.
	 * @return string|null EXCLUDE, UNVERIFIABLE or null.
	 */
	public function rule_for( $host ) {
		$host = strtolower( $host );

		foreach ( array( self::EXCLUDE, self::UNVERIFIABLE ) as $kind ) {
			foreach ( $this->rules[ $kind ] as $rule ) {
				if ( $host === $rule || substr( $host, -strlen( $rule ) - 1 ) === '.' . $rule ) {
					return $kind;
				}
			}
		}

		return null;
	}

	/**
	 * Normalizes a host typed by the admin: no scheme, path, port or leading dot.
	 *
	 * @param mixed $host Host or URL.
	 * @return string
	 */
	public static function clean_host( $host ) {
		$host = strtolower( trim( (string) $host ) );

		if ( false !== strpos( $host, '/' ) ) {
			$parsed = wp_parse_url( false === strpos( $host, '://' ) ? 'http://' . $host : $host, PHP_URL_HOST );
			$host   = is_string( $parsed ) ? $parsed : '';
		}

		$host = preg_replace( '/:\d+$/', '', $host );

		return ltrim( (string) $host, '.' );
	}
}
