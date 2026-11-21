<?php
/**
 * Source attribution for lookup data and images.
 *
 * Google Books results and covers are credited to Google Books. The source of
 * an imported cover is recorded on its attachment so the credit can be shown
 * under the image.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Attribution {

	/** Attachment meta key recording which lookup source a cover came from. */
	const META = '_clib_cover_source';

	/**
	 * Credit for a provider: [ text, url, name ] or null when none is needed.
	 * Used for lookup results (sent to the browser and inserted with textContent).
	 */
	public static function for_provider( $provider ) {
		if ( 'googlebooks' === $provider ) {
			return array(
				'text' => __( 'Book information from Google Books.', 'church-library' ),
				'url'  => 'https://books.google.com/',
				'name' => 'Google Books',
			);
		}
		return null;
	}

	/**
	 * Fixed credit list for the built-in sources: id => [ name, url, note ].
	 * Hard-coded on purpose so a filtered-in provider can never inject a link.
	 */
	const SOURCES = array(
		'openlibrary' => array( 'Open Library', 'https://openlibrary.org/', '' ),
		'googlebooks' => array( 'Google Books', 'https://books.google.com/', '' ),
		'wikidata'    => array( 'Wikidata', 'https://www.wikidata.org/', 'CC0' ),
		'musicbrainz' => array( 'MusicBrainz', 'https://musicbrainz.org/', 'CC0' ),
		'hymnary'     => array( 'Hymnary.org', 'https://hymnary.org/', '' ),
		'imslp'       => array( 'IMSLP', 'https://imslp.org/', '' ),
		'discogs'     => array( 'Discogs', 'https://www.discogs.com/', '' ),
	);

	/**
	 * Escaped footer line crediting the lookup sources that are switched on.
	 * Empty when lookup is off or no known source is enabled.
	 */
	public static function footer_html() {
		if ( ! CLIB_Settings::get( 'lookup_enabled' ) || ! class_exists( 'CLIB_Lookup' ) ) {
			return '';
		}
		$links = array();
		foreach ( array_keys( CLIB_Lookup::enabled_providers() ) as $id ) {
			if ( ! isset( self::SOURCES[ $id ] ) ) {
				continue;
			}
			list( $name, $url, $note ) = self::SOURCES[ $id ];
			$link = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $name ) . '</a>';
			if ( '' !== $note ) {
				$link .= ' (' . esc_html( $note ) . ')';
			}
			$links[] = $link;
		}
		if ( ! $links ) {
			return '';
		}
		return '<p class="clib-credit clib-footer-credit">' . esc_html__( 'Lookup data from:', 'church-library' ) . ' ' . implode( ' &middot; ', $links ) . '</p>';
	}

	/** Remember which lookup source an imported cover came from. */
	public static function record_cover( $attachment_id, $provider ) {
		$provider = sanitize_key( (string) $provider );
		if ( $attachment_id && null !== self::for_provider( $provider ) ) {
			update_post_meta( (int) $attachment_id, self::META, $provider );
		}
	}

	/** Escaped credit line under an item image, if it came from a source that needs one. */
	public static function image_credit( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$provider = (string) get_post_meta( (int) $attachment_id, self::META, true );
		if ( 'googlebooks' === $provider ) {
			return '<p class="clib-credit clib-image-credit">' . esc_html__( 'Cover image: Google Books.', 'church-library' ) . '</p>';
		}
		if ( 'tmdb' === $provider ) {
			// Posters imported while the TMDB source existed (1.3.0 / 1.3.1) keep their credit.
			return '<p class="clib-credit clib-image-credit">' . esc_html__( 'Poster: TMDB. This product uses the TMDB API but is not endorsed or certified by TMDB.', 'church-library' ) . '</p>';
		}
		return '';
	}
}
