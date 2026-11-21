<?php
/**
 * Plugin settings.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Reads the seekfix_settings option, with defaults.
 */
class Settings {

	/**
	 * Option name. Stored without autoload.
	 */
	const OPTION = 'seekfix_settings';

	/**
	 * Post statuses scanned by default.
	 */
	const DEFAULT_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Returns the raw settings array.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$settings = get_option( self::OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Post types to scan.
	 *
	 * Defaults to every public post type except attachments.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$settings = self::all();
		$public   = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		$types    = isset( $settings['post_types'] ) && is_array( $settings['post_types'] )
			? array_values( array_intersect( $settings['post_types'], $public ) )
			: $public;

		/**
		 * Filters the post types that SeekFix scans.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_unique( (array) apply_filters( 'seekfix_scan_post_types', $types ) ) );
	}

	/**
	 * Post statuses to scan: publish, future and private, plus drafts if enabled.
	 *
	 * @return string[]
	 */
	public static function post_statuses() {
		$settings = self::all();
		$statuses = self::DEFAULT_STATUSES;

		if ( ! empty( $settings['include_drafts'] ) ) {
			$statuses[] = 'draft';
			$statuses[] = 'pending';
		}

		return $statuses;
	}
}
