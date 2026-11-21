<?php
/**
 * Tools → Broken Links screen.
 *
 * @package SeekFix
 */

namespace SeekFix\Admin;

use SeekFix\Data\LinkRepository;

/**
 * Registers the admin screen and loads its React app.
 *
 * Nothing is loaded on public pages, and in the admin the scripts and styles
 * load only on this screen.
 */
class AdminPage {

	/**
	 * Menu slug.
	 */
	const SLUG = 'seekfix';

	/**
	 * Transient caching the number of broken URLs shown in the menu.
	 */
	const COUNT_TRANSIENT = 'seekfix_broken_count';

	/**
	 * Script handle.
	 */
	const HANDLE = 'seekfix-admin';

	/**
	 * Screen hook suffix, set when the page is added.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers the hooks.
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SEEKFIX_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds Tools → Broken Links, with a bubble counting broken URLs.
	 */
	public function add_page() {
		$count = self::broken_count();
		$title = __( 'Broken Links', 'seekfix' );

		if ( $count > 0 ) {
			$title .= sprintf(
				' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%2$s</span><span class="screen-reader-text">%3$s</span></span>',
				$count,
				number_format_i18n( $count ),
				/* translators: %s: number of broken links. */
				esc_html( sprintf( _n( '%s broken link', '%s broken links', $count, 'seekfix' ), number_format_i18n( $count ) ) )
			);
		}

		$this->hook = (string) add_management_page(
			__( 'Broken Links', 'seekfix' ),
			$title,
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Prints the container the React app mounts in.
	 */
	public function render() {
		echo '<div class="wrap seekfix-wrap"><h1>' . esc_html__( 'Broken Links', 'seekfix' ) . '</h1><div id="seekfix-root"></div></div>';
	}

	/**
	 * Loads the app on this screen only.
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public function enqueue( $hook_suffix ) {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		$dir   = plugin_dir_path( SEEKFIX_FILE ) . 'assets/build/';
		$url   = plugin_dir_url( SEEKFIX_FILE ) . 'assets/build/';
		$asset = is_readable( $dir . 'admin.asset.php' ) ? require $dir . 'admin.asset.php' : null;

		if ( ! is_array( $asset ) ) {
			return;
		}

		$this->register_jsx_runtime( $dir, $url );

		wp_enqueue_script( self::HANDLE, $url . 'admin.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( self::HANDLE, $url . 'admin.css', array(), $asset['version'] );
		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		wp_set_script_translations( self::HANDLE, 'seekfix', plugin_dir_path( SEEKFIX_FILE ) . 'languages' );

		wp_add_inline_script(
			self::HANDLE,
			'window.seekfixAdmin = ' . wp_json_encode( $this->boot_data() ) . ';',
			'before'
		);
	}

	/**
	 * Adds a link to the screen on the Plugins page.
	 *
	 * @param string[] $links Action links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'tools.php?page=' . self::SLUG ) ), esc_html__( 'Broken Links', 'seekfix' ) )
		);

		return $links;
	}

	/**
	 * Number of broken URLs still in use, cached for five minutes.
	 *
	 * @return int
	 */
	public static function broken_count() {
		$count = get_transient( self::COUNT_TRANSIENT );

		if ( false === $count ) {
			$count = ( new LinkRepository() )->count_broken_in_use();
			set_transient( self::COUNT_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Drops the cached count after a change.
	 */
	public static function forget_count() {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Registers react-jsx-runtime on WordPress before 6.6, which lacks it.
	 *
	 * The shim maps jsx() to React.createElement() of the React that
	 * WordPress loads, so there is still one React on the page.
	 *
	 * @param string $dir Build directory.
	 * @param string $url Build URL.
	 */
	private function register_jsx_runtime( $dir, $url ) {
		if ( wp_script_is( 'react-jsx-runtime', 'registered' ) || ! is_readable( $dir . 'jsx-runtime-shim.asset.php' ) ) {
			return;
		}

		$asset = require $dir . 'jsx-runtime-shim.asset.php';

		wp_register_script( 'react-jsx-runtime', $url . 'jsx-runtime-shim.js', $asset['dependencies'], $asset['version'], true );
	}

	/**
	 * Data the app needs before its first request.
	 *
	 * @return array<string, mixed>
	 */
	private function boot_data() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$types[] = array(
					'value' => $type->name,
					'label' => $type->labels->singular_name,
				);
			}
		}

		return array(
			'postTypes'    => $types,
			'revisionsUrl' => admin_url( 'revision.php' ),
		);
	}
}
