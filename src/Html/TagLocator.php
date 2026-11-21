<?php
/**
 * Byte-exact tag locator built on the WordPress HTML API.
 *
 * @package SeekFix
 */

namespace SeekFix\Html;

/**
 * Finds tags in an HTML string and reports their exact byte spans.
 *
 * WP_HTML_Tag_Processor can change attributes but cannot remove tags or
 * report where a tag starts and ends. Its bookmarks hold that information in a
 * protected property, so this subclass reads them. That lets the editors cut
 * exactly the bytes of one tag and leave every other byte untouched.
 *
 * Works on WordPress 6.4 (spans with `start` and inclusive `end`) and 6.5+
 * (spans with `start` and `length`).
 */
class TagLocator extends \WP_HTML_Tag_Processor {

	/**
	 * Bookmark name used internally to read the span of the current tag.
	 */
	const BOOKMARK = 'seekfix_span';

	/**
	 * Moves to the next opening tag with the given name, in any letter case.
	 *
	 * WordPress 6.4 skips uppercase tags such as `<A HREF>` when next_tag()
	 * gets a `tag_name` query, so the name is compared with get_tag() instead.
	 *
	 * @param string $tag_name Tag name, for example "a" or "img".
	 * @return bool Whether a tag was found.
	 */
	public function next_named_tag( $tag_name ) {
		$wanted = strtoupper( $tag_name );

		while ( $this->next_tag() ) {
			if ( $this->get_tag() === $wanted ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Moves to the Nth tag (1-based) whose attribute equals the given value.
	 *
	 * The comparison uses the decoded value returned by get_attribute(), so
	 * `href="?a=1&amp;b=2"` matches `?a=1&b=2`. The Extractor counts
	 * occurrences the same way.
	 *
	 * @param string $tag_name   Tag name, for example "a" or "img".
	 * @param string $attribute  Attribute name, for example "href" or "src".
	 * @param string $value      Decoded attribute value to match.
	 * @param int    $occurrence 1-based occurrence among matching tags.
	 * @return bool Whether the tag was found. On success the processor stays on it.
	 */
	public function find_nth( $tag_name, $attribute, $value, $occurrence ) {
		$seen = 0;

		while ( $this->next_named_tag( $tag_name ) ) {
			if ( $this->get_attribute( $attribute ) !== $value ) {
				continue;
			}

			++$seen;

			if ( $seen === $occurrence ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the byte span of the tag the processor is on.
	 *
	 * Offsets refer to the original HTML, so call this before any attribute
	 * change on the same processor.
	 *
	 * @return array{start:int,length:int}|null Span covering `<` through `>`, or null.
	 */
	public function current_span() {
		if ( ! $this->set_bookmark( self::BOOKMARK ) ) {
			return null;
		}

		$span = $this->bookmarks[ self::BOOKMARK ];
		$this->release_bookmark( self::BOOKMARK );

		if ( isset( $span->length ) ) {
			$length = (int) $span->length;
		} else {
			// WordPress 6.4: `end` is the offset of the closing `>`.
			$length = (int) $span->end - (int) $span->start + 1;
		}

		$start = (int) $span->start;

		// Sanity check: a tag span always runs from `<` to `>`.
		if ( '<' !== substr( $this->html, $start, 1 ) || '>' !== substr( $this->html, $start + $length - 1, 1 ) ) {
			return null;
		}

		return array(
			'start'  => $start,
			'length' => $length,
		);
	}

	/**
	 * From an opening tag, moves to its matching closing tag.
	 *
	 * Counts nested tags of the same name so the right closer is found even in
	 * malformed markup.
	 *
	 * @return bool Whether a matching closer was found.
	 */
	public function seek_matching_closer() {
		$tag_name = $this->get_tag();

		if ( null === $tag_name || $this->is_tag_closer() ) {
			return false;
		}

		$depth = 1;

		while ( $this->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
			if ( $this->get_tag() !== $tag_name ) {
				continue;
			}

			$depth += $this->is_tag_closer() ? -1 : 1;

			if ( 0 === $depth ) {
				return true;
			}
		}

		return false;
	}
}
