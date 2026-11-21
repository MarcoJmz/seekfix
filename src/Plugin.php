<?php
/**
 * Plugin bootstrap.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Registers hooks and services. The single entry point of the plugin.
 */
class Plugin {

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private static $booted = false;

	/**
	 * Post indexer.
	 *
	 * @var PostIndexer|null
	 */
	private static $indexer = null;

	/**
	 * Registers the plugin's hooks. Runs on plugins_loaded.
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		if ( is_admin() ) {
			Installer::maybe_upgrade();
		}

		self::indexer()->register_hooks();
	}

	/**
	 * Shared post indexer.
	 *
	 * @return PostIndexer
	 */
	public static function indexer() {
		if ( null === self::$indexer ) {
			self::$indexer = new PostIndexer();
		}

		return self::$indexer;
	}
}
