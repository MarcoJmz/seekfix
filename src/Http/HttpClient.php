<?php
/**
 * HTTP client contract used by the Checker.
 *
 * @package SeekFix
 */

namespace SeekFix\Http;

/**
 * Sends several requests at once and never follows redirects.
 *
 * Each request is an array with:
 * - `url`:        absolute URL.
 * - `method`:     "HEAD" or "GET".
 * - `user_agent`: User-Agent header.
 * - `verify_ssl`: whether to verify the certificate.
 *
 * Each response is an array with:
 * - `code`:    HTTP status, or 0 when there was no response.
 * - `headers`: lowercase header names to string values.
 * - `body`:    up to 64 KB of the body (GET only).
 * - `error`:   null, or "dns", "timeout", "ssl", "connection", "private_host".
 */
interface HttpClient {

	/**
	 * Sends requests in parallel.
	 *
	 * @param array<int|string, array<string, mixed>> $requests Requests, keyed by any ID.
	 * @return array<int|string, array<string, mixed>> Responses with the same keys.
	 */
	public function send( array $requests );
}
