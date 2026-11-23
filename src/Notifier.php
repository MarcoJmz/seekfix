<?php
/**
 * E-mail about new broken links.
 *
 * @package SeekFix
 */

namespace SeekFix;

use SeekFix\Data\InstanceRepository;
use SeekFix\Data\LinkRepository;

/**
 * Sends one e-mail per scan with the broken links found since the last one.
 *
 * Each URL is reported once. It is reported again only after a check finds
 * it working and a later one finds it broken (LinkRepository::save_check()
 * clears notified_at). If wp_mail() fails, nothing is marked and the next
 * scan tries again.
 */
class Notifier {

	/**
	 * URLs listed in the e-mail; the rest are only counted.
	 */
	const MAX_LISTED = 25;

	/**
	 * Posts named for each URL.
	 */
	const MAX_POSTS = 3;

	/**
	 * URL rows.
	 *
	 * @var LinkRepository
	 */
	private $links;

	/**
	 * Instance rows.
	 *
	 * @var InstanceRepository
	 */
	private $instances;

	/**
	 * Creates the notifier. Dependencies can be replaced in tests.
	 *
	 * @param LinkRepository|null     $links     URL rows.
	 * @param InstanceRepository|null $instances Instance rows.
	 */
	public function __construct( $links = null, $instances = null ) {
		$this->links     = $links ? $links : new LinkRepository();
		$this->instances = $instances ? $instances : new InstanceRepository();
	}

	/**
	 * Sends the e-mail when a scan finishes.
	 */
	public function register_hooks() {
		add_action( 'seekfix_scan_finished', array( $this, 'send' ) );
	}

	/**
	 * Sends the e-mail if it is on and there are broken URLs not reported yet.
	 *
	 * @return int URLs reported; 0 when nothing was sent.
	 */
	public function send() {
		$recipients = Settings::notification_recipients();

		if ( empty( $recipients ) ) {
			return 0;
		}

		$ids = $this->links->unnotified_broken_ids();

		if ( empty( $ids ) ) {
			return 0;
		}

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$email = array(
			'to'      => $recipients,
			'subject' => sprintf(
				/* translators: 1: site name, 2: number of links. */
				_n( '[%1$s] %2$s new broken link', '[%1$s] %2$s new broken links', count( $ids ), 'seekfix' ),
				$site,
				number_format_i18n( count( $ids ) )
			),
			'message' => $this->message( $ids, $site ),
			'headers' => array(),
		);

		/**
		 * Filters the broken link e-mail before it is sent.
		 *
		 * Return an empty "to" to skip it; the URLs are then reported next time.
		 *
		 * @param array $email   to, subject, message and headers for wp_mail().
		 * @param int[] $link_ids Rows in seekfix_links being reported.
		 */
		$email = (array) apply_filters( 'seekfix_notification_email', $email, $ids );

		if ( empty( $email['to'] ) || ! wp_mail( $email['to'], (string) $email['subject'], (string) $email['message'], isset( $email['headers'] ) ? $email['headers'] : array() ) ) {
			return 0;
		}

		$this->links->mark_notified( $ids, current_time( 'mysql', true ) );

		return count( $ids );
	}

	/**
	 * Plain-text body: each URL with why it failed and where it appears.
	 *
	 * @param int[]  $ids  Rows in seekfix_links.
	 * @param string $site Site name.
	 * @return string
	 */
	private function message( array $ids, $site ) {
		$lines = array(
			sprintf(
				/* translators: 1: number of links, 2: site name. */
				_n( 'SeekFix found %1$s new broken link on %2$s.', 'SeekFix found %1$s new broken links on %2$s.', count( $ids ), 'seekfix' ),
				number_format_i18n( count( $ids ) ),
				$site
			),
			'',
		);

		foreach ( array_slice( $ids, 0, self::MAX_LISTED ) as $id ) {
			$link = $this->links->get( $id );

			if ( ! $link ) {
				continue;
			}

			$lines[] = $link->url;
			$lines[] = '  ' . $this->reason( $link );

			$titles = $this->post_titles( $id );

			if ( $titles ) {
				/* translators: %s: post titles, comma separated. */
				$lines[] = '  ' . sprintf( __( 'In: %s', 'seekfix' ), $titles );
			}

			$lines[] = '';
		}

		$more = count( $ids ) - self::MAX_LISTED;

		if ( $more > 0 ) {
			/* translators: %s: number of links. */
			$lines[] = sprintf( _n( 'And %s more.', 'And %s more.', $more, 'seekfix' ), number_format_i18n( $more ) );
			$lines[] = '';
		}

		$lines[] = __( 'Review and fix them:', 'seekfix' );
		$lines[] = admin_url( 'tools.php?page=' . Admin\AdminPage::SLUG . '&tab=links' );
		$lines[] = '';
		$lines[] = __( 'You get this e-mail because broken link e-mails are on in Tools > Broken Links > Settings.', 'seekfix' );

		return implode( "\n", $lines );
	}

	/**
	 * Why a URL is broken, in a few words.
	 *
	 * @param object $link Row in seekfix_links.
	 * @return string
	 */
	private function reason( $link ) {
		if ( ! empty( $link->http_code ) ) {
			/* translators: %d: HTTP status code. */
			return sprintf( __( 'HTTP %d', 'seekfix' ), (int) $link->http_code );
		}

		if ( ! empty( $link->error_code ) ) {
			/* translators: %s: error code, such as "dns". */
			return sprintf( __( 'Error: %s', 'seekfix' ), $link->error_code );
		}

		return __( 'Broken', 'seekfix' );
	}

	/**
	 * Titles of the first posts where a URL appears, plus how many more.
	 *
	 * @param int $link_id Row in seekfix_links.
	 * @return string
	 */
	private function post_titles( $link_id ) {
		$post_ids = array_values( array_unique( array_map( 'intval', wp_list_pluck( $this->instances->for_link( $link_id ), 'post_id' ) ) ) );
		$titles   = array();

		foreach ( array_slice( $post_ids, 0, self::MAX_POSTS ) as $post_id ) {
			$title    = wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES );
			$titles[] = '' !== $title ? $title : sprintf( '#%d', $post_id );
		}

		$text = implode( ', ', $titles );
		$more = count( $post_ids ) - self::MAX_POSTS;

		if ( $more > 0 ) {
			/* translators: 1: post titles, 2: number of other posts. */
			$text = sprintf( _n( '%1$s and %2$s more post', '%1$s and %2$s more posts', $more, 'seekfix' ), $text, number_format_i18n( $more ) );
		}

		return $text;
	}
}
