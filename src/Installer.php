<?php
/**
 * Tables, requirements and schema upgrades.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Creates and upgrades the plugin tables.
 *
 * The tables only hold data that a new scan can rebuild, and every schema
 * change is additive, so dbDelta() is enough to bring any older schema up to
 * date.
 */
class Installer {

	/**
	 * Schema version stored in the seekfix_db_version option.
	 */
	const DB_VERSION = '3';

	/**
	 * Option that stores the installed schema version.
	 */
	const DB_VERSION_OPTION = 'seekfix_db_version';

	/**
	 * Minimum versions checked on activation.
	 */
	const MIN_WP      = '6.4';
	const MIN_PHP     = '7.4';
	const MIN_MYSQL   = '5.7';
	const MIN_MARIADB = '10.3';

	/**
	 * Activation hook: checks requirements and creates the tables.
	 *
	 * Starts no scan; the admin starts the first one.
	 */
	public static function activate() {
		$problems = self::requirement_problems();

		if ( ! empty( $problems ) ) {
			deactivate_plugins( plugin_basename( SEEKFIX_FILE ) );
			wp_die(
				'<p>' . esc_html__( 'SeekFix could not be activated:', 'seekfix' ) . '</p><ul><li>' . implode( '</li><li>', array_map( 'esc_html', $problems ) ) . '</li></ul>',
				esc_html__( 'Plugin activation failed', 'seekfix' ),
				array( 'back_link' => true )
			);
		}

		self::install();
	}

	/**
	 * Creates or upgrades the tables when the stored schema version is older.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or upgrades the three tables and records the schema version.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::table( 'links' );
		$inst    = self::table( 'instances' );
		$actions = self::table( 'actions' );

		// dbDelta() needs one field per line and two spaces after PRIMARY KEY.
		dbDelta(
			"CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url text NOT NULL,
  url_hash char(40) NOT NULL,
  host varchar(191) NOT NULL DEFAULT '',
  is_internal tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  http_code smallint(5) unsigned DEFAULT NULL,
  error_code varchar(50) DEFAULT NULL,
  ssl_invalid tinyint(1) NOT NULL DEFAULT 0,
  final_url text DEFAULT NULL,
  redirect_chain text DEFAULT NULL,
  fail_count tinyint(3) unsigned NOT NULL DEFAULT 0,
  fail_days tinyint(3) unsigned NOT NULL DEFAULT 0,
  first_failure_at datetime DEFAULT NULL,
  last_checked_at datetime DEFAULT NULL,
  next_check_at datetime DEFAULT NULL,
  notified_at datetime DEFAULT NULL,
  ignored tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY status_ignored (status,ignored),
  KEY next_check_at (next_check_at),
  KEY host (host)
) {$charset};
CREATE TABLE {$inst} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  link_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  element varchar(20) NOT NULL,
  shortcode_tag varchar(64) DEFAULT NULL,
  anchor_text varchar(255) NOT NULL DEFAULT '',
  raw_href text NOT NULL,
  occurrence smallint(5) unsigned NOT NULL DEFAULT 1,
  content_hash char(40) NOT NULL,
  PRIMARY KEY  (id),
  KEY link_id (link_id),
  KEY post_id (post_id)
) {$charset};
CREATE TABLE {$actions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  link_id bigint(20) unsigned DEFAULT NULL,
  action varchar(20) NOT NULL,
  old_url text NOT NULL,
  new_url text DEFAULT NULL,
  revision_id bigint(20) unsigned DEFAULT NULL,
  old_content longtext DEFAULT NULL,
  content_hash char(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  undone_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id)
) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Full name of a plugin table.
	 *
	 * @param string $name "links", "instances" or "actions".
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'seekfix_' . $name;
	}

	/**
	 * Lists the unmet requirements, as messages for the admin.
	 *
	 * @return string[]
	 */
	public static function requirement_problems() {
		global $wp_version, $wpdb;

		$problems = array();

		if ( version_compare( $wp_version, self::MIN_WP, '<' ) ) {
			/* translators: 1: required WordPress version, 2: installed version. */
			$problems[] = sprintf( __( 'WordPress %1$s or later is required. This site runs %2$s.', 'seekfix' ), self::MIN_WP, $wp_version );
		}

		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: installed version. */
			$problems[] = sprintf( __( 'PHP %1$s or later is required. This server runs %2$s.', 'seekfix' ), self::MIN_PHP, PHP_VERSION );
		}

		$server = method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : '';
		$is_ma  = false !== stripos( $server, 'mariadb' );
		$db     = $wpdb->db_version();
		$min    = $is_ma ? self::MIN_MARIADB : self::MIN_MYSQL;

		if ( $is_ma && preg_match( '/(\d+\.\d+\.\d+)-MariaDB/i', $server, $match ) ) {
			// Older MariaDB builds report "5.5.5-10.x.y-MariaDB".
			$db = $match[1];
		}

		if ( $db && version_compare( $db, $min, '<' ) ) {
			$problems[] = sprintf(
				/* translators: 1: database name (MySQL or MariaDB), 2: required version, 3: installed version. */
				__( '%1$s %2$s or later is required. This server runs %3$s.', 'seekfix' ),
				$is_ma ? 'MariaDB' : 'MySQL',
				$min,
				$db
			);
		}

		return $problems;
	}
}
