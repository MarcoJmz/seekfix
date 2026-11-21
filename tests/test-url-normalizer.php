<?php
/**
 * Tests for UrlNormalizer.
 *
 * @package SeekFix
 */

use SeekFix\UrlNormalizer;

/**
 * URL resolution and normalization tests.
 */
class UrlNormalizerTest extends WP_UnitTestCase {

	/**
	 * Base URL relative links resolve against.
	 */
	const BASE = 'https://site.test/blog/mi-post/';

	/**
	 * Normalizer under test.
	 *
	 * @var UrlNormalizer
	 */
	private $normalizer;

	/**
	 * Sets up the normalizer.
	 */
	public function set_up() {
		parent::set_up();
		$this->normalizer = new UrlNormalizer( 'https://site.test' );
	}

	/**
	 * Raw URLs and their normalized form.
	 *
	 * @return array<string, array{0:string,1:string}>
	 */
	public function data_normalized() {
		return array(
			'absolute'             => array( 'https://example.test/a?b=1', 'https://example.test/a?b=1' ),
			'scheme and host case' => array( 'HTTPS://Example.TEST/Path', 'https://example.test/Path' ),
			'fragment removed'     => array( 'https://example.test/a#seccion', 'https://example.test/a' ),
			'query kept'           => array( 'https://example.test/a?utm=x&b=2#top', 'https://example.test/a?utm=x&b=2' ),
			'empty path'           => array( 'https://example.test', 'https://example.test/' ),
			'port kept'            => array( 'http://example.test:8080/x', 'http://example.test:8080/x' ),
			'root relative'        => array( '/precios', 'https://site.test/precios' ),
			'path relative'        => array( 'ruta', 'https://site.test/blog/mi-post/ruta' ),
			'parent relative'      => array( '../otra', 'https://site.test/blog/otra' ),
			'protocol relative'    => array( '//cdn.example.test/x.png', 'https://cdn.example.test/x.png' ),
			'query only'           => array( '?pagina=2', 'https://site.test/blog/mi-post/?pagina=2' ),
			'surrounding spaces'   => array( '  https://example.test/a  ', 'https://example.test/a' ),
		);
	}

	/**
	 * Normalizes and resolves URLs.
	 *
	 * @dataProvider data_normalized
	 *
	 * @param string $raw      Raw URL.
	 * @param string $expected Normalized URL.
	 */
	public function test_normalize( $raw, $expected ) {
		$this->assertSame( $expected, $this->normalizer->normalize( $raw, self::BASE ) );
	}

	/**
	 * URLs that are never checked.
	 *
	 * @return array<string, array{0:string}>
	 */
	public function data_skipped() {
		return array(
			'empty'         => array( '' ),
			'spaces'        => array( '   ' ),
			'fragment'      => array( '#formulario' ),
			'bare hash'     => array( '#' ),
			'mailto'        => array( 'mailto:hola@example.test' ),
			'tel'           => array( 'tel:+525555555555' ),
			'sms'           => array( 'sms:+525555555555' ),
			'javascript'    => array( 'javascript:void(0)' ),
			'split scheme'  => array( "java\tscript:alert(1)" ),
			'data'          => array( 'data:image/png;base64,AAAA' ),
			'uppercase tel' => array( 'TEL:555' ),
			'other scheme'  => array( 'whatsapp://send?phone=1' ),
			'ftp'           => array( 'ftp://example.test/file' ),
		);
	}

	/**
	 * Skips non-checkable URLs.
	 *
	 * @dataProvider data_skipped
	 *
	 * @param string $raw Raw URL.
	 */
	public function test_skipped( $raw ) {
		$this->assertNull( $this->normalizer->normalize( $raw, self::BASE ) );
	}

	/**
	 * Same host as home_url() is internal; subdomains are not.
	 */
	public function test_is_internal() {
		$this->assertTrue( $this->normalizer->is_internal( 'https://site.test/precios' ) );
		$this->assertTrue( $this->normalizer->is_internal( 'http://site.test/' ) );
		$this->assertFalse( $this->normalizer->is_internal( 'https://www.site.test/' ) );
		$this->assertFalse( $this->normalizer->is_internal( 'https://example.test/' ) );
	}

	/**
	 * Hash and host helpers.
	 */
	public function test_hash_and_host() {
		$this->assertSame( sha1( 'https://example.test/' ), UrlNormalizer::hash( 'https://example.test/' ) );
		$this->assertSame( 40, strlen( UrlNormalizer::hash( 'https://example.test/' ) ) );
		$this->assertSame( 'example.test', UrlNormalizer::host( 'https://example.test/a' ) );
	}
}
