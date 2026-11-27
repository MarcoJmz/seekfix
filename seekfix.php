<?php
/**
 * Plugin Name:       SeekFix – Broken Link Checker & Fixer
 * Plugin URI:        https://github.com/MarcoJmz/seekfix
 * Description:       Finds and fixes broken links without leaving WordPress. Every fix can be undone.
 * Author:            MarcoJmz
 * Author URI:        https://github.com/MarcoJmz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seekfix
 * Domain Path:       /languages
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 *
 * @package SeekFix
 */

defined( 'ABSPATH' ) || exit;

define( 'SEEKFIX_VERSION', '0.1.0' );
define( 'SEEKFIX_FILE', __FILE__ );

/*
 * PSR-4 autoloader for the SeekFix\ namespace in src/. The published plugin
 * ships without Composer's vendor/ folder, so it loads its own classes.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SeekFix\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SeekFix\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SeekFix\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'SeekFix\\Plugin', 'boot' ) );
