<?php
/**
 * Google Books and TMDB lookup providers.
 *
 * - Google Books (https://developers.google.com/books/docs/v1/using): books by
 *   title or ISBN. Works without a key at low volume; an optional API key from
 *   Google Cloud raises the quota. The key travels in the X-goog-api-key header,
 *   never in the URL.
 * - TMDB (https://developer.themoviedb.org/docs): movies on DVD / Blu-ray / VHS.
 *   Needs a free API Read Access Token (sent as a Bearer header) or a v3 API key.
 *   "This product uses the TMDB API but is not endorsed or certified by TMDB."
 *
 * IMDb has no free official API (its data is sold through AWS Data Exchange) and
 * scraping its site breaks its terms, so it is not included.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Lookup_GoogleBooks implements CLIB_Lookup_Provider {

	/** Google Books volume ids: letters, digits, - and _. */
	const VOLUME_ID = '/^[A-Za-z0-9_-]{6,24}$/';

	public function id() {
		return 'googlebooks';
	}

	public function label() {
		return 'Google Books';
	}

	public function kind() {
		return 'book';
	}

	public function allowed_hosts() {
		return array( 'books.google.com' );
	}

	/** Optional API key (validated shape) or ''. */
	private function key() {
		$k = (string) CLIB_Settings::get( 'google_books_key' );
		return CLIB_Settings::valid_google_key( $k ) ? $k : '';
	}

	public function cover_url( $cover_ref ) {
		$cover_ref = (string) $cover_ref;
		if ( ! preg_match( self::VOLUME_ID, $cover_ref ) ) {
			return '';
		}
		return 'https://books.google.com/books/content?id=' . rawurlencode( $cover_ref ) . '&printsec=frontcover&img=1&zoom=1';
	}

	public function search( $type, $query ) {
		if ( 'isbn' === $type ) {
			if ( ! in_array( strlen( $query ), array( 10, 13 ), true ) ) {
				return array(); // 12 digits is a UPC, not an ISBN.
			}
			$q = 'isbn:' . $query;
		} else {
			// Strip Google's own search operators (intitle:, inauthor:, quotes) from user text.
			$words = trim( preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s\'\-]/u', ' ', $query ) ) );
			if ( strlen( $words ) < 2 ) {
				return array();
			}
			$q = 'intitle:' . $words;
		}
		$url     = add_query_arg(
			array(
				'q'          => rawurlencode( $q ),
				'maxResults' => 10,
				'printType'  => 'books',
				'fields'     => rawurlencode( 'items(id,volumeInfo(title,subtitle,authors,publisher,publishedDate,description,industryIdentifiers,imageLinks/smallThumbnail))' ),
			),
			'https://www.googleapis.com/books/v1/volumes'
		);
		$key     = $this->key();
		$headers = '' !== $key ? array( 'X-goog-api-key' => $key ) : array();
		$data    = CLIB_Lookup::http_json( $url, $headers );
		$out     = array();
		if ( empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return $out;
		}
		foreach ( array_slice( $data['items'], 0, 10 ) as $it ) {
			if ( ! is_array( $it ) || empty( $it['id'] ) || ! is_string( $it['id'] ) || ! preg_match( self::VOLUME_ID, $it['id'] ) ) {
				continue;
			}
			$v = isset( $it['volumeInfo'] ) && is_array( $it['volumeInfo'] ) ? $it['volumeInfo'] : array();
			$s = function ( $k ) use ( $v ) {
				return isset( $v[ $k ] ) && is_scalar( $v[ $k ] ) ? (string) $v[ $k ] : '';
			};
			$authors = array();
			foreach ( isset( $v['authors'] ) && is_array( $v['authors'] ) ? array_slice( $v['authors'], 0, 3 ) : array() as $a ) {
				if ( is_string( $a ) ) {
					$authors[] = $a;
				}
			}
			$isbn = '';
			foreach ( isset( $v['industryIdentifiers'] ) && is_array( $v['industryIdentifiers'] ) ? $v['industryIdentifiers'] : array() as $ii ) {
				if ( is_array( $ii ) && isset( $ii['type'], $ii['identifier'] ) && is_string( $ii['identifier'] ) ) {
					if ( 'ISBN_13' === $ii['type'] || ( 'ISBN_10' === $ii['type'] && '' === $isbn ) ) {
						$isbn = $ii['identifier'];
					}
				}
			}
			$has_cover = ! empty( $v['imageLinks']['smallThumbnail'] );
			$title     = $s( 'title' );
			if ( '' !== $s( 'subtitle' ) ) {
				$title .= ': ' . $s( 'subtitle' );
			}
			$out[] = array(
				'title'       => $title,
				'creator'     => implode( ', ', $authors ),
				'publisher'   => $s( 'publisher' ),
				'year'        => substr( $s( 'publishedDate' ), 0, 4 ),
				'identifier'  => $isbn,
				'description' => wp_strip_all_tags( $s( 'description' ) ),
				'cover_ref'   => $has_cover ? $it['id'] : '',
				// Thumbnail rebuilt from the validated id on a fixed https host; the URL Google sent is not used.
				'cover_thumb' => $has_cover ? 'https://books.google.com/books/content?id=' . rawurlencode( $it['id'] ) . '&printsec=frontcover&img=1&zoom=5' : '',
			);
		}
		return $out;
	}
}

class CLIB_Lookup_TMDB implements CLIB_Lookup_Provider {

	/** Poster file names such as /kqjL17yufvn9OVLyXYpvtyrFfak.jpg. */
	const POSTER = '/^[A-Za-z0-9]{10,64}\.(jpg|jpeg|png)$/';

	/** How many top results get an extra request for their director. */
	const CREDIT_LOOKUPS = 3;

	public function id() {
		return 'tmdb';
	}

	public function label() {
		return 'TMDB';
	}

	public function kind() {
		return 'video';
	}

	public function allowed_hosts() {
		return array( 'image.tmdb.org' );
	}

	/** [ header name => value ] or [ 'api_key' => key ] for the query string, or [] when not set up. */
	private function auth() {
		$token = (string) CLIB_Settings::get( 'tmdb_token' );
		if ( CLIB_Settings::valid_tmdb_token( $token ) ) {
			return array( 'header' => 'Bearer ' . $token );
		}
		$key = (string) CLIB_Settings::get( 'tmdb_key' );
		if ( CLIB_Settings::valid_tmdb_key( $key ) ) {
			return array( 'api_key' => $key );
		}
		return array();
	}

	public function is_configured() {
		return (bool) $this->auth();
	}

	/** GET a fixed api.themoviedb.org path with auth. */
	private function get( $path, array $args ) {
		$auth = $this->auth();
		if ( ! $auth ) {
			return null;
		}
		$headers = array();
		if ( isset( $auth['header'] ) ) {
			$headers['Authorization'] = $auth['header'];
		} else {
			$args['api_key'] = $auth['api_key']; // v3 keys only work in the query string.
		}
		return CLIB_Lookup::http_json( add_query_arg( $args, 'https://api.themoviedb.org/3/' . $path ), $headers );
	}

	public function cover_url( $cover_ref ) {
		$cover_ref = (string) $cover_ref;
		return preg_match( self::POSTER, $cover_ref ) ? 'https://image.tmdb.org/t/p/w500/' . $cover_ref : '';
	}

	public function search( $type, $query ) {
		if ( 'title' !== $type ) {
			return array(); // TMDB has no barcode / ISBN search.
		}
		$data = $this->get(
			'search/movie',
			array(
				'query'         => rawurlencode( $query ),
				'include_adult' => 'false',
				'page'          => 1,
			)
		);
		$out = array();
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return $out;
		}
		$n = 0;
		foreach ( array_slice( $data['results'], 0, 10 ) as $m ) {
			if ( ! is_array( $m ) || empty( $m['id'] ) || ! is_numeric( $m['id'] ) ) {
				continue;
			}
			$id     = (int) $m['id'];
			$poster = isset( $m['poster_path'] ) && is_string( $m['poster_path'] ) ? ltrim( $m['poster_path'], '/' ) : '';
			$poster = preg_match( self::POSTER, $poster ) ? $poster : '';
			$title  = isset( $m['title'] ) && is_scalar( $m['title'] ) ? (string) $m['title'] : '';
			$date   = isset( $m['release_date'] ) && is_string( $m['release_date'] ) ? $m['release_date'] : '';
			$out[]  = array(
				'title'       => $title,
				// Director for the first few results, so the Author field can rank them.
				'creator'     => $n++ < self::CREDIT_LOOKUPS ? $this->director( $id ) : '',
				'publisher'   => '',
				'year'        => substr( $date, 0, 4 ),
				'identifier'  => '',
				'description' => isset( $m['overview'] ) && is_string( $m['overview'] ) ? $m['overview'] : '',
				'cover_ref'   => $poster,
				'cover_thumb' => '' !== $poster ? 'https://image.tmdb.org/t/p/w92/' . $poster : '',
			);
		}
		return $out;
	}

	/** Director name(s) for a movie id, or ''. */
	private function director( $movie_id ) {
		$data = $this->get( 'movie/' . (int) $movie_id . '/credits', array() );
		if ( empty( $data['crew'] ) || ! is_array( $data['crew'] ) ) {
			return '';
		}
		$names = array();
		foreach ( $data['crew'] as $c ) {
			if ( is_array( $c ) && isset( $c['job'], $c['name'] ) && 'Director' === $c['job'] && is_string( $c['name'] ) ) {
				$names[] = $c['name'];
			}
			if ( count( $names ) >= 2 ) {
				break;
			}
		}
		return implode( ', ', $names );
	}
}
