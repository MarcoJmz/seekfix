<?php
/**
 * Tests for Extractor.
 *
 * @package SeekFix
 */

use SeekFix\BlockEditor;
use SeekFix\Extractor;

/**
 * Link extraction tests.
 */
class ExtractorTest extends WP_UnitTestCase {

	/**
	 * Extractor under test.
	 *
	 * @var Extractor
	 */
	private $extractor;

	/**
	 * Sets up the extractor.
	 */
	public function set_up() {
		parent::set_up();
		$this->extractor = new Extractor();
	}

	/**
	 * Reads a fixture file byte for byte.
	 *
	 * @param string $name File name in tests/fixtures.
	 * @return string
	 */
	private function fixture( $name ) {
		return file_get_contents( __DIR__ . '/fixtures/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Reduces items to "element|raw|occurrence|text" strings for compact assertions.
	 *
	 * @param array $items Extracted items.
	 * @return string[]
	 */
	private function summarize( array $items ) {
		return array_map(
			static function ( $item ) {
				return implode( '|', array( $item['element'], $item['raw_href'], $item['occurrence'], $item['anchor_text'] ) );
			},
			$items
		);
	}

	/**
	 * Nested blocks: links, a repeated link with occurrences, a linked image, entities.
	 */
	public function test_nested_blocks() {
		$this->assertSame(
			array(
				'link|https://example.test/precios|1|nuestros precios',
				'link|https://example.test/precios-2025|1|precios 2025',
				'link|https://example.test/precios|2|otra vez precios',
				'link|https://example.test/buscar?a=1&b=2|1|buscar «todo»',
				'link|https://example.test/precios|3|Logo',
				'image|https://example.test/logo.png|1|Logo',
			),
			$this->summarize( $this->extractor->extract( $this->fixture( 'nested.html' ) ) )
		);
	}

	/**
	 * Links inside core/button blocks are buttons.
	 */
	public function test_buttons() {
		$this->assertSame(
			array(
				'button|https://example.test/roto|1|Comprar',
				'button|https://example.test/ok|1|Ver más',
				'button|https://example.test/solo|1|Único',
			),
			$this->summarize( $this->extractor->extract( $this->fixture( 'buttons.html' ) ) )
		);
	}

	/**
	 * Classic HTML: uppercase tags, an image, and a link without a closing tag.
	 */
	public function test_classic() {
		$this->assertSame(
			array(
				'link|https://example.test/viejo|1|un enlace viejo',
				'link|https://example.test/viejo|2|otro igual',
				'image|https://example.test/foto.jpg|1|Foto',
				'link|https://example.test/abierto|1|',
			),
			$this->summarize( $this->extractor->extract( $this->fixture( 'classic.html' ) ) )
		);
	}

	/**
	 * WPBakery and Divi shortcode URLs are detected; escaped shortcodes are not.
	 */
	public function test_shortcodes() {
		$items      = $this->extractor->extract( $this->fixture( 'shortcodes.html' ) );
		$shortcodes = array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return 'shortcode' === $item['element'];
				}
			)
		);

		$this->assertSame( 'link|/contacto|1|contacto', $this->summarize( $items )[0] );
		$this->assertSame(
			array(
				'vc_btn https://example.test/citas?origen=web Agenda tu cita',
				'vc_btn #formulario Ir al formulario',
				'vc_single_image https://example.test/galeria ',
				'et_pb_button https://example.test/precios Ver precios',
				'et_pb_image https://example.test/wp-content/uploads/foto.png Foto',
			),
			array_map(
				static function ( $item ) {
					return $item['shortcode_tag'] . ' ' . $item['raw_href'] . ' ' . $item['anchor_text'];
				},
				$shortcodes
			)
		);
	}

	/**
	 * Anchor text is plain text, one line, at most 255 characters.
	 */
	public function test_anchor_text_is_cleaned_and_truncated() {
		$long  = str_repeat( 'á', 300 );
		$items = $this->extractor->extract( "<p><a href=\"https://example.test/\">\n  Hola <b>mundo</b>\n</a> <a href=\"https://example.test/l\">{$long}</a></p>" );

		$this->assertSame( 'Hola mundo', $items[0]['anchor_text'] );
		$this->assertSame( 255, mb_strlen( $items[1]['anchor_text'] ) );
	}

	/**
	 * Links without href and empty content produce nothing.
	 */
	public function test_ignores_anchors_without_href() {
		$this->assertSame( array(), $this->extractor->extract( '<p><a name="top">Arriba</a></p>' ) );
		$this->assertSame( array(), $this->extractor->extract( '' ) );
	}

	/**
	 * Every extracted link can be found again by BlockEditor with its raw value
	 * and occurrence: the two count the same way.
	 */
	public function test_occurrences_match_block_editor() {
		$editor = new BlockEditor();

		foreach ( array( 'nested.html', 'classic.html', 'buttons.html' ) as $name ) {
			$content = $this->fixture( $name );

			foreach ( $this->extractor->extract( $content ) as $item ) {
				$tag    = 'image' === $item['element'] ? 'img' : 'a';
				$result = $editor->replace_url( $content, $item['raw_href'], $item['occurrence'], 'https://example.test/reemplazo', $tag );

				$this->assertIsString( $result, "{$name}: {$item['raw_href']} #{$item['occurrence']} not found by BlockEditor" );
				$this->assertNotSame( $content, $result );
			}
		}
	}
}
