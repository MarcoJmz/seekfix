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

		if ( '' !== $this->hook ) {
			add_action( 'load-' . $this->hook, array( $this, 'add_help' ) );
		}
	}

	/**
	 * Adds the screen's Help tab: what each part of the screen does.
	 */
	public function add_help() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$tabs = array(
			'seekfix-overview' => array(
				__( 'Overview', 'seekfix' ),
				array(
					__( 'SeekFix finds broken links in your content and lets you fix them from one screen. A scan has two steps: it reads your posts and pages to collect every link, button and image, then checks each different URL once.', 'seekfix' ),
					__( 'The checks run on your server. Nothing is sent to an external service. A link that fails is checked again later before it is marked as broken, so a site that is down for a moment is not reported.', 'seekfix' ),
				),
			),
			'seekfix-summary'  => array(
				__( 'Summary', 'seekfix' ),
				array(
					__( 'Shows how many URLs are broken, cannot be verified or redirect, and the progress of the current scan. Click a count to see those links in the Links tab.', 'seekfix' ),
					__( 'Start scan reads all the selected content. Pause stops the scan and Resume continues it where it stopped; Cancel ends it and keeps the results found so far.', 'seekfix' ),
					__( '"Cannot verify" means the other site blocked the check or did not answer, which many large sites do with automated requests. These links may work in a browser: open them to check by hand.', 'seekfix' ),
				),
			),
			'seekfix-links'    => array(
				__( 'Links', 'seekfix' ),
				array(
					__( 'Lists each link with its status, the post it is in and its text. Filter by status, content type, domain or internal and external links, search by URL or link text, and export the list as CSV.', 'seekfix' ),
					__( 'Fixes: Remove link keeps the text and removes the link; Remove button removes a button block; Replace URL changes the address; Update to final URL points a redirect straight to where it ends. Group by URL fixes the same URL in every post at once.', 'seekfix' ),
					__( 'Before each fix the post gets a revision. Ignore hides a link you do not want to fix; Check again now checks it right away.', 'seekfix' ),
				),
			),
			'seekfix-history'  => array(
				__( 'History', 'seekfix' ),
				array(
					__( 'Lists every fix made from the Links tab: when, by whom, in which post and what changed. Undo puts the post back as it was before the fix.', 'seekfix' ),
					__( 'A fix can be undone only while the post is exactly as the fix left it. If the post was edited afterwards, Compare revisions opens the WordPress revisions screen so you can restore it by hand without losing those edits.', 'seekfix' ),
					__( 'Fixes older than the period chosen in Settings are removed from the history once a day. The revisions WordPress saved stay with each post.', 'seekfix' ),
				),
			),
			'seekfix-settings' => array(
				__( 'Settings', 'seekfix' ),
				array(
					__( 'Choose the content types to scan, whether drafts are included, how often a scan runs on its own, who gets an e-mail about new broken links, how long the fix history is kept, and domain rules for sites that block checks or should never be checked.', 'seekfix' ),
					__( 'Automatic scans use WP-Cron. If your site turns WP-Cron off, keep the Summary tab open while a scan runs, or set up a server cron job.', 'seekfix' ),
				),
			),
		);

		foreach ( $tabs as $id => $tab ) {
			$screen->add_help_tab(
				array(
					'id'      => $id,
					'title'   => $tab[0],
					'content' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $tab[1] ) ) . '</p>',
				)
			);
		}
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
			'adminEmail'   => (string) get_option( 'admin_email' ),
			'cronDisabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}
}
