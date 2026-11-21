<?php
/**
 * Tests for ResponseClassifier: one per row of the classification table and
 * one per step of the confirmation rule.
 *
 * @package SeekFix
 */

use SeekFix\ResponseClassifier as RC;

/**
 * Classification and confirmation tests.
 */
class ResponseClassifierTest extends WP_UnitTestCase {

	/**
	 * A fixed "now": 2026-11-20 12:00:00 UTC.
	 */
	const NOW = 1795176000;

	/**
	 * A link row as stored, with overrides.
	 *
	 * @param array $overrides Column values.
	 * @return object
	 */
	private function link( array $overrides = array() ) {
		return (object) array_merge(
			array(
				'status'           => 'pending',
				'is_internal'      => 0,
				'http_code'        => null,
				'error_code'       => null,
				'fail_count'       => 0,
				'fail_days'        => 0,
				'first_failure_at' => null,
				'last_checked_at'  => null,
			),
			$overrides
		);
	}

	/**
	 * Formats a time as stored.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function stamp( $time ) {
		return gmdate( 'Y-m-d H:i:s', $time );
	}

	/**
	 * Applies a fetch result to a link and returns the new columns.
	 *
	 * @param object $link   Link row.
	 * @param array  $result Fetch result.
	 * @param int    $now    Time.
	 * @return array
	 */
	private function check( $link, array $result, $now = self::NOW ) {
		return RC::apply( $link, RC::classify( $result ), $result, $now );
	}

	/*
	 * Classification table.
	 */

	/**
	 * Each row of the table maps to its kind.
	 *
	 * @return array
	 */
	public function data_table() {
		return array(
			'2xx'                  => array( array( 'code' => 200 ), RC::KIND_OK ),
			'204'                  => array( array( 'code' => 204 ), RC::KIND_OK ),
			'3xx to a 2xx'         => array(
				array(
					'code'  => 200,
					'chain' => array(
						array(
							'url'  => 'http://a.test/',
							'code' => 301,
						),
					),
				),
				RC::KIND_REDIRECT,
			),
			'404'                  => array( array( 'code' => 404 ), RC::KIND_DEFINITE ),
			'410'                  => array( array( 'code' => 410 ), RC::KIND_DEFINITE ),
			'DNS error'            => array( array( 'error' => 'dns' ), RC::KIND_DEFINITE ),
			'400 (Facebook)'       => array( array( 'code' => 400 ), RC::KIND_BLOCKED ),
			'401'                  => array( array( 'code' => 401 ), RC::KIND_BLOCKED ),
			'403'                  => array( array( 'code' => 403 ), RC::KIND_BLOCKED ),
			'429'                  => array( array( 'code' => 429 ), RC::KIND_BLOCKED ),
			'999 (LinkedIn)'       => array( array( 'code' => 999 ), RC::KIND_BLOCKED ),
			'Cloudflare challenge' => array(
				array(
					'code' => 403,
					'waf'  => true,
				),
				RC::KIND_BLOCKED,
			),
			'private host'         => array( array( 'error' => 'private_host' ), RC::KIND_BLOCKED ),
			'host rule'            => array( array( 'error' => 'host_rule' ), RC::KIND_BLOCKED ),
			'500'                  => array( array( 'code' => 500 ), RC::KIND_AMBIGUOUS ),
			'503'                  => array( array( 'code' => 503 ), RC::KIND_AMBIGUOUS ),
			'520 (Cloudflare)'     => array( array( 'code' => 520 ), RC::KIND_AMBIGUOUS ),
			'timeout'              => array( array( 'error' => 'timeout' ), RC::KIND_AMBIGUOUS ),
			'connection error'     => array( array( 'error' => 'connection' ), RC::KIND_AMBIGUOUS ),
			'redirect loop'        => array( array( 'error' => 'too_many_redirects' ), RC::KIND_AMBIGUOUS ),
			'no response'          => array( array( 'code' => 0 ), RC::KIND_AMBIGUOUS ),
		);
	}

	/**
	 * Classifies each row of the table.
	 *
	 * @dataProvider data_table
	 *
	 * @param array  $result Fetch result.
	 * @param string $kind   Expected kind.
	 */
	public function test_classify( array $result, $kind ) {
		$this->assertSame( $kind, RC::classify( $result ) );
	}

	/**
	 * LinkedIn's 999 is unverifiable, never broken.
	 */
	public function test_999_is_unverifiable() {
		$this->assertSame( 'unverifiable', $this->check( $this->link(), array( 'code' => 999 ) )['status'] );
	}

	/**
	 * A redirect stores the final URL and the chain.
	 */
	public function test_redirect_stores_final_url_and_chain() {
		$chain = array(
			array(
				'url'  => 'http://a.test/',
				'code' => 301,
			),
			array(
				'url'  => 'https://a.test/',
				'code' => 302,
			),
		);
		$row   = $this->check(
			$this->link(),
			array(
				'code'      => 200,
				'chain'     => $chain,
				'final_url' => 'https://a.test/home',
			)
		);

		$this->assertSame( 'redirect', $row['status'] );
		$this->assertSame( 'https://a.test/home', $row['final_url'] );
		$this->assertSame( $chain, json_decode( $row['redirect_chain'], true ) );
		$this->assertSame( $this->stamp( self::NOW + 7 * DAY_IN_SECONDS ), $row['next_check_at'] );
	}

	/**
	 * An invalid certificate is flagged whatever the status.
	 */
	public function test_ssl_invalid_is_flagged_and_not_hidden() {
		$row = $this->check(
			$this->link(),
			array(
				'code'        => 200,
				'ssl_invalid' => true,
			)
		);

		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 1, $row['ssl_invalid'] );
	}

	/*
	 * Confirmation rule.
	 */

	/**
	 * Internal links: a 404 is broken at once.
	 */
	public function test_internal_404_is_broken_immediately() {
		$row = $this->check( $this->link( array( 'is_internal' => 1 ) ), array( 'code' => 404 ) );

		$this->assertSame( 'broken', $row['status'] );
		$this->assertSame( $this->stamp( self::NOW + 3 * DAY_IN_SECONDS ), $row['next_check_at'] );
	}

	/**
	 * External 404: the first one waits 30–60 s for a confirmation, keeping the current status.
	 */
	public function test_external_404_waits_for_confirmation() {
		$row = $this->check( $this->link( array( 'status' => 'ok' ) ), array( 'code' => 404 ) );

		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 1, $row['fail_count'] );
		$this->assertSame( $this->stamp( self::NOW + RC::CONFIRM_DELAY ), $row['next_check_at'] );
		$this->assertGreaterThanOrEqual( 30, RC::CONFIRM_DELAY );
		$this->assertLessThanOrEqual( 60, RC::CONFIRM_DELAY );
	}

	/**
	 * The confirmation retry uses GET and another User-Agent (is_confirming), and a repeated 404 is broken.
	 */
	public function test_repeated_404_is_broken_in_the_same_scan() {
		$first = $this->link( $this->check( $this->link(), array( 'code' => 404 ) ) );

		$this->assertTrue( RC::is_confirming( $first ) );

		$second = $this->check( $first, array( 'code' => 404 ), self::NOW + RC::CONFIRM_DELAY );
		$this->assertSame( 'broken', $second['status'] );
	}

	/**
	 * A 404 that answers 200 on the confirmation retry is ok.
	 */
	public function test_404_then_200_is_ok() {
		$first  = $this->link( $this->check( $this->link(), array( 'code' => 404 ) ) );
		$second = $this->check( $first, array( 'code' => 200 ), self::NOW + RC::CONFIRM_DELAY );

		$this->assertSame( 'ok', $second['status'] );
		$this->assertSame( 0, $second['fail_count'] );
		$this->assertNull( $second['first_failure_at'] );
	}

	/**
	 * A DNS error follows the same confirmation as a 404.
	 */
	public function test_dns_error_is_confirmed_like_404() {
		$first = $this->link( $this->check( $this->link(), array( 'error' => 'dns' ) ) );

		$this->assertSame( 'pending', $first->status );
		$this->assertSame( 'broken', $this->check( $first, array( 'error' => 'dns' ), self::NOW + 45 )['status'] );
	}

	/**
	 * A 404 after a 5xx is not a repeated 404: it waits for its own confirmation.
	 */
	public function test_404_after_5xx_is_not_confirmed_yet() {
		$first  = $this->link( $this->check( $this->link(), array( 'code' => 503 ) ) );
		$second = $this->check( $first, array( 'code' => 404 ), self::NOW + 900 );

		$this->assertSame( 'unverifiable', $second['status'] );
		$this->assertSame( $this->stamp( self::NOW + 900 + RC::CONFIRM_DELAY ), $second['next_check_at'] );
	}

	/**
	 * 5xx: unverifiable at once, retried at 15 min and 2 h, then daily.
	 */
	public function test_ambiguous_retries_at_15_min_2_h_then_daily() {
		$link = $this->link( $this->check( $this->link(), array( 'code' => 500 ) ) );
		$this->assertSame( 'unverifiable', $link->status );
		$this->assertSame( $this->stamp( self::NOW + 900 ), $link->next_check_at );

		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'error' => 'timeout' ), self::NOW + 900 ) ) );
		$this->assertSame( $this->stamp( self::NOW + 900 + 7200 ), $link->next_check_at );

		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 502 ), self::NOW + 8100 ) ) );
		$this->assertSame( 'unverifiable', $link->status );
		$this->assertSame( 1, $link->fail_days );
		$this->assertSame( $this->stamp( self::NOW + 8100 + DAY_IN_SECONDS ), $link->next_check_at );
	}

	/**
	 * Same-day retries count as one day; broken only after 3 different days within 7.
	 */
	public function test_ambiguous_is_broken_after_three_days_within_seven() {
		$day  = DAY_IN_SECONDS;
		$link = $this->link( $this->check( $this->link(), array( 'code' => 500 ) ) );
		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 500 ), self::NOW + 900 ) ) );
		$this->assertSame( 1, $link->fail_days );

		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 500 ), self::NOW + 2 * $day ) ) );
		$this->assertSame( 2, $link->fail_days );
		$this->assertSame( 'unverifiable', $link->status );

		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 500 ), self::NOW + 4 * $day ) ) );
		$this->assertSame( 3, $link->fail_days );
		$this->assertSame( 'broken', $link->status );
	}

	/**
	 * Failures spread over more than 7 days start a new window instead of confirming.
	 */
	public function test_ambiguous_window_expires_after_seven_days() {
		$day  = DAY_IN_SECONDS;
		$link = $this->link( $this->check( $this->link(), array( 'code' => 500 ) ) );
		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 500 ), self::NOW + 3 * $day ) ) );
		$link = $this->link( array_merge( (array) $link, $this->check( $link, array( 'code' => 500 ), self::NOW + 9 * $day ) ) );

		$this->assertSame( 'unverifiable', $link->status );
		$this->assertSame( 1, $link->fail_days );
		$this->assertSame( $this->stamp( self::NOW + 9 * $day ), $link->first_failure_at );
	}

	/**
	 * Any correct answer resets fail_count and first_failure_at.
	 */
	public function test_success_resets_failures() {
		$row = $this->check(
			$this->link(
				array(
					'status'           => 'unverifiable',
					'fail_count'       => 2,
					'fail_days'        => 2,
					'first_failure_at' => $this->stamp( self::NOW - DAY_IN_SECONDS ),
				)
			),
			array( 'code' => 200 )
		);

		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 0, $row['fail_count'] );
		$this->assertSame( 0, $row['fail_days'] );
		$this->assertNull( $row['first_failure_at'] );
		$this->assertSame( $this->stamp( self::NOW + 14 * DAY_IN_SECONDS ), $row['next_check_at'] );
	}

	/**
	 * A broken link stays broken until a check says otherwise: 5xx and 403 do not clear it.
	 */
	public function test_broken_stays_broken_until_a_success() {
		$broken = $this->link(
			array(
				'status'     => 'broken',
				'http_code'  => 404,
				'fail_count' => 2,
			)
		);

		$this->assertSame( 'broken', $this->check( $broken, array( 'code' => 503 ) )['status'] );
		$this->assertSame( 'broken', $this->check( $broken, array( 'code' => 403 ) )['status'] );
		$this->assertSame( 'broken', $this->check( $broken, array( 'code' => 404 ) )['status'] );
		$this->assertSame( 'ok', $this->check( $broken, array( 'code' => 200 ) )['status'] );
	}

	/**
	 * Blocked answers are unverifiable and rechecked weekly, never counted towards broken.
	 */
	public function test_blocked_is_unverifiable_weekly() {
		$row = $this->check( $this->link(), array( 'code' => 403 ) );

		$this->assertSame( 'unverifiable', $row['status'] );
		$this->assertSame( 0, $row['fail_count'] );
		$this->assertSame( $this->stamp( self::NOW + 7 * DAY_IN_SECONDS ), $row['next_check_at'] );
	}
}
