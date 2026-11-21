<?php
/**
 * Link extraction from post content.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Html\BlockScanner;
use SeekFix\Html\TagLocator;

/**
 * Finds links, buttons, images and builder shortcode links in post content.
 *
 * It reads post_content as stored, without rendering the page, and never reads
 * postmeta. Every result carries what BlockEditor needs to find it again: the
 * raw value and its occurrence number, counted the same way BlockEditor
 * counts them (among tags of the same kind with the same decoded value, tag
 * names in any letter case, in document order).
 */
class Extractor {

	/**
	 * Maximum length of the stored anchor text, in characters.
	 */
	const MAX_ANCHOR_TEXT = 255;

	/**
	 * Builder shortcode attributes that hold a URL, by tag name.
	 *
	 * WPBakery stores links as `url:<urlencoded>|title:…|target:…`; Divi stores
	 * plain URLs. Detected only: v1 does not fix shortcodes.
	 */
	const SHORTCODE_URL_ATTRIBUTES = array(
		'vc_btn'                => array( 'link' ),
		'vc_cta'                => array( 'btn_link' ),
		'vc_icon'               => array( 'link' ),
		'vc_single_image'       => array( 'link' ),
		'et_pb_button'          => array( 'button_url' ),
		'et_pb_blurb'           => array( 'url', 'link_option_url' ),
		'et_pb_cta'             => array( 'button_url', 'link_option_url' ),
		'et_pb_image'           => array( 'src', 'url', 'link_option_url' ),
		'et_pb_slide'           => array( 'button_link', 'link_option_url' ),
		'et_pb_text'            => array( 'link_option_url' ),
		'et_pb_column'          => array( 'link_option_url' ),
		'et_pb_row'             => array( 'link_option_url' ),
		'et_pb_section'         => array( 'link_option_url' ),
		'et_pb_fullwidth_image' => array( 'src', 'url', 'link_option_url' ),
	);

	/**
	 * Shortcode attributes used as the visible text of a shortcode link.
	 */
	const SHORTCODE_TEXT_ATTRIBUTES = array( 'title', 'button_text', 'alt', 'title_text' );

	/**
	 * Extracts every link in a piece of content.
	 *
	 * Each item is an array with:
	 * - `element`: "link", "button", "image" or "shortcode".
	 * - `raw_href`: decoded value as found (href, src or shortcode URL).
	 * - `occurrence`: 1-based number among items of the same kind and raw value.
	 * - `anchor_text`: visible text, image alt or shortcode title, up to 255 characters.
	 * - `shortcode_tag`: shortcode name, or null.
	 *
	 * Fragment-only links, empty values and non-checkable schemes are still
	 * returned here; the caller decides with UrlNormalizer which ones to keep.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( $content ) {
		if ( '' === trim( (string) $content ) ) {
			return array();
		}

		$items = $this->extract_tags( $content );

		foreach ( $this->extract_shortcodes( $content ) as $item ) {
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Extracts `<a href>` and `<img src>` in one pass over the HTML.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_tags( $content ) {
		$blocks    = BlockScanner::scan( $content );
		$processor = new TagLocator( $content );
		$items     = array();
		$counts    = array();
		$open      = array();

		while ( $processor->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			$tag = $processor->get_tag();

			if ( 'A' === $tag && $processor->is_tag_closer() ) {
				$index = array_pop( $open );

				if ( null !== $index ) {
					$span = $processor->current_span();

					if ( null !== $span && null !== $items[ $index ]['inner_start'] ) {
						$text = $this->clean_text( substr( $content, $items[ $index ]['inner_start'], $span['start'] - $items[ $index ]['inner_start'] ) );

						if ( '' !== $text ) {
							$items[ $index ]['anchor_text'] = $text;
						}
					}
				}
				continue;
			}

			if ( $processor->is_tag_closer() ) {
				continue;
			}

			if ( 'A' === $tag ) {
				$href = $processor->get_attribute( 'href' );
				$span = $processor->current_span();

				if ( ! is_string( $href ) ) {
					// An <a> without href still closes with </a>; keep the stack aligned.
					$open[] = null;
					continue;
				}

				$element = 'link';

				if ( null !== $span ) {
					$block = BlockScanner::innermost_at( $blocks, $span['start'] );

					if ( null !== $block && 'core/button' === $blocks[ $block ]['name'] ) {
						$element = 'button';
					}
				}

				$items[] = array(
					'element'       => $element,
					'raw_href'      => $href,
					'occurrence'    => $this->next_occurrence( $counts, 'a', $href ),
					'anchor_text'   => '',
					'shortcode_tag' => null,
					'inner_start'   => null === $span ? null : $span['start'] + $span['length'],
				);
				$open[]  = count( $items ) - 1;
				continue;
			}

			if ( 'IMG' === $tag ) {
				$src = $processor->get_attribute( 'src' );
				$alt = $processor->get_attribute( 'alt' );
				$alt = is_string( $alt ) ? $this->clean_text( $alt ) : '';

				// A link that wraps only an image is named by the image's alt text.
				$parent = end( $open );

				if ( null !== $parent && false !== $parent && '' === $items[ $parent ]['anchor_text'] && '' !== $alt ) {
					$items[ $parent ]['image_alt'] = $alt;
				}

				if ( is_string( $src ) ) {
					$items[] = array(
						'element'       => 'image',
						'raw_href'      => $src,
						'occurrence'    => $this->next_occurrence( $counts, 'img', $src ),
						'anchor_text'   => $alt,
						'shortcode_tag' => null,
						'inner_start'   => null,
					);
				}
			}
		}

		foreach ( $items as &$item ) {
			if ( '' === $item['anchor_text'] && isset( $item['image_alt'] ) ) {
				$item['anchor_text'] = $item['image_alt'];
			}

			unset( $item['inner_start'], $item['image_alt'] );
		}
		unset( $item );

		return array_values( $items );
	}

	/**
	 * Extracts URLs from known WPBakery and Divi shortcodes.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>>
	 */
	private function extract_shortcodes( $content ) {
		if ( false === strpos( $content, '[' ) ) {
			return array();
		}

		/**
		 * Filters the builder shortcode attributes that hold URLs.
		 *
		 * @param array<string, string[]> $attributes Attribute names by shortcode tag.
		 */
		$known = apply_filters( 'seekfix_shortcode_url_attributes', self::SHORTCODE_URL_ATTRIBUTES );
		$tags  = array_keys( $known );

		if ( empty( $tags ) || ! preg_match_all( '/' . get_shortcode_regex( $tags ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$items  = array();
		$counts = array();

		$this->collect_shortcodes( $matches, $known, $items, $counts );

		return $items;
	}

	/**
	 * Reads URL attributes from shortcode matches, recursing into nested content.
	 *
	 * @param array                   $matches Matches of get_shortcode_regex().
	 * @param array<string, string[]> $known   Attribute names by tag.
	 * @param array                   $items   Collected items, by reference.
	 * @param array                   $counts  Occurrence counters, by reference.
	 */
	private function collect_shortcodes( array $matches, array $known, array &$items, array &$counts ) {
		foreach ( $matches as $match ) {
			// [[escaped]] shortcodes are not rendered.
			if ( '[' === $match[1] && ']' === $match[6] ) {
				continue;
			}

			$tag   = $match[2];
			$attrs = shortcode_parse_atts( $match[3] );
			$attrs = is_array( $attrs ) ? $attrs : array();
			$text  = '';

			foreach ( self::SHORTCODE_TEXT_ATTRIBUTES as $text_attribute ) {
				if ( ! empty( $attrs[ $text_attribute ] ) && is_string( $attrs[ $text_attribute ] ) ) {
					$text = $this->clean_text( $attrs[ $text_attribute ] );
					break;
				}
			}

			foreach ( isset( $known[ $tag ] ) ? (array) $known[ $tag ] : array() as $attribute ) {
				if ( empty( $attrs[ $attribute ] ) || ! is_string( $attrs[ $attribute ] ) ) {
					continue;
				}

				$url = $this->shortcode_url( $attrs[ $attribute ] );

				if ( '' === $url ) {
					continue;
				}

				$items[] = array(
					'element'       => 'shortcode',
					'raw_href'      => $url,
					'occurrence'    => $this->next_occurrence( $counts, 'shortcode', $url ),
					'anchor_text'   => $text,
					'shortcode_tag' => $tag,
				);
			}

			// Builder rows and columns wrap the shortcodes that hold the links.
			if ( '' !== $match[5] && preg_match_all( '/' . get_shortcode_regex( array_keys( $known ) ) . '/', $match[5], $inner, PREG_SET_ORDER ) ) {
				$this->collect_shortcodes( $inner, $known, $items, $counts );
			}
		}
	}

	/**
	 * Reads the URL from a shortcode attribute value.
	 *
	 * WPBakery link values look like `url:https%3A%2F%2Fexample.com|title:Buy|target:_blank`.
	 *
	 * @param string $value Attribute value.
	 * @return string Decoded URL, or an empty string.
	 */
	private function shortcode_url( $value ) {
		$value = trim( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( 0 === strpos( $value, 'url:' ) ) {
			foreach ( explode( '|', $value ) as $pair ) {
				if ( 0 === strpos( $pair, 'url:' ) ) {
					return trim( urldecode( substr( $pair, 4 ) ) );
				}
			}
		}

		return $value;
	}

	/**
	 * Returns the next occurrence number for a kind and value.
	 *
	 * @param array  $counts Counters, by reference.
	 * @param string $kind   "a", "img" or "shortcode".
	 * @param string $value  Raw value.
	 * @return int
	 */
	private function next_occurrence( array &$counts, $kind, $value ) {
		$key            = $kind . '|' . $value;
		$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;

		return $counts[ $key ];
	}

	/**
	 * Turns HTML into one line of plain text, up to MAX_ANCHOR_TEXT characters.
	 *
	 * @param string $html HTML fragment.
	 * @return string
	 */
	private function clean_text( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return mb_substr( $text, 0, self::MAX_ANCHOR_TEXT );
	}
}
