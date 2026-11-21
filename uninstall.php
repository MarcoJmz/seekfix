<?php
/**
 * Removes every table, option and transient the plugin created.
 *
 * Post content and revisions are never touched.
 *
 * @package SeekFix
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

foreach ( array( 'links', 'instances', 'actions' ) as $seekfix_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own tables on uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'seekfix_' . $seekfix_table ) );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the plugin's own options and transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'seekfix_' ) . '%',
		$wpdb->esc_like( '_transient_seekfix_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_seekfix_' ) . '%'
	)
);

wp_cache_flush();
