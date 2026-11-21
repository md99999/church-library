<?php
/**
 * Music lookup providers.
 *
 * - MusicBrainz (https://musicbrainz.org/doc/MusicBrainz_API): open music
 *   encyclopedia, no key. Rules followed: at most one request per second,
 *   a descriptive User-Agent with a contact URL, results cached.
 * - Cover Art Archive (https://coverartarchive.org): album art for
 *   MusicBrainz releases, addressed by the release MBID.
 * - Discogs (https://www.discogs.com/developers): optional; needs a free
 *   personal access token entered in Library - Settings; off by default.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Lookup_MusicBrainz implements CLIB_Lookup_Provider {

	const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public function id() {
		return 'musicbrainz';
	}

	public function label() {
		return 'MusicBrainz';
	}

	public function kind() {
		return 'music';
	}

	public function allowed_hosts() {
		return array( 'coverartarchive.org' );
	}

	/**
	 * Cover refs are "rg-<release-group MBID>" (art from any release in the group,
	 * best coverage) or a release MBID.
	 */
	public function cover_url( $cover_ref ) {
		$cover_ref = strtolower( (string) $cover_ref );
		if ( 0 === strpos( $cover_ref, 'rg-' ) && preg_match( self::UUID, substr( $cover_ref, 3 ) ) ) {
			return 'https://coverartarchive.org/release-group/' . substr( $cover_ref, 3 ) . '/front-500';
		}
		return preg_match( self::UUID, $cover_ref ) ? 'https://coverartarchive.org/release/' . $cover_ref . '/front-500' : '';
	}

	public function search( $type, $query ) {
		if ( 'isbn' === $type ) {
			// Music has no ISBN, but CDs carry a UPC/EAN barcode.
			if ( ! in_array( strlen( $query ), array( 12, 13 ), true ) || ! ctype_digit( $query ) ) {
				return array();
			}
			$lucene = 'barcode:' . $query;
		} else {
			// Match the title words (MusicBrainz ranks exact titles first).
			// All Lucene operators in user text are escaped.
			$words  = trim( preg_replace( '/([+\-&|!(){}\[\]^"~*?:\\\\\/])/', '\\\\$1', $query ) );
			$lucene = 'release:(' . $words . ')';
		}
		$url = add_query_arg(
			array(
				'query' => rawurlencode( $lucene ),
				'limit' => 8,
				'fmt'   => 'json',
			),
			'https://musicbrainz.org/ws/2/release/'
		);
		// MusicBrainz answers 503 when its shared rate limit is hit; wait and try once more.
		$data = null;
		for ( $try = 0; $try < 2 && null === $data; $try++ ) {
			CLIB_Lookup::wait_turn( 'musicbrainz', $try ? 1500 : 1100 );
			$data = CLIB_Lookup::http_json( $url );
		}
		$out = array();
		if ( empty( $data['releases'] ) || ! is_array( $data['releases'] ) ) {
			return $out;
		}
		foreach ( $data['releases'] as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) || ! preg_match( self::UUID, (string) $r['id'] ) ) {
				continue;
			}
			$artists = array();
			foreach ( isset( $r['artist-credit'] ) ? (array) $r['artist-credit'] : array() as $ac ) {
				if ( is_array( $ac ) && ! empty( $ac['name'] ) ) {
					$artists[] = $ac['name'];
				}
			}
			$labels = array();
			$catno  = '';
			foreach ( isset( $r['label-info'] ) ? (array) $r['label-info'] : array() as $li ) {
				if ( is_array( $li ) && ! empty( $li['label']['name'] ) ) {
					$labels[] = $li['label']['name'];
				}
				if ( '' === $catno && is_array( $li ) && ! empty( $li['catalog-number'] ) ) {
					$catno = $li['catalog-number'];
				}
			}
			$formats = array();
			$tracks  = 0;
			foreach ( isset( $r['media'] ) ? (array) $r['media'] : array() as $m ) {
				if ( is_array( $m ) ) {
					if ( ! empty( $m['format'] ) ) {
						$formats[] = $m['format'];
					}
					$tracks += isset( $m['track-count'] ) ? (int) $m['track-count'] : 0;
				}
			}
			$desc = implode( ', ', array_unique( $formats ) );
			if ( $tracks ) {
				/* translators: %d: number of tracks */
				$desc .= ( '' !== $desc ? ', ' : '' ) . sprintf( _n( '%d track', '%d tracks', $tracks, 'church-library' ), $tracks );
			}
			$rg      = isset( $r['release-group']['id'] ) ? strtolower( (string) $r['release-group']['id'] ) : '';
			$ref     = preg_match( self::UUID, $rg ) ? 'rg-' . $rg : $r['id'];
			$out[]   = array(
				'title'       => isset( $r['title'] ) ? $r['title'] : '',
				'creator'     => implode( ', ', array_slice( $artists, 0, 3 ) ),
				'publisher'   => implode( ', ', array_slice( array_unique( $labels ), 0, 2 ) ),
				'year'        => isset( $r['date'] ) ? substr( (string) $r['date'], 0, 4 ) : '',
				'identifier'  => ! empty( $r['barcode'] ) ? $r['barcode'] : $catno,
				'description' => $desc,
				'cover_ref'   => $ref,
				'cover_thumb' => preg_replace( '/-500$/', '-250', $this->cover_url( $ref ) ),
			);
		}
		return $out;
	}
}

class CLIB_Lookup_Discogs implements CLIB_Lookup_Provider {

	public function id() {
		return 'discogs';
	}

	public function label() {
		return 'Discogs';
	}

	public function kind() {
		return 'music';
	}

	public function allowed_hosts() {
		return array( 'i.discogs.com' );
	}

	private function token() {
		$t = (string) CLIB_Settings::get( 'discogs_token' );
		return CLIB_Settings::valid_discogs_token( $t ) ? $t : '';
	}

	/** Consumer key + secret of a registered Discogs app (alternative to a token). */
	private function key_secret() {
		$k = (string) CLIB_Settings::get( 'discogs_key' );
		$s = (string) CLIB_Settings::get( 'discogs_secret' );
		return CLIB_Settings::valid_discogs_credential( $k ) && CLIB_Settings::valid_discogs_credential( $s ) ? array( $k, $s ) : array();
	}

	/** Authorization header value: personal token first, else key + secret, else ''. */
	private function auth_header() {
		$token = $this->token();
		if ( '' !== $token ) {
			return 'Discogs token=' . $token;
		}
		$ks = $this->key_secret();
		return $ks ? 'Discogs key=' . $ks[0] . ', secret=' . $ks[1] : '';
	}

	public function is_configured() {
		return '' !== $this->auth_header();
	}

	private function get( $url ) {
		$auth = $this->auth_header();
		if ( '' === $auth ) {
			return null;
		}
		CLIB_Lookup::wait_turn( 'discogs', 1100 );
		// Credentials travel only in the header, over https, to api.discogs.com (fixed in code).
		return CLIB_Lookup::http_json( $url, array( 'Authorization' => $auth ) );
	}

	/** Look the release up again server-side and return its primary image URL. */
	public function cover_url( $cover_ref ) {
		if ( ! ctype_digit( (string) $cover_ref ) || strlen( $cover_ref ) > 12 ) {
			return '';
		}
		$data = $this->get( 'https://api.discogs.com/releases/' . $cover_ref );
		if ( empty( $data['images'] ) || ! is_array( $data['images'] ) ) {
			return '';
		}
		$pick = null;
		foreach ( $data['images'] as $img ) {
			if ( is_array( $img ) && ! empty( $img['uri'] ) && ( null === $pick || ( isset( $img['type'] ) && 'primary' === $img['type'] ) ) ) {
				$pick = $img['uri'];
			}
		}
		return $pick ? esc_url_raw( (string) $pick, array( 'https' ) ) : '';
	}

	public function search( $type, $query ) {
		if ( 'isbn' === $type ) {
			if ( ! in_array( strlen( $query ), array( 12, 13 ), true ) || ! ctype_digit( $query ) ) {
				return array();
			}
			$args = array( 'barcode' => $query );
		} else {
			$args = array( 'q' => rawurlencode( $query ) );
		}
		$args['type']     = 'release';
		$args['per_page'] = 8;
		$data             = $this->get( add_query_arg( $args, 'https://api.discogs.com/database/search' ) );
		$out              = array();
		if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return $out;
		}
		foreach ( $data['results'] as $r ) {
			if ( ! is_array( $r ) || empty( $r['id'] ) ) {
				continue;
			}
			// Discogs titles are "Artist - Title".
			$full   = isset( $r['title'] ) ? (string) $r['title'] : '';
			$parts  = explode( ' - ', $full, 2 );
			$artist = 2 === count( $parts ) ? $parts[0] : '';
			$title  = 2 === count( $parts ) ? $parts[1] : $full;
			$thumb  = isset( $r['thumb'] ) ? (string) $r['thumb'] : '';
			$host   = wp_parse_url( $thumb, PHP_URL_HOST );
			$out[]  = array(
				'title'       => $title,
				'creator'     => $artist,
				'publisher'   => isset( $r['label'][0] ) ? $r['label'][0] : '',
				'year'        => isset( $r['year'] ) ? $r['year'] : '',
				'identifier'  => isset( $r['barcode'][0] ) ? $r['barcode'][0] : ( isset( $r['catno'] ) ? $r['catno'] : '' ),
				'description' => isset( $r['format'] ) ? implode( ', ', array_slice( array_unique( (array) $r['format'] ), 0, 4 ) ) : '',
				'cover_ref'   => (string) (int) $r['id'],
				'cover_thumb' => 'i.discogs.com' === $host ? $thumb : '',
			);
		}
		return $out;
	}
}

/**
 * Hymnary.org (https://hymnary.org): hymn texts. No public JSON search API, so
 * this uses the site's own search with its CSV export, restricted to texts.
 * No cover images. Search operators in user text (in:, field:) are stripped.
 */
class CLIB_Lookup_Hymnary implements CLIB_Lookup_Provider {

	public function id() {
		return 'hymnary';
	}

	public function label() {
		return 'Hymnary.org';
	}

	public function kind() {
		return 'music';
	}

	public function allowed_hosts() {
		return array();
	}

	public function cover_url( $cover_ref ) {
		return '';
	}

	public function search( $type, $query ) {
		if ( 'title' !== $type ) {
			return array();
		}
		$words = trim( preg_replace( '/\s+/u', ' ', (string) preg_replace( '/[^\p{L}\p{N}\s\'\-]/u', ' ', $query ) ) );
		if ( strlen( $words ) < 2 ) {
			return array();
		}
		CLIB_Lookup::wait_turn( 'hymnary', 1000 );
		$body = CLIB_Lookup::http_body(
			add_query_arg(
				array(
					'qu'     => rawurlencode( $words . ' in:texts' ),
					'export' => 'csv',
				),
				'https://hymnary.org/search'
			),
			array(),
			'text/csv'
		);
		if ( null === $body || '' === $body ) {
			return array();
		}
		$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $fh, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $fh );
		$head = fgetcsv( $fh, 0, ',', '"', '\\' );
		$out  = array();
		if ( is_array( $head ) && in_array( 'displayTitle', $head, true ) ) {
			$head = array_map( 'strval', $head );
			while ( count( $out ) < 8 && false !== ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
				if ( count( $row ) !== count( $head ) ) {
					continue;
				}
				$r     = array_combine( $head, $row );
				$slug  = isset( $r['textAuthNumber'] ) && preg_match( '/^[a-z0-9_]{1,120}$/', $r['textAuthNumber'] ) ? $r['textAuthNumber'] : '';
				$desc  = array();
				if ( ! empty( $r['firstLine'] ) ) {
					$desc[] = sprintf( /* translators: %s: first line of a hymn */ __( 'First line: %s', 'church-library' ), $r['firstLine'] );
				}
				if ( ! empty( $r['meter'] ) ) {
					$desc[] = sprintf( /* translators: %s: hymn meter */ __( 'Meter: %s', 'church-library' ), $r['meter'] );
				}
				if ( $slug ) {
					$desc[] = 'https://hymnary.org/text/' . $slug;
				}
				$out[] = array(
					'title'       => isset( $r['displayTitle'] ) ? $r['displayTitle'] : '',
					'creator'     => isset( $r['authors'] ) ? $r['authors'] : '',
					'publisher'   => '',
					'year'        => '',
					'identifier'  => '',
					'description' => implode( "\n", $desc ),
					'cover_ref'   => '',
					'cover_thumb' => '',
				);
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}
}

/**
 * IMSLP / Petrucci Music Library (https://imslp.org): public-domain scores.
 * Uses the MediaWiki opensearch API; page titles read "Work (Composer, First)".
 */
class CLIB_Lookup_IMSLP implements CLIB_Lookup_Provider {

	public function id() {
		return 'imslp';
	}

	public function label() {
		return 'IMSLP';
	}

	public function kind() {
		return 'music';
	}

	public function allowed_hosts() {
		return array();
	}

	public function cover_url( $cover_ref ) {
		return '';
	}

	public function search( $type, $query ) {
		if ( 'title' !== $type ) {
			return array();
		}
		CLIB_Lookup::wait_turn( 'imslp', 1000 );
		$data = CLIB_Lookup::http_json(
			add_query_arg(
				array(
					'action'    => 'opensearch',
					'search'    => rawurlencode( $query ),
					'limit'     => 8,
					'namespace' => 0,
					'format'    => 'json',
				),
				'https://imslp.org/api.php'
			)
		);
		$out = array();
		if ( ! is_array( $data ) || ! isset( $data[1] ) || ! is_array( $data[1] ) ) {
			return $out;
		}
		foreach ( array_slice( $data[1], 0, 8 ) as $page ) {
			if ( ! is_string( $page ) || '' === $page || false !== strpos( $page, ':' ) && preg_match( '/^[A-Za-z]+:/', $page ) ) {
				continue; // Skip non-work namespaces such as Category:.
			}
			$title   = $page;
			$creator = '';
			if ( preg_match( '/^(.+?)\s*\(([^()]+)\)$/u', $page, $m ) ) {
				$title = $m[1];
				$bits  = array_map( 'trim', explode( ',', $m[2], 2 ) );
				// "Handel, George Frideric" -> "George Frideric Handel".
				$creator = 2 === count( $bits ) ? $bits[1] . ' ' . $bits[0] : $m[2];
			}
			$out[] = array(
				'title'       => $title,
				'creator'     => $creator,
				'publisher'   => '',
				'year'        => '',
				'identifier'  => '',
				'description' => 'https://imslp.org/wiki/' . rawurlencode( str_replace( ' ', '_', $page ) ),
				'cover_ref'   => '',
				'cover_thumb' => '',
			);
		}
		return $out;
	}
}
