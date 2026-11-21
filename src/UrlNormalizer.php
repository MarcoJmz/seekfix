<?php
/**
 * URL resolution and normalization.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Turns a raw href or src into the normalized absolute URL that is checked.
 *
 * One normalized URL is checked once, however many times it appears, so two
 * spellings of the same address must normalize to the same string.
 */
class UrlNormalizer {

	/**
	 * Schemes that are never checked.
	 */
	const SKIPPED_SCHEMES = array( 'mailto', 'tel', 'sms', 'javascript', 'data' );

	/**
	 * Lowercase host of the site, for is_internal().
	 *
	 * @var string
	 */
	private $site_host;

	/**
	 * Creates a normalizer.
	 *
	 * @param string|null $home_url Site URL; defaults to home_url().
	 */
	public function __construct( $home_url = null ) {
		$host            = wp_parse_url( null === $home_url ? home_url() : $home_url, PHP_URL_HOST );
		$this->site_host = is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Resolves and normalizes a raw URL.
	 *
	 * Relative URLs (`/path`, `path`, `../path`, `//host/path`, `?query`) are
	 * resolved against the base URL, usually the post permalink. Scheme and
	 * host are lowercased, the fragment is removed, an empty path becomes `/`,
	 * and the query string is kept as is.
	 *
	 * @param string $raw      URL as found in the content (entities already decoded).
	 * @param string $base_url Absolute URL that relative URLs resolve against.
	 * @return string|null Normalized http(s) URL, or null when the URL is not checked
	 *                     (empty, fragment only, mailto:, tel:, other schemes).
	 */
	public function normalize( $raw, $base_url ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw || '#' === $raw[0] ) {
			return null;
		}

		$scheme = $this->scheme_of( $raw );

		if ( null !== $scheme && in_array( $scheme, self::SKIPPED_SCHEMES, true ) ) {
			return null;
		}

		if ( null === $scheme ) {
			$raw = \WP_Http::make_absolute_url( $raw, $base_url );
		}

		$parts = wp_parse_url( $raw );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$url = $scheme . '://';

		if ( isset( $parts['user'] ) ) {
			$url .= $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@';
		}

		$url .= strtolower( $parts['host'] );

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}

		$url .= isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$url .= '?' . $parts['query'];
		}

		return $url;
	}

	/**
	 * Hash used as the unique key of a normalized URL.
	 *
	 * @param string $url Normalized URL.
	 * @return string 40-character SHA-1.
	 */
	public static function hash( $url ) {
		return sha1( $url );
	}

	/**
	 * Lowercase host of a normalized URL.
	 *
	 * @param string $url Normalized URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Whether a normalized URL points to this site (same host as home_url()).
	 *
	 * @param string $url Normalized URL.
	 * @return bool
	 */
	public function is_internal( $url ) {
		return '' !== $this->site_host && self::host( $url ) === $this->site_host;
	}

	/**
	 * Returns the lowercase scheme of a URL, or null when it has none.
	 *
	 * Browsers ignore tabs and newlines inside a scheme (`java\tscript:`), so
	 * they are removed before reading it.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private function scheme_of( $url ) {
		$compact = preg_replace( '/[\x00-\x20]+/', '', $url );

		if ( preg_match( '/^([a-z][a-z0-9+.\-]*):/i', $compact, $match ) ) {
			return strtolower( $match[1] );
		}

		return null;
	}
}
