<?php
/**
 * Google Books (books) and Wikidata (movies) lookup providers.
 *
 * - Google Books (https://developers.google.com/books/docs/v1/using): books by
 *   title or ISBN. Works without a key at low volume; an optional API key from
 *   Google Cloud raises the quota. The key travels in the X-goog-api-key header,
 *   never in the URL.
 *
 * - Wikidata (https://www.wikidata.org/wiki/Wikidata:Data_access): movies, TV
 *   films and series (DVD / Blu-ray / VHS), by title, with year and director.
 *   Free, no key; data is CC0. Covers are not imported: film posters on
 *   Wikimedia Commons are rare and carry their own licences.
 *
 * TMDB was dropped in 1.3.2 (its licence for this use is paid).
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

class CLIB_Lookup_Wikidata implements CLIB_Lookup_Provider {

	/** Wikidata item ids. */
	const QID = '/^Q[1-9][0-9]{0,11}$/';

	/** API endpoint (fixed). */
	const API = 'https://www.wikidata.org/w/api.php';

	/**
	 * "Instance of" values accepted as screen works: film, feature film, animated,
	 * documentary, short, television film, television series, miniseries,
	 * television special, concert film, musical film.
	 */
	const SCREEN_TYPES = array( 'Q11424', 'Q24869', 'Q202866', 'Q93204', 'Q24862', 'Q506240', 'Q5398426', 'Q1259759', 'Q1261214', 'Q1257444', 'Q842256' );

	public function id() {
		return 'wikidata';
	}

	public function label() {
		return 'Wikidata';
	}

	public function kind() {
		return 'video';
	}

	public function allowed_hosts() {
		return array();
	}

	public function cover_url( $cover_ref ) {
		return '';
	}

	/** Call the API with fixed parameters plus $args. */
	private function api( array $args ) {
		CLIB_Lookup::wait_turn( 'wikidata', 200 );
		$args = array_merge(
			$args,
			array(
				'format'        => 'json',
				'formatversion' => 2,
				'maxlag'        => 5,
			)
		);
		return CLIB_Lookup::http_json( add_query_arg( $args, self::API ) );
	}

	/** Site language as a Wikidata language code (falls back to English). */
	private function lang() {
		$l = strtolower( substr( (string) get_locale(), 0, 2 ) );
		return preg_match( '/^[a-z]{2}$/', $l ) ? $l : 'en';
	}

	/** Pull the item ids out of a claim list (only well-formed ones). */
	private static function claim_ids( $claims, $prop ) {
		$out = array();
		if ( ! isset( $claims[ $prop ] ) || ! is_array( $claims[ $prop ] ) ) {
			return $out;
		}
		foreach ( $claims[ $prop ] as $c ) {
			$id = isset( $c['mainsnak']['datavalue']['value']['id'] ) ? $c['mainsnak']['datavalue']['value']['id'] : '';
			if ( is_string( $id ) && preg_match( self::QID, $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/** Earliest year from a time claim ("+1986-05-16T00:00:00Z"). */
	private static function claim_year( $claims, $prop ) {
		$years = array();
		foreach ( isset( $claims[ $prop ] ) && is_array( $claims[ $prop ] ) ? $claims[ $prop ] : array() as $c ) {
			$t = isset( $c['mainsnak']['datavalue']['value']['time'] ) ? $c['mainsnak']['datavalue']['value']['time'] : '';
			if ( is_string( $t ) && preg_match( '/^\+([0-9]{4})-/', $t, $m ) ) {
				$years[] = (int) $m[1];
			}
		}
		return $years ? (string) min( $years ) : '';
	}

	/** Label in $lang, else English, else ''. */
	private static function label_of( $entity, $lang ) {
		foreach ( array( $lang, 'en' ) as $l ) {
			if ( isset( $entity['labels'][ $l ]['value'] ) && is_string( $entity['labels'][ $l ]['value'] ) ) {
				return $entity['labels'][ $l ]['value'];
			}
		}
		return '';
	}

	public function search( $type, $query ) {
		if ( 'title' !== $type ) {
			return array(); // Wikidata has no barcode search.
		}
		$lang = $this->lang();
		// 1. Find candidate items by title.
		$found = $this->api(
			array(
				'action'   => 'wbsearchentities',
				'search'   => rawurlencode( $query ),
				'language' => $lang,
				'uselang'  => $lang,
				'type'     => 'item',
				'limit'    => 20,
			)
		);
		$ids = array();
		foreach ( isset( $found['search'] ) && is_array( $found['search'] ) ? $found['search'] : array() as $hit ) {
			if ( is_array( $hit ) && isset( $hit['id'] ) && is_string( $hit['id'] ) && preg_match( self::QID, $hit['id'] ) ) {
				$ids[] = $hit['id'];
			}
		}
		if ( ! $ids ) {
			return array();
		}
		// 2. Keep the ones that are films or TV works.
		$data = $this->api(
			array(
				'action'    => 'wbgetentities',
				'ids'       => implode( '|', $ids ),
				'props'     => 'labels|descriptions|claims',
				'languages' => $lang . ( 'en' === $lang ? '' : '|en' ),
			)
		);
		$films     = array();
		$directors = array();
		foreach ( $ids as $qid ) { // Search order = relevance.
			$e = isset( $data['entities'][ $qid ] ) && is_array( $data['entities'][ $qid ] ) ? $data['entities'][ $qid ] : null;
			if ( ! $e || ! isset( $e['claims'] ) || ! is_array( $e['claims'] ) ) {
				continue;
			}
			if ( ! array_intersect( self::claim_ids( $e['claims'], 'P31' ), self::SCREEN_TYPES ) ) {
				continue;
			}
			$dir     = array_slice( self::claim_ids( $e['claims'], 'P57' ), 0, 2 );
			$films[] = array( $qid, $e, $dir );
			foreach ( $dir as $d ) {
				$directors[ $d ] = '';
			}
			if ( count( $films ) >= 8 ) {
				break;
			}
		}
		// 3. Director names, in one request.
		if ( $directors ) {
			$people = $this->api(
				array(
					'action'    => 'wbgetentities',
					'ids'       => implode( '|', array_slice( array_keys( $directors ), 0, 50 ) ),
					'props'     => 'labels',
					'languages' => $lang . ( 'en' === $lang ? '' : '|en' ),
				)
			);
			foreach ( array_keys( $directors ) as $d ) {
				if ( isset( $people['entities'][ $d ] ) && is_array( $people['entities'][ $d ] ) ) {
					$directors[ $d ] = self::label_of( $people['entities'][ $d ], $lang );
				}
			}
		}
		$out = array();
		foreach ( $films as $f ) {
			list( $qid, $e, $dir ) = $f;
			$names = array();
			foreach ( $dir as $d ) {
				if ( '' !== $directors[ $d ] ) {
					$names[] = $directors[ $d ];
				}
			}
			$desc  = '';
			foreach ( array( $lang, 'en' ) as $l ) {
				if ( '' === $desc && isset( $e['descriptions'][ $l ]['value'] ) && is_string( $e['descriptions'][ $l ]['value'] ) ) {
					$desc = $e['descriptions'][ $l ]['value'];
				}
			}
			$out[] = array(
				'title'       => self::label_of( $e, $lang ),
				'creator'     => implode( ', ', $names ),
				'publisher'   => '',
				'year'        => self::claim_year( $e['claims'], 'P577' ),
				'identifier'  => '',
				'description' => trim( $desc . "\nhttps://www.wikidata.org/wiki/" . $qid ),
				'cover_ref'   => '',
				'cover_thumb' => '',
			);
		}
		return $out;
	}
}
