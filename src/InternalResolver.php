<?php
/**
 * Resolves internal links without HTTP.
 *
 * @package SeekFix
 */

namespace SeekFix;

/**
 * Checks whether an internal URL points to existing content, using the database.
 *
 * Answers only "exists" or "don't know": a URL it cannot resolve may belong to
 * another plugin's route, so the Checker then requests it over HTTP. A 404
 * from that request is broken at once, with no confirmation wait.
 */
class InternalResolver {

	/**
	 * Whether an internal URL is known to work.
	 *
	 * @param string $url Normalized internal URL.
	 * @return bool True when the content exists; false when HTTP must decide.
	 */
	public function resolves( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? $parts['query'] : '';
		$home  = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home  = is_string( $home ) ? $home : '/';

		if ( '' === $query && untrailingslashit( $path ) === untrailingslashit( $home ) ) {
			return true;
		}

		$post_id = url_to_postid( $url );

		if ( $post_id ) {
			$status = get_post_status( $post_id );

			// Private and draft content gives visitors a 404: let HTTP decide.
			if ( 'publish' === $status || ( 'attachment' === get_post_type( $post_id ) && 'inherit' === $status ) ) {
				return true;
			}
		}

		if ( $this->upload_exists( $url ) ) {
			return true;
		}

		return '' === $query && $this->term_exists_for_path( $path );
	}

	/**
	 * Whether a URL inside the uploads folder is a file on disk.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function upload_exists( $url ) {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$base = set_url_scheme( trailingslashit( $uploads['baseurl'] ), 'https' );
		$url  = set_url_scheme( strtok( $url, '?' ), 'https' );

		if ( 0 !== strpos( $url, $base ) ) {
			return false;
		}

		$relative = rawurldecode( substr( $url, strlen( $base ) ) );

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return false;
		}

		return is_file( trailingslashit( $uploads['basedir'] ) . $relative );
	}

	/**
	 * Whether a path is the archive of an existing term, like /category/news/.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	private function term_exists_for_path( $path ) {
		$home     = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home     = is_string( $home ) ? trailingslashit( $home ) : '/';
		$path     = '/' . ltrim( 0 === strpos( $path, $home ) ? substr( $path, strlen( $home ) ) : $path, '/' );
		$segments = array_values( array_filter( explode( '/', trim( $path, '/' ) ), 'strlen' ) );

		if ( count( $segments ) < 2 ) {
			return false;
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$slug = is_array( $taxonomy->rewrite ) && ! empty( $taxonomy->rewrite['slug'] ) ? trim( $taxonomy->rewrite['slug'], '/' ) : '';

			if ( '' === $slug ) {
				continue;
			}

			$slug_parts = explode( '/', $slug );

			if ( array_slice( $segments, 0, count( $slug_parts ) ) !== $slug_parts ) {
				continue;
			}

			$rest = array_slice( $segments, count( $slug_parts ) );

			// Skip pagination: /category/news/page/2/.
			if ( count( $rest ) >= 2 && 'page' === $rest[ count( $rest ) - 2 ] ) {
				$rest = array_slice( $rest, 0, -2 );
			}

			if ( empty( $rest ) ) {
				continue;
			}

			$term = get_term_by( 'slug', sanitize_title( rawurldecode( end( $rest ) ) ), $taxonomy->name );

			if ( $term ) {
				return true;
			}
		}

		return false;
	}
}
