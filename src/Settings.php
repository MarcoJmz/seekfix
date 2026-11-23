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

	/**
	 * Addresses that get the broken link e-mail, or none when it is off.
	 *
	 * With no address saved, the e-mail goes to the site's admin address.
	 *
	 * @return string[]
	 */
	public static function notification_recipients() {
		$settings = self::for_editing();

		if ( ! $settings['email_enabled'] ) {
			return array();
		}

		$recipients = $settings['email_recipients'] ? $settings['email_recipients'] : array( (string) get_option( 'admin_email' ) );

		return array_values( array_filter( $recipients, 'is_email' ) );
	}

	/**
	 * Scan schedules.
	 */
	const SCHEDULES = array( 'off', 'daily', 'weekly' );

	/**
	 * Settings as the admin screen edits them, with defaults filled in.
	 *
	 * `post_types` is null while the user has not picked any, meaning every
	 * public type.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_editing() {
		$settings = self::all();
		$rules    = isset( $settings['host_rules'] ) && is_array( $settings['host_rules'] ) ? $settings['host_rules'] : array();

		return array(
			'post_types'       => isset( $settings['post_types'] ) && is_array( $settings['post_types'] ) ? array_values( $settings['post_types'] ) : null,
			'include_drafts'   => ! empty( $settings['include_drafts'] ),
			'schedule'         => isset( $settings['schedule'] ) && in_array( $settings['schedule'], self::SCHEDULES, true ) ? $settings['schedule'] : 'off',
			'email_enabled'    => ! empty( $settings['email_enabled'] ),
			'email_recipients' => isset( $settings['email_recipients'] ) ? array_values( (array) $settings['email_recipients'] ) : array(),
			'host_rules'       => array(
				HostRules::UNVERIFIABLE => isset( $rules[ HostRules::UNVERIFIABLE ] ) ? array_values( (array) $rules[ HostRules::UNVERIFIABLE ] ) : array(),
				HostRules::EXCLUDE      => isset( $rules[ HostRules::EXCLUDE ] ) ? array_values( (array) $rules[ HostRules::EXCLUDE ] ) : array(),
			),
		);
	}

	/**
	 * Saves the settings the admin screen sends. Unknown keys are dropped.
	 *
	 * @param array<string, mixed> $input Values to change; missing keys keep their value.
	 * @return array<string, mixed> Saved settings, as for_editing() returns them.
	 */
	public static function update( array $input ) {
		$settings = array_merge( self::for_editing(), array_intersect_key( $input, self::for_editing() ) );
		$public   = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

		$clean = array(
			'post_types'       => is_array( $settings['post_types'] ) ? array_values( array_intersect( array_map( 'strval', $settings['post_types'] ), $public ) ) : null,
			'include_drafts'   => (bool) $settings['include_drafts'],
			'schedule'         => in_array( $settings['schedule'], self::SCHEDULES, true ) ? $settings['schedule'] : 'off',
			'email_enabled'    => (bool) $settings['email_enabled'],
			'email_recipients' => array_values( array_unique( array_filter( array_map( 'sanitize_email', (array) $settings['email_recipients'] ), 'is_email' ) ) ),
			'host_rules'       => array(),
		);

		foreach ( array( HostRules::UNVERIFIABLE, HostRules::EXCLUDE ) as $kind ) {
			$hosts = isset( $settings['host_rules'][ $kind ] ) ? (array) $settings['host_rules'][ $kind ] : array();

			$clean['host_rules'][ $kind ] = array_values( array_unique( array_filter( array_map( array( HostRules::class, 'clean_host' ), array_map( 'strval', $hosts ) ) ) ) );
		}

		if ( null === $clean['post_types'] ) {
			unset( $clean['post_types'] );
		}

		update_option( self::OPTION, $clean, false );
		Scheduler::sync();

		return self::for_editing();
	}
}
