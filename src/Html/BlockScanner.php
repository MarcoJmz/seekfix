<?php
/**
 * Block delimiter scanner with byte offsets.
 *
 * @package SeekFix
 */

namespace SeekFix\Html;

/**
 * Lists the blocks in post content with the exact byte span of each one.
 *
 * The core parse_blocks() returns the block tree but no offsets, and serialize_blocks()
 * re-encodes block attributes, which can change bytes outside the edited link.
 * This scanner reads the block delimiters with the same grammar as
 * WP_Block_Parser so that editors can cut or patch one block in place.
 */
class BlockScanner {

	/**
	 * Block delimiter grammar, copied from WP_Block_Parser::next_token().
	 */
	const DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Scans content and returns its blocks in document order.
	 *
	 * Each block is an array with:
	 * - `name`: full block name, for example "core/button".
	 * - `start` / `end`: byte span of the whole block, `end` exclusive.
	 * - `opener_start` / `opener_end`: byte span of the opening delimiter.
	 * - `attrs_start` / `attrs_length`: byte span of the JSON attributes, or -1 / 0.
	 * - `parent`: index of the parent block in the returned list, or null.
	 *
	 * Openers without a closer and stray closers are left out, so a malformed
	 * block is never cut.
	 *
	 * @param string $content Post content.
	 * @return array<int, array<string, mixed>>
	 */
	public static function scan( $content ) {
		if ( false === strpos( $content, '<!--' ) ) {
			return array();
		}

		if ( ! preg_match_all( self::DELIMITER, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$blocks = array();
		$stack  = array();

		foreach ( $matches as $match ) {
			$offset    = $match[0][1];
			$length    = strlen( $match[0][0] );
			$namespace = isset( $match['namespace'] ) && '' !== $match['namespace'][0] ? $match['namespace'][0] : 'core/';
			$name      = $namespace . $match['name'][0];
			$is_closer = isset( $match['closer'] ) && '' !== $match['closer'][0];
			$is_void   = isset( $match['void'] ) && '' !== $match['void'][0];

			if ( $is_closer ) {
				// Close the nearest open block with this name; drop unclosed ones above it.
				for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
					$index = $stack[ $i ];

					if ( $blocks[ $index ]['name'] === $name ) {
						$blocks[ $index ]['end'] = $offset + $length;
						$stack                   = array_slice( $stack, 0, $i );
						break;
					}
				}
				continue;
			}

			$has_attrs = isset( $match['attrs'] ) && '' !== $match['attrs'][0] && -1 !== $match['attrs'][1];

			$blocks[] = array(
				'name'         => $name,
				'start'        => $offset,
				'end'          => $is_void ? $offset + $length : null,
				'opener_start' => $offset,
				'opener_end'   => $offset + $length,
				'attrs_start'  => $has_attrs ? $match['attrs'][1] : -1,
				'attrs_length' => $has_attrs ? strlen( rtrim( $match['attrs'][0] ) ) : 0,
				'parent'       => empty( $stack ) ? null : end( $stack ),
			);

			if ( ! $is_void ) {
				$stack[] = count( $blocks ) - 1;
			}
		}

		// Remove blocks that never closed, then remap parent indexes.
		$kept  = array();
		$remap = array();

		foreach ( $blocks as $index => $block ) {
			if ( null === $block['end'] ) {
				continue;
			}

			$remap[ $index ] = count( $kept );
			$kept[]          = $block;
		}

		foreach ( $kept as &$block ) {
			if ( null !== $block['parent'] ) {
				$block['parent'] = isset( $remap[ $block['parent'] ] ) ? $remap[ $block['parent'] ] : null;
			}
		}
		unset( $block );

		return $kept;
	}

	/**
	 * Returns the index of the innermost block that contains a byte offset.
	 *
	 * @param array<int, array<string, mixed>> $blocks Blocks from scan().
	 * @param int                              $offset Byte offset.
	 * @return int|null
	 */
	public static function innermost_at( array $blocks, $offset ) {
		$found = null;

		foreach ( $blocks as $index => $block ) {
			if ( $block['opener_end'] <= $offset && $offset < $block['end'] ) {
				// Blocks are in document order, so a later match is nested deeper.
				$found = $index;
			}
		}

		return $found;
	}

	/**
	 * Returns the indexes of the blocks that share a parent, in document order.
	 *
	 * @param array<int, array<string, mixed>> $blocks       Blocks from scan().
	 * @param int|null                         $parent_index Parent index, or null for top level.
	 * @return int[]
	 */
	public static function children_of( array $blocks, $parent_index ) {
		$children = array();

		foreach ( $blocks as $index => $block ) {
			if ( $block['parent'] === $parent_index ) {
				$children[] = $index;
			}
		}

		return $children;
	}
}
