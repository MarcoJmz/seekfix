<?php
/**
 * Tests for BlockEditor: every edit changes only the bytes of one link.
 *
 * @package SeekFix
 */

use SeekFix\BlockEditor;

/**
 * BlockEditor proof of concept tests.
 */
class BlockEditorTest extends WP_UnitTestCase {

	/**
	 * Editor under test.
	 *
	 * @var BlockEditor
	 */
	private $editor;

	/**
	 * Sets up the editor.
	 */
	public function set_up() {
		parent::set_up();
		$this->editor = new BlockEditor();
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
	 * Replaces the Nth occurrence of a substring; fails if it does not exist.
	 *
	 * Builds the expected output from the original so that any other byte that
	 * changed makes assertSame() fail.
	 *
	 * @param string $haystack    Original content.
	 * @param string $needle      Exact bytes to replace.
	 * @param string $replacement Replacement bytes.
	 * @param int    $nth         1-based occurrence.
	 * @return string
	 */
	private function replace_nth( $haystack, $needle, $replacement, $nth = 1 ) {
		$offset = -1;

		for ( $i = 0; $i < $nth; $i++ ) {
			$offset = strpos( $haystack, $needle, $offset + 1 );
			$this->assertNotFalse( $offset, "Fixture does not contain occurrence {$nth} of: {$needle}" );
		}

		return substr_replace( $haystack, $replacement, $offset, strlen( $needle ) );
	}

	/**
	 * Asserts the result is a WP_Error with the given code.
	 *
	 * @param string $code   Expected error code.
	 * @param mixed  $result Result to check.
	 */
	private function assert_error( $code, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/*
	 * Unlink.
	 */

	/**
	 * Unlinking keeps bold text and touches nothing else, even with a prefix URL next to it.
	 */
	public function test_unlink_keeps_inner_markup_and_ignores_prefix_urls() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'<a href="https://example.test/precios">nuestros <strong>precios</strong></a>',
			'nuestros <strong>precios</strong>'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/precios', 1 ) );
	}

	/**
	 * The URL that is a prefix of another is not confused with it.
	 */
	public function test_unlink_longer_url_leaves_prefix_url_alone() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'<a href="https://example.test/precios-2025">precios 2025</a>',
			'precios 2025'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/precios-2025', 1 ) );
	}

	/**
	 * The second appearance of a repeated link is the one removed, inside a block
	 * with backslashes, escaped JSON, entities and an HTML comment.
	 */
	public function test_unlink_second_occurrence_with_backslashes_and_comments() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'<a href="https://example.test/precios">otra vez precios</a>',
			'otra vez precios'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/precios', 2 ) );
	}

	/**
	 * A linked image keeps the image.
	 */
	public function test_unlink_keeps_inner_image() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'<a href="https://example.test/precios"><img src="https://example.test/logo.png" alt="Logo"/></a>',
			'<img src="https://example.test/logo.png" alt="Logo"/>'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/precios', 3 ) );
	}

	/**
	 * Links with encoded entities in href are matched by their decoded value.
	 */
	public function test_unlink_matches_decoded_href_with_entities() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'<a class="x" href="https://example.test/buscar?a=1&amp;b=2" target="_blank" rel="noreferrer noopener">buscar &laquo;todo&raquo;</a>',
			'buscar &laquo;todo&raquo;'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/buscar?a=1&b=2', 1 ) );
	}

	/**
	 * Classic editor HTML: uppercase tag, single quotes, inner emphasis.
	 */
	public function test_unlink_classic_uppercase_single_quotes() {
		$content  = $this->fixture( 'classic.html' );
		$expected = $this->replace_nth(
			$content,
			"<A HREF='https://example.test/viejo'>un enlace <em>viejo</em></A>",
			'un enlace <em>viejo</em>'
		);

		$this->assertSame( $expected, $this->editor->unlink( $content, 'https://example.test/viejo', 1 ) );
	}

	/**
	 * A link without a closing tag is refused instead of guessing.
	 */
	public function test_unlink_refuses_unclosed_link() {
		$content = $this->fixture( 'classic.html' );

		$this->assert_error( 'seekfix_unclosed_link', $this->editor->unlink( $content, 'https://example.test/abierto', 1 ) );
	}

	/**
	 * A missing occurrence returns an error and no content.
	 */
	public function test_unlink_missing_occurrence() {
		$content = $this->fixture( 'nested.html' );

		$this->assert_error( 'seekfix_link_not_found', $this->editor->unlink( $content, 'https://example.test/precios', 4 ) );
		$this->assert_error( 'seekfix_link_not_found', $this->editor->unlink( $content, 'https://example.test/precio', 1 ) );
	}

	/**
	 * Buttons must be removed as buttons, not unlinked into invalid block markup.
	 */
	public function test_unlink_refuses_button() {
		$content = $this->fixture( 'buttons.html' );

		$this->assert_error( 'seekfix_unlink_button', $this->editor->unlink( $content, 'https://example.test/roto', 1 ) );
	}

	/*
	 * Remove button.
	 */

	/**
	 * Removing the first of two buttons removes it and the separator after it.
	 */
	public function test_remove_first_of_two_buttons() {
		$content  = $this->fixture( 'buttons.html' );
		$expected = $this->replace_nth(
			$content,
			"<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"https://example.test/roto\">Comprar</a></div>\n<!-- /wp:button -->\n\n",
			''
		);

		$this->assertSame( $expected, $this->editor->remove_button( $content, 'https://example.test/roto', 1 ) );
	}

	/**
	 * Removing the last button removes the separator before it.
	 */
	public function test_remove_last_button() {
		$content  = $this->fixture( 'buttons.html' );
		$expected = $this->replace_nth(
			$content,
			"\n\n<!-- wp:button {\"className\":\"is-style-outline\"} -->\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link wp-element-button\" href=\"https://example.test/ok\">Ver más</a></div>\n<!-- /wp:button -->",
			''
		);

		$this->assertSame( $expected, $this->editor->remove_button( $content, 'https://example.test/ok', 1 ) );
	}

	/**
	 * Removing the only button also removes its now empty buttons group.
	 */
	public function test_remove_only_button_removes_empty_group() {
		$content  = $this->fixture( 'buttons.html' );
		$expected = $this->replace_nth(
			$content,
			"<!-- wp:buttons {\"layout\":{\"type\":\"flex\",\"justifyContent\":\"center\"}} -->\n<div class=\"wp-block-buttons\"><!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"https://example.test/solo\">Único</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->\n\n",
			''
		);

		$result = $this->editor->remove_button( $content, 'https://example.test/solo', 1 );

		$this->assertSame( $expected, $result );
		$this->assertSame( 3, count( array_filter( wp_list_pluck( parse_blocks( $result ), 'blockName' ) ) ) );
	}

	/**
	 * The result still parses as the same blocks minus the removed one.
	 */
	public function test_remove_button_result_parses() {
		$result  = $this->editor->remove_button( $this->fixture( 'buttons.html' ), 'https://example.test/roto', 1 );
		$blocks  = array_values(
			array_filter(
				parse_blocks( $result ),
				static function ( $block ) {
					return null !== $block['blockName'];
				}
			)
		);
		$buttons = $blocks[1]['innerBlocks'];

		$this->assertCount( 1, $buttons );
		$this->assertStringContainsString( 'https://example.test/ok', $buttons[0]['innerHTML'] );
	}

	/**
	 * A link in a paragraph is not a button.
	 */
	public function test_remove_button_refuses_plain_link() {
		$this->assert_error(
			'seekfix_not_a_button',
			$this->editor->remove_button( $this->fixture( 'nested.html' ), 'https://example.test/precios', 1 )
		);
		$this->assert_error(
			'seekfix_not_a_button',
			$this->editor->remove_button( $this->fixture( 'classic.html' ), 'https://example.test/viejo', 2 )
		);
	}

	/*
	 * Replace URL.
	 */

	/**
	 * Replacing a URL changes only that href.
	 */
	public function test_replace_url_changes_only_href() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'href="https://example.test/precios">otra vez',
			'href="https://example.test/tarifas">otra vez'
		);

		$this->assertSame( $expected, $this->editor->replace_url( $content, 'https://example.test/precios', 2, 'https://example.test/tarifas' ) );
	}

	/**
	 * A new URL with a query string is stored encoded, and the other attributes stay.
	 *
	 * The HTML API escapes `&` as `&#038;`, like esc_url(); both decode to the same URL.
	 */
	public function test_replace_url_with_query_string() {
		$content  = $this->fixture( 'nested.html' );
		$expected = $this->replace_nth(
			$content,
			'href="https://example.test/buscar?a=1&amp;b=2"',
			'href="https://example.test/buscar?q=1&#038;r=2"'
		);

		$this->assertSame( $expected, $this->editor->replace_url( $content, 'https://example.test/buscar?a=1&b=2', 1, 'https://example.test/buscar?q=1&r=2' ) );
	}

	/**
	 * Single-quoted classic attributes are rewritten as `href="…"`; nothing else moves.
	 */
	public function test_replace_url_classic_single_quotes() {
		$content  = $this->fixture( 'classic.html' );
		$expected = $this->replace_nth(
			$content,
			"HREF='https://example.test/viejo'",
			'href="https://example.test/nuevo"'
		);

		$this->assertSame( $expected, $this->editor->replace_url( $content, 'https://example.test/viejo', 1, 'https://example.test/nuevo' ) );
	}

	/**
	 * Images: the src changes, width and height stay.
	 */
	public function test_replace_image_src() {
		$content  = $this->fixture( 'classic.html' );
		$expected = $this->replace_nth(
			$content,
			'src="https://example.test/foto.jpg"',
			'src="https://example.test/foto-nueva.jpg"'
		);

		$this->assertSame( $expected, $this->editor->replace_url( $content, 'https://example.test/foto.jpg', 1, 'https://example.test/foto-nueva.jpg', 'img' ) );
	}

	/**
	 * A block that stores the URL in JSON gets every copy updated, and stays valid.
	 */
	public function test_replace_url_in_file_block_updates_json_and_both_links() {
		$content  = $this->fixture( 'file.html' );
		$old      = 'https://example.test/wp-content/uploads/guia.pdf';
		$new      = 'https://example.test/wp-content/uploads/guia-2026.pdf';
		$expected = str_replace( $old, $new, $content );

		$result = $this->editor->replace_url( $content, $old, 2, $new );

		$this->assertSame( $expected, $result );

		$block = parse_blocks( $result )[0];
		$this->assertSame( $new, $block['attrs']['href'] );
	}

	/**
	 * An empty or unsafe new URL is refused.
	 */
	public function test_replace_url_rejects_invalid_url() {
		$content = $this->fixture( 'nested.html' );

		$this->assert_error( 'seekfix_invalid_url', $this->editor->replace_url( $content, 'https://example.test/precios', 1, 'javascript:alert(1)' ) );
		$this->assert_error( 'seekfix_invalid_url', $this->editor->replace_url( $content, 'https://example.test/precios', 1, '' ) );
	}
}
