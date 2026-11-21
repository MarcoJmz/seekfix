<?php
/**
 * Response classification and the confirmation rule.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Turns an HTTP result into a link status, and decides when a failure is confirmed.
 *
 * Pure logic: no requests and no database. The Checker gathers a result,
 * classify() names what kind of answer it is, and apply() computes the new
 * row for seekfix_links from the old row, that answer and the current time.
 *
 * Kinds of answer:
 * - ok:        2xx with no redirect.
 * - redirect:  2xx reached through one or more redirects.
 * - definite:  404, 410 or a domain that does not exist. Confirmed by one
 *              retry 30–60 s later; internal links are broken at once.
 * - ambiguous: 5xx, timeout, connection error, redirect loop. Broken only if
 *              it persists on 3 different days within 7.
 * - blocked:   any other 4xx, codes above 599 (LinkedIn's 999), a WAF challenge,
 *              a private host or a host rule.
 *              The URL cannot be verified; it never becomes broken from this.
 */
class ResponseClassifier {

	const KIND_OK        = 'ok';
	const KIND_REDIRECT  = 'redirect';
	const KIND_DEFINITE  = 'definite';
	const KIND_AMBIGUOUS = 'ambiguous';
	const KIND_BLOCKED   = 'blocked';

	/**
	 * Seconds before the confirmation retry of a 404, 410 or DNS error.
	 */
	const CONFIRM_DELAY = 45;

	/**
	 * Retry delays after the first and second ambiguous failure; then daily.
	 */
	const AMBIGUOUS_DELAYS = array( 900, 7200 );

	/**
	 * Distinct failing days, within the window, that confirm an ambiguous failure.
	 */
	const AMBIGUOUS_DAYS = 3;

	/**
	 * Window for counting failing days, in seconds (7 days).
	 */
	const AMBIGUOUS_WINDOW = 604800;

	/**
	 * Re-check interval per status, in seconds.
	 */
	const RECHECK = array(
		'ok'           => 1209600, // 14 days.
		'redirect'     => 604800,  // 7 days.
		'unverifiable' => 604800,  // 7 days.
		'broken'       => 259200,  // 3 days, in case it gets fixed.
	);

	/**
	 * Error codes that count as a definite failure.
	 */
	const DEFINITE_ERRORS = array( 'dns' );

	/**
	 * Error codes that mean the URL cannot be verified.
	 */
	const BLOCKED_ERRORS = array( 'waf', 'private_host', 'host_rule' );

	/**
	 * Classifies a fetch result.
	 *
	 * @param array $result Result from the Checker: `code` (int|null), `error`
	 *                      (string|null), `chain` (array of hops), `waf` (bool).
	 * @return string One of the KIND_* constants.
	 */
	public static function classify( array $result ) {
		$code  = isset( $result['code'] ) ? (int) $result['code'] : 0;
		$error = isset( $result['error'] ) ? (string) $result['error'] : '';

		if ( ! empty( $result['waf'] ) || in_array( $error, self::BLOCKED_ERRORS, true ) ) {
			return self::KIND_BLOCKED;
		}

		if ( in_array( $error, self::DEFINITE_ERRORS, true ) ) {
			return self::KIND_DEFINITE;
		}

		if ( '' !== $error || 0 === $code ) {
			return self::KIND_AMBIGUOUS;
		}

		if ( $code >= 200 && $code < 300 ) {
			return empty( $result['chain'] ) ? self::KIND_OK : self::KIND_REDIRECT;
		}

		if ( 404 === $code || 410 === $code ) {
			return self::KIND_DEFINITE;
		}

		// Other 4xx, and non-standard codes above 599 such as LinkedIn's 999: bot blocking.
		if ( ( $code >= 400 && $code < 500 ) || $code > 599 ) {
			return self::KIND_BLOCKED;
		}

		// 5xx (including Cloudflare's 520–527), and 1xx/3xx left over.
		return self::KIND_AMBIGUOUS;
	}

	/**
	 * Computes the new state of a link row after a check.
	 *
	 * @param object|array $link   Current row of seekfix_links.
	 * @param string       $kind   Kind from classify().
	 * @param array        $result Fetch result (`code`, `error`, `chain`, `final_url`, `ssl_invalid`).
	 * @param int          $now    Current Unix time.
	 * @return array<string, mixed> Columns to save.
	 */
	public static function apply( $link, $kind, array $result, $now ) {
		$link        = (object) $link;
		$status      = (string) $link->status;
		$fail_count  = (int) $link->fail_count;
		$fail_days   = isset( $link->fail_days ) ? (int) $link->fail_days : 0;
		$first_fail  = empty( $link->first_failure_at ) ? null : strtotime( $link->first_failure_at . ' UTC' );
		$last_check  = empty( $link->last_checked_at ) ? null : strtotime( $link->last_checked_at . ' UTC' );
		$is_internal = ! empty( $link->is_internal );
		$code        = isset( $result['code'] ) && $result['code'] ? (int) $result['code'] : null;
		$error       = isset( $result['error'] ) && '' !== $result['error'] ? (string) $result['error'] : null;

		$row = array(
			'http_code'       => $code,
			'error_code'      => $error,
			'ssl_invalid'     => empty( $result['ssl_invalid'] ) ? 0 : 1,
			'last_checked_at' => gmdate( 'Y-m-d H:i:s', $now ),
		);

		switch ( $kind ) {
			case self::KIND_OK:
			case self::KIND_REDIRECT:
				$new_status = self::KIND_OK === $kind ? 'ok' : 'redirect';

				return $row + array(
					'status'           => $new_status,
					'final_url'        => self::KIND_REDIRECT === $kind && isset( $result['final_url'] ) ? $result['final_url'] : null,
					'redirect_chain'   => self::KIND_REDIRECT === $kind ? wp_json_encode( array_slice( $result['chain'], 0, 10 ) ) : null,
					'fail_count'       => 0,
					'fail_days'        => 0,
					'first_failure_at' => null,
					'next_check_at'    => self::at( $now + self::RECHECK[ $new_status ] ),
				);

			case self::KIND_BLOCKED:
				// "Cannot verify" never proves a broken link works again.
				$new_status = 'broken' === $status ? 'broken' : 'unverifiable';

				return $row + array(
					'status'           => $new_status,
					'fail_count'       => 0,
					'fail_days'        => 0,
					'first_failure_at' => null,
					'next_check_at'    => self::at( $now + self::RECHECK[ $new_status ] ),
				);

			case self::KIND_DEFINITE:
				// Confirmed when internal, already broken, or the previous check was also definite.
				$confirmed = $is_internal || 'broken' === $status || self::is_confirming( $link );

				if ( $confirmed ) {
					return $row + array(
						'status'           => 'broken',
						'fail_count'       => min( 255, $fail_count + 1 ),
						'first_failure_at' => self::at( null === $first_fail ? $now : $first_fail ),
						'next_check_at'    => self::at( $now + self::RECHECK['broken'] ),
					);
				}

				// First sighting: keep the current status and confirm shortly.
				return $row + array(
					'status'           => $status,
					'fail_count'       => 1,
					'first_failure_at' => self::at( $now ),
					'next_check_at'    => self::at( $now + self::CONFIRM_DELAY ),
				);

			case self::KIND_AMBIGUOUS:
			default:
				$window_open = null !== $first_fail && $fail_count > 0 && ( $now - $first_fail ) <= self::AMBIGUOUS_WINDOW;

				if ( ! $window_open ) {
					$first_fail = $now;
					$fail_days  = 1;
				} elseif ( null === $last_check || gmdate( 'Y-m-d', $last_check ) !== gmdate( 'Y-m-d', $now ) ) {
					++$fail_days;
				}

				$fail_count = min( 255, $window_open ? $fail_count + 1 : 1 );

				if ( 'broken' === $status || $fail_days >= self::AMBIGUOUS_DAYS ) {
					return $row + array(
						'status'           => 'broken',
						'fail_count'       => $fail_count,
						'fail_days'        => min( 255, $fail_days ),
						'first_failure_at' => self::at( $first_fail ),
						'next_check_at'    => self::at( $now + self::RECHECK['broken'] ),
					);
				}

				$delay = isset( self::AMBIGUOUS_DELAYS[ $fail_count - 1 ] ) ? self::AMBIGUOUS_DELAYS[ $fail_count - 1 ] : DAY_IN_SECONDS;

				return $row + array(
					'status'           => 'unverifiable',
					'fail_count'       => $fail_count,
					'fail_days'        => min( 255, $fail_days ),
					'first_failure_at' => self::at( $first_fail ),
					'next_check_at'    => self::at( $now + $delay ),
				);
		}
	}

	/**
	 * Whether the next check of a link is the confirmation of a 404, 410 or DNS error.
	 *
	 * Confirmation retries use GET and a different User-Agent.
	 *
	 * @param object|array $link Row of seekfix_links.
	 * @return bool
	 */
	public static function is_confirming( $link ) {
		$link = (object) $link;

		if ( (int) $link->fail_count < 1 || 'broken' === $link->status ) {
			return false;
		}

		return in_array( (int) $link->http_code, array( 404, 410 ), true ) || in_array( (string) $link->error_code, self::DEFINITE_ERRORS, true );
	}

	/**
	 * Formats a Unix time as a UTC MySQL datetime.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function at( $time ) {
		return gmdate( 'Y-m-d H:i:s', (int) $time );
	}
}
