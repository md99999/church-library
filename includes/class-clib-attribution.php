<?php
/**
 * Source attribution for lookup data and images.
 *
 * TMDB's terms require crediting TMDB wherever its data or images are used,
 * with its logo and the notice "This product uses the TMDB API but is not
 * endorsed or certified by TMDB." (https://www.themoviedb.org/about/logos-attribution).
 * Google Books data is credited to Google Books.
 *
 * The TMDB logo is bundled in assets/ (never hotlinked). If the file is
 * missing, the text credit is shown on its own.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Attribution {

	/** Attachment meta key recording which lookup source a cover came from. */
	const META = '_clib_cover_source';

	/** Bundled logo files (fixed names: no user input reaches these paths). */
	const LOGOS = array(
		'tmdb' => 'assets/tmdb-logo.svg',
	);

	/** TMDB's required notice. */
	public static function tmdb_notice() {
		return __( 'This product uses the TMDB API but is not endorsed or certified by TMDB.', 'church-library' );
	}

	/** URL of a bundled logo, or '' when the file is not present. */
	public static function logo_url( $provider ) {
		if ( ! isset( self::LOGOS[ $provider ] ) || ! is_readable( CLIB_DIR . self::LOGOS[ $provider ] ) ) {
			return '';
		}
		return CLIB_URL . self::LOGOS[ $provider ];
	}

	/**
	 * Credit for a provider: [ text, url, logo ] or null when none is needed.
	 * Used for lookup results (sent to the browser and inserted with textContent).
	 */
	public static function for_provider( $provider ) {
		switch ( $provider ) {
			case 'tmdb':
				return array(
					'text' => self::tmdb_notice(),
					'url'  => 'https://www.themoviedb.org/',
					'logo' => self::logo_url( 'tmdb' ),
					'name' => 'TMDB',
				);
			case 'googlebooks':
				return array(
					'text' => __( 'Book information from Google Books.', 'church-library' ),
					'url'  => 'https://books.google.com/',
					'logo' => '',
					'name' => 'Google Books',
				);
		}
		return null;
	}

	/** Is the TMDB source switched on (and set up)? */
	public static function tmdb_in_use() {
		return CLIB_Settings::get( 'lookup_enabled' ) && isset( CLIB_Lookup::enabled_providers()['tmdb'] );
	}

	/** Escaped HTML for one credit line. */
	public static function credit_html( $provider, $class = 'clib-credit' ) {
		$c = self::for_provider( $provider );
		if ( ! $c ) {
			return '';
		}
		// The logo (or, without the file, the name) links to the source, followed by the notice.
		$mark = '' !== $c['logo']
			? '<img src="' . esc_url( $c['logo'] ) . '" alt="' . esc_attr( $c['name'] ) . '" class="clib-credit-logo" width="80" height="10" loading="lazy">'
			: esc_html( $c['name'] );
		return '<p class="' . esc_attr( $class ) . '"><a href="' . esc_url( $c['url'], array( 'https' ) ) . '" target="_blank" rel="noopener noreferrer">' . $mark . '</a> ' . esc_html( $c['text'] ) . '</p>';
	}

	/** Site-wide credits (library pages and admin screens) while TMDB is in use. */
	public static function footer_html() {
		return self::tmdb_in_use() ? self::credit_html( 'tmdb', 'clib-credit clib-credits-footer' ) : '';
	}

	/** Remember which lookup source an imported cover came from. */
	public static function record_cover( $attachment_id, $provider ) {
		$provider = sanitize_key( (string) $provider );
		if ( $attachment_id && null !== self::for_provider( $provider ) ) {
			update_post_meta( (int) $attachment_id, self::META, $provider );
		}
	}

	/** Credit line under an item image, if it came from a source that needs one. */
	public static function image_credit( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$provider = (string) get_post_meta( (int) $attachment_id, self::META, true );
		if ( 'tmdb' === $provider ) {
			return self::credit_html( 'tmdb', 'clib-credit clib-image-credit' );
		}
		if ( 'googlebooks' === $provider ) {
			return '<p class="clib-credit clib-image-credit">' . esc_html__( 'Cover image: Google Books.', 'church-library' ) . '</p>';
		}
		return '';
	}
}
