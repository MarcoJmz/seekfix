<?php
/**
 * Content edits for broken links: unlink, remove button, replace URL.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Html\BlockScanner;
use SeekFix\Html\TagLocator;
use WP_Error;

/**
 * Changes only the bytes of one link in post content.
 *
 * Every method takes the content, the link's raw value (as the HTML API
 * decodes it) and its occurrence number, and returns the new content or a
 * WP_Error. Nothing outside the affected link, button or URL changes; the
 * whole document is never re-serialized. It works on block content and on
 * classic editor HTML alike; only remove_button() needs blocks.
 */
class BlockEditor {

	/**
	 * Removes a link but keeps its inner content (text, bold, images).
	 *
	 * @param string $content    Post content.
	 * @param string $raw_href   Decoded href of the link.
	 * @param int    $occurrence 1-based occurrence of that href among `<a>` tags.
	 * @return string|WP_Error
	 */
	public function unlink( $content, $raw_href, $occurrence ) {
		$locator = new TagLocator( $content );

		if ( ! $locator->find_nth( 'a', 'href', $raw_href, $occurrence ) ) {
			return $this->not_found();
		}

		$opener = $locator->current_span();

		if ( null === $opener ) {
			return $this->not_found();
		}

		$block = $this->enclosing_block( $content, $opener['start'] );

		if ( null !== $block && 'core/button' === $block['name'] ) {
			return new WP_Error(
				'seekfix_unlink_button',
				__( 'This link is a button. Remove the button instead of the link.', 'seekfix' )
			);
		}

		if ( ! $locator->seek_matching_closer() ) {
			return new WP_Error(
				'seekfix_unclosed_link',
				__( 'The link has no closing tag, so it cannot be removed safely. Open the editor to fix it.', 'seekfix' )
			);
		}

		$closer = $locator->current_span();

		if ( null === $closer ) {
			return $this->not_found();
		}

		// Cut the closer first so the opener offsets stay valid.
		$content = substr_replace( $content, '', $closer['start'], $closer['length'] );

		return substr_replace( $content, '', $opener['start'], $opener['length'] );
	}

	/**
	 * Removes the core/button block that holds a link.
	 *
	 * If the parent core/buttons block is left with no buttons, it is removed
	 * too. The whitespace that separated the removed block from its sibling
	 * goes with it, as the block editor would save it.
	 *
	 * @param string $content    Post content.
	 * @param string $raw_href   Decoded href of the button link.
	 * @param int    $occurrence 1-based occurrence of that href among `<a>` tags.
	 * @return string|WP_Error
	 */
	public function remove_button( $content, $raw_href, $occurrence ) {
		$locator = new TagLocator( $content );

		if ( ! $locator->find_nth( 'a', 'href', $raw_href, $occurrence ) ) {
			return $this->not_found();
		}

		$opener = $locator->current_span();

		if ( null === $opener ) {
			return $this->not_found();
		}

		$blocks = BlockScanner::scan( $content );
		$index  = BlockScanner::innermost_at( $blocks, $opener['start'] );

		if ( null === $index || 'core/button' !== $blocks[ $index ]['name'] ) {
			return new WP_Error(
				'seekfix_not_a_button',
				__( 'This link is not inside a button block.', 'seekfix' )
			);
		}

		$parent = $blocks[ $index ]['parent'];

		if ( null !== $parent && 'core/buttons' === $blocks[ $parent ]['name'] && 1 === count( BlockScanner::children_of( $blocks, $parent ) ) ) {
			$index = $parent;
		}

		$span = $this->removal_span( $content, $blocks, $index );

		return substr_replace( $content, '', $span['start'], $span['end'] - $span['start'] );
	}

	/**
	 * Replaces the URL of a link or image.
	 *
	 * Only the attribute value changes. If the enclosing block also stores the
	 * old URL in its JSON attributes (for example core/file), those JSON
	 * strings are updated as well so the block stays valid in the editor.
	 *
	 * @param string $content    Post content.
	 * @param string $raw_url    Decoded current URL.
	 * @param int    $occurrence 1-based occurrence of that URL among tags of the same kind.
	 * @param string $new_url    New URL.
	 * @param string $tag_name   "a" (href, default) or "img" (src).
	 * @return string|WP_Error
	 */
	public function replace_url( $content, $raw_url, $occurrence, $new_url, $tag_name = 'a' ) {
		$new_url = esc_url_raw( $new_url );

		if ( '' === $new_url ) {
			return new WP_Error(
				'seekfix_invalid_url',
				__( 'The new URL is not valid.', 'seekfix' )
			);
		}

		$attribute = 'img' === $tag_name ? 'src' : 'href';
		$locator   = new TagLocator( $content );

		if ( ! $locator->find_nth( $tag_name, $attribute, $raw_url, $occurrence ) ) {
			return $this->not_found();
		}

		$opener = $locator->current_span();

		if ( null === $opener ) {
			return $this->not_found();
		}

		$block = $this->enclosing_block( $content, $opener['start'] );

		if ( null !== $block && $this->block_attrs_contain( $content, $block, $raw_url ) ) {
			return $this->replace_url_in_block( $content, $block, $raw_url, $new_url, $tag_name, $attribute );
		}

		$locator->set_attribute( $attribute, $new_url );

		return $locator->get_updated_html();
	}

	/**
	 * Replaces a URL everywhere inside one block: its tags and its JSON attributes.
	 *
	 * Used when the block keeps the URL in its attributes (core/file stores it
	 * once in JSON and twice in links). Changing only one copy would leave the
	 * block invalid in the editor.
	 *
	 * @param string               $content   Post content.
	 * @param array<string, mixed> $block     Block from BlockScanner::scan().
	 * @param string               $old_url   Decoded current URL.
	 * @param string               $new_url   Sanitized new URL.
	 * @param string               $tag_name  Tag name.
	 * @param string               $attribute Attribute name.
	 * @return string
	 */
	private function replace_url_in_block( $content, array $block, $old_url, $new_url, $tag_name, $attribute ) {
		$locator = new TagLocator( $content );

		while ( $locator->next_named_tag( $tag_name ) ) {
			$span = $locator->current_span();

			if ( null === $span || $span['start'] < $block['opener_end'] ) {
				continue;
			}

			if ( $span['start'] >= $block['end'] ) {
				break;
			}

			if ( $locator->get_attribute( $attribute ) === $old_url ) {
				$locator->set_attribute( $attribute, $new_url );
			}
		}

		// The block opener precedes its tags, so its offsets are unchanged.
		return $this->replace_in_block_attrs( $locator->get_updated_html(), $block, $old_url, $new_url );
	}

	/**
	 * Whether a block's JSON attributes hold the URL as a string value.
	 *
	 * @param string               $content Post content.
	 * @param array<string, mixed> $block   Block from BlockScanner::scan().
	 * @param string               $url     URL to look for.
	 * @return bool
	 */
	private function block_attrs_contain( $content, array $block, $url ) {
		if ( $block['attrs_length'] <= 0 ) {
			return false;
		}

		$json = substr( $content, $block['attrs_start'], $block['attrs_length'] );

		foreach ( $this->json_candidates( $url ) as $candidate ) {
			if ( false !== strpos( $json, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Ways a URL can appear as a JSON string in block attributes.
	 *
	 * The encoding WordPress uses today, plus plain json_encode() output from
	 * older versions, with and without escaped slashes.
	 *
	 * @param string $url URL.
	 * @return string[]
	 */
	private function json_candidates( $url ) {
		return array_values(
			array_unique(
				array(
					'"' . $this->json_string( $url ) . '"',
					wp_json_encode( $url ),
					wp_json_encode( $url, JSON_UNESCAPED_SLASHES ),
				)
			)
		);
	}

	/**
	 * Returns the innermost block around an offset, or null for classic content.
	 *
	 * @param string $content Post content.
	 * @param int    $offset  Byte offset.
	 * @return array<string, mixed>|null
	 */
	private function enclosing_block( $content, $offset ) {
		$blocks = BlockScanner::scan( $content );
		$index  = BlockScanner::innermost_at( $blocks, $offset );

		return null === $index ? null : $blocks[ $index ];
	}

	/**
	 * Computes the bytes to remove for a block, including one separator.
	 *
	 * The separator is the text between the block and its next sibling, or
	 * between the previous sibling and the block when it is the last one. That
	 * text is whitespace in editor-saved content; if it is anything else, only
	 * the block itself is removed.
	 *
	 * @param string                           $content Post content.
	 * @param array<int, array<string, mixed>> $blocks  Blocks from BlockScanner::scan().
	 * @param int                              $index   Block to remove.
	 * @return array{start:int,end:int}
	 */
	private function removal_span( $content, array $blocks, $index ) {
		$siblings = BlockScanner::children_of( $blocks, $blocks[ $index ]['parent'] );
		$position = array_search( $index, $siblings, true );
		$start    = $blocks[ $index ]['start'];
		$end      = $blocks[ $index ]['end'];

		if ( isset( $siblings[ $position + 1 ] ) ) {
			$next = $blocks[ $siblings[ $position + 1 ] ]['start'];

			if ( $this->is_whitespace( $content, $end, $next ) ) {
				return array(
					'start' => $start,
					'end'   => $next,
				);
			}
		} elseif ( $position > 0 ) {
			$previous = $blocks[ $siblings[ $position - 1 ] ]['end'];

			if ( $this->is_whitespace( $content, $previous, $start ) ) {
				return array(
					'start' => $previous,
					'end'   => $end,
				);
			}
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Whether the bytes between two offsets are only whitespace.
	 *
	 * @param string $content Post content.
	 * @param int    $from    Start offset.
	 * @param int    $to      End offset, exclusive.
	 * @return bool
	 */
	private function is_whitespace( $content, $from, $to ) {
		return '' === trim( substr( $content, $from, $to - $from ) );
	}

	/**
	 * Replaces the old URL inside a block's JSON attributes.
	 *
	 * Matches whole JSON string values only (see json_candidates()).
	 *
	 * @param string               $content Content after the attribute change.
	 * @param array<string, mixed> $block   Block from BlockScanner::scan().
	 * @param string               $old_url Old URL.
	 * @param string               $new_url New URL.
	 * @return string
	 */
	private function replace_in_block_attrs( $content, array $block, $old_url, $new_url ) {
		$json    = substr( $content, $block['attrs_start'], $block['attrs_length'] );
		$new     = '"' . $this->json_string( $new_url ) . '"';
		$patched = str_replace( $this->json_candidates( $old_url ), $new, $json );

		if ( $patched === $json ) {
			return $content;
		}

		return substr_replace( $content, $patched, $block['attrs_start'], $block['attrs_length'] );
	}

	/**
	 * Encodes a string the way serialize_block_attributes() does, without quotes.
	 *
	 * @param string $value Value to encode.
	 * @return string
	 */
	private function json_string( $value ) {
		$encoded = serialize_block_attributes( array( 'v' => $value ) );

		// Strip the `{"v":"` prefix and `"}` suffix.
		return substr( $encoded, 6, -2 );
	}

	/**
	 * Error for a link that is no longer where the scan found it.
	 *
	 * @return WP_Error
	 */
	private function not_found() {
		return new WP_Error(
			'seekfix_link_not_found',
			__( 'The link is no longer in the content. Scan the post again.', 'seekfix' )
		);
	}
}
