<?php
/**
 * Title / ISBN lookup with pluggable providers.
 *
 * A provider implements CLIB_Lookup_Provider and is registered through the
 * `clib_lookup_providers` filter. Results are normalised to the same shape so
 * the item form can prefill fields regardless of the source:
 *
 *   [ provider, title, creator, publisher, year, identifier, description,
 *     cover_ref, cover_thumb ]
 *
 * Cover images are never fetched from a URL supplied by the browser. The form
 * posts back only (provider id, cover_ref); the provider rebuilds the URL
 * server-side and the host must be on the provider's allow list.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CLIB_Lookup_Provider {
	/** Short machine id, [a-z0-9_]. */
	public function id();
	/** Human label. */
	public function label();
	/** @return array Normalised results (max 10). */
	public function search( $type, $query );
	/** Build a cover URL from a provider-specific reference, or '' when invalid. */
	public function cover_url( $cover_ref );
	/** Hosts this provider may download covers from. */
	public function allowed_hosts();
}

class CLIB_Lookup {

	public static function init() {
		add_action( 'wp_ajax_clib_lookup', array( __CLASS__, 'ajax' ) );
		// No wp_ajax_nopriv_ hook: guests can never trigger outbound requests.
	}

	/** @return CLIB_Lookup_Provider[] keyed by id. */
	public static function providers() {
		$list = apply_filters( 'clib_lookup_providers', array( new CLIB_Lookup_OpenLibrary(), new CLIB_Lookup_MusicBrainz(), new CLIB_Lookup_Hymnary(), new CLIB_Lookup_IMSLP(), new CLIB_Lookup_Discogs() ) );
		$out  = array();
		foreach ( (array) $list as $p ) {
			if ( $p instanceof CLIB_Lookup_Provider ) {
				$out[ sanitize_key( $p->id() ) ] = $p;
			}
		}
		return $out;
	}

	/** Providers switched on in Library - Settings (and configured, where a key is needed). */
	public static function enabled_providers() {
		$on  = (array) CLIB_Settings::get( 'lookup_providers' );
		$out = array();
		foreach ( self::providers() as $id => $p ) {
			if ( ! in_array( $id, $on, true ) ) {
				continue;
			}
			if ( method_exists( $p, 'is_configured' ) && ! $p->is_configured() ) {
				continue;
			}
			$out[ $id ] = $p;
		}
		return $out;
	}

	/** Provider kind: 'book' (default) or 'music'. */
	public static function kind( $p ) {
		return method_exists( $p, 'kind' ) && 'music' === $p->kind() ? 'music' : 'book';
	}

	/**
	 * Space out calls to services with a per-second limit (MusicBrainz asks for
	 * at most one request per second). Waits at most 2 seconds.
	 */
	public static function wait_turn( $service, $gap_ms ) {
		$key  = 'clib_gap_' . sanitize_key( $service );
		$last = (float) get_transient( $key );
		$wait = $last + ( $gap_ms / 1000 ) - microtime( true );
		if ( $wait > 0 ) {
			usleep( (int) ( min( $wait, 2 ) * 1000000 ) );
		}
		set_transient( $key, (string) microtime( true ), 60 );
	}

	public static function provider( $id ) {
		$all = self::providers();
		$id  = sanitize_key( (string) $id );
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function ajax() {
		check_ajax_referer( 'clib_lookup', 'nonce' );
		if ( ! current_user_can( 'clib_manage_items' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'church-library' ) ), 403 );
		}
		// Throttle: at most 30 lookups per user per 5 minutes.
		$rl_key = 'clib_lk_rate_' . get_current_user_id();
		$hits   = (int) get_transient( $rl_key );
		if ( $hits >= 30 ) {
			wp_send_json_error( array( 'message' => __( 'Too many lookups. Please wait a few minutes.', 'church-library' ) ), 429 );
		}
		set_transient( $rl_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

		$type  = CLIB_Util::enum( CLIB_Util::post( 'type', 'title' ), array( 'title', 'isbn' ), 'title' );
		$query = CLIB_Util::text( CLIB_Util::post( 'q' ), 200 );
		$music = (bool) CLIB_Util::post( 'music' );
		if ( 'isbn' === $type ) {
			$query = strtoupper( preg_replace( '/[^0-9Xx]/', '', $query ) );
			if ( ! in_array( strlen( $query ), array( 10, 12, 13 ), true ) ) {
				wp_send_json_error( array( 'message' => __( 'Enter a 10 or 13 digit ISBN, or a 12 or 13 digit CD barcode.', 'church-library' ) ) );
			}
		} elseif ( strlen( $query ) < 2 ) {
			wp_send_json_error( array( 'message' => __( 'Enter at least 2 characters.', 'church-library' ) ) );
		}

		$response = array(
			'local'  => self::local_matches( $type, $query ),
			'remote' => array(),
		);

		if ( CLIB_Settings::get( 'lookup_enabled' ) ) {
			// Music sources first for music items, book sources first otherwise.
			$providers = self::enabled_providers();
			uasort(
				$providers,
				function ( $a, $b ) use ( $music ) {
					$ka = 'music' === CLIB_Lookup::kind( $a ) ? 0 : 1;
					$kb = 'music' === CLIB_Lookup::kind( $b ) ? 0 : 1;
					return $music ? $ka - $kb : $kb - $ka;
				}
			);
			foreach ( $providers as $pid => $provider ) {
				$cache_key = 'clib_lk_' . md5( $pid . '|' . $type . '|' . strtolower( $query ) );
				$results   = get_transient( $cache_key );
				if ( ! is_array( $results ) ) {
					$results = $provider->search( $type, $query );
					$results = is_array( $results ) ? array_slice( $results, 0, 10 ) : array();
					set_transient( $cache_key, $results, $results ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS ); // Short cache for empty answers (may be a busy service).
				}
				foreach ( $results as $r ) {
					$response['remote'][] = self::clean_result( $pid, $provider->label(), $r );
				}
			}
		}
		wp_send_json_success( $response );
	}

	/** Re-sanitize everything a provider returns before it reaches the browser. */
	private static function clean_result( $pid, $label, $r ) {
		$r = is_array( $r ) ? $r : array();
		$g = function ( $k, $max ) use ( $r ) {
			return isset( $r[ $k ] ) && is_scalar( $r[ $k ] ) ? CLIB_Util::cap( sanitize_text_field( (string) $r[ $k ] ), $max ) : '';
		};
		$thumb = isset( $r['cover_thumb'] ) ? esc_url_raw( (string) $r['cover_thumb'], array( 'https' ) ) : '';
		return array(
			'provider'       => $pid,
			'provider_label' => sanitize_text_field( $label ),
			'title'          => $g( 'title', 255 ),
			'creator'        => $g( 'creator', 255 ),
			'publisher'      => $g( 'publisher', 255 ),
			'year'           => $g( 'year', 10 ),
			'identifier'     => $g( 'identifier', 64 ),
			'description'    => isset( $r['description'] ) && is_scalar( $r['description'] ) ? CLIB_Util::cap( sanitize_textarea_field( (string) $r['description'] ), 5000 ) : '',
			'cover_ref'      => $g( 'cover_ref', 100 ),
			'cover_thumb'    => $thumb,
		);
	}

	private static function local_matches( $type, $query ) {
		global $wpdb;
		$t = CLIB_DB::table( 'items' );
		if ( 'isbn' === $type ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_code, title FROM $t WHERE REPLACE(identifier, '-', '') = %s LIMIT 5", $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_code, title FROM $t WHERE title LIKE %s ORDER BY title LIMIT 5", '%' . $wpdb->esc_like( $query ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'item_code' => $r->item_code,
				'title'     => $r->title,
				'url'       => esc_url_raw( admin_url( 'admin.php?page=clib-catalog&view=' . (int) $r->id ) ),
			);
		}
		return $out;
	}

	/** Sideload a cover chosen from lookup results. Returns attachment id, 0 or WP_Error. */
	public static function import_cover( $provider_id, $cover_ref, $title ) {
		if ( ! CLIB_Settings::get( 'lookup_enabled' ) ) {
			return 0;
		}
		$provider = self::provider( $provider_id );
		if ( ! $provider ) {
			return 0;
		}
		$url = $provider->cover_url( (string) $cover_ref );
		if ( '' === $url ) {
			return 0;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), array_map( 'strtolower', $provider->allowed_hosts() ), true ) ) {
			return new WP_Error( 'clib_cover', __( 'Cover host not allowed.', 'church-library' ) );
		}
		return CLIB_Items::sideload_image( $url, 'cover-' . sanitize_file_name( $provider_id ) . '.jpg', $title );
	}

	/** Shared HTTP helper for providers: https only, small, short timeout. Returns the body or null. */
	public static function http_body( $url, array $headers = array(), $accept = 'application/json' ) {
		if ( 'https' !== wp_parse_url( (string) $url, PHP_URL_SCHEME ) ) {
			return null;
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 2,
				'limit_response_size' => 1048576,
				// Services ask for an identifying User-Agent with a contact URL; no personal data.
				'user-agent'          => 'ChurchLibrary/' . CLIB_VERSION . ' ( https://github.com/md99999/church-library )',
				'headers'             => array_merge( array( 'Accept' => $accept ), $headers ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/** JSON variant of http_body(). */
	public static function http_json( $url, array $headers = array() ) {
		$body = self::http_body( $url, $headers );
		if ( null === $body ) {
			return null;
		}
		$data = json_decode( $body, true, 64 );
		return is_array( $data ) ? $data : null;
	}
}

/**
 * Open Library provider (free, no API key). https://openlibrary.org/developers/api
 */
class CLIB_Lookup_OpenLibrary implements CLIB_Lookup_Provider {

	public function id() {
		return 'openlibrary';
	}

	public function label() {
		return 'Open Library';
	}

	public function kind() {
		return 'book';
	}

	public function allowed_hosts() {
		return array( 'covers.openlibrary.org' );
	}

	public function cover_url( $cover_ref ) {
		return ctype_digit( $cover_ref ) && strlen( $cover_ref ) <= 12 ? 'https://covers.openlibrary.org/b/id/' . $cover_ref . '-L.jpg' : '';
	}

	public function search( $type, $query ) {
		if ( 'isbn' === $type && 12 === strlen( $query ) ) {
			return array(); // A 12-digit code is a UPC barcode, not an ISBN.
		}
		return 'isbn' === $type ? $this->by_isbn( $query ) : $this->by_title( $query );
	}

	private function by_title( $query ) {
		$url  = add_query_arg(
			array(
				'q'      => rawurlencode( $query ),
				'limit'  => 8,
				'fields' => 'key,title,author_name,publisher,first_publish_year,isbn,cover_i,first_sentence',
			),
			'https://openlibrary.org/search.json'
		);
		$data = CLIB_Lookup::http_json( $url );
		$out  = array();
		if ( empty( $data['docs'] ) || ! is_array( $data['docs'] ) ) {
			return $out;
		}
		foreach ( $data['docs'] as $d ) {
			$cover = isset( $d['cover_i'] ) ? (string) (int) $d['cover_i'] : '';
			$out[] = array(
				'title'       => isset( $d['title'] ) ? $d['title'] : '',
				'creator'     => isset( $d['author_name'] ) ? implode( ', ', array_slice( (array) $d['author_name'], 0, 3 ) ) : '',
				'publisher'   => isset( $d['publisher'][0] ) ? $d['publisher'][0] : '',
				'year'        => isset( $d['first_publish_year'] ) ? $d['first_publish_year'] : '',
				'identifier'  => isset( $d['isbn'][0] ) ? $d['isbn'][0] : '',
				'description' => isset( $d['first_sentence'][0] ) ? $d['first_sentence'][0] : '',
				'cover_ref'   => '0' === $cover ? '' : $cover,
				'cover_thumb' => ( '' !== $cover && '0' !== $cover ) ? 'https://covers.openlibrary.org/b/id/' . $cover . '-S.jpg' : '',
			);
		}
		return $out;
	}

	private function by_isbn( $isbn ) {
		$url  = add_query_arg(
			array(
				'bibkeys' => 'ISBN:' . $isbn,
				'format'  => 'json',
				'jscmd'   => 'data',
			),
			'https://openlibrary.org/api/books'
		);
		$data = CLIB_Lookup::http_json( $url );
		$key  = 'ISBN:' . $isbn;
		if ( empty( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return array();
		}
		$d     = $data[ $key ];
		$cover = '';
		if ( ! empty( $d['cover']['large'] ) && preg_match( '#/b/id/(\d+)-#', (string) $d['cover']['large'], $m ) ) {
			$cover = $m[1];
		}
		$authors = array();
		foreach ( isset( $d['authors'] ) ? (array) $d['authors'] : array() as $a ) {
			if ( ! empty( $a['name'] ) ) {
				$authors[] = $a['name'];
			}
		}
		return array(
			array(
				'title'       => isset( $d['title'] ) ? $d['title'] . ( ! empty( $d['subtitle'] ) ? ': ' . $d['subtitle'] : '' ) : '',
				'creator'     => implode( ', ', array_slice( $authors, 0, 3 ) ),
				'publisher'   => isset( $d['publishers'][0]['name'] ) ? $d['publishers'][0]['name'] : '',
				'year'        => isset( $d['publish_date'] ) ? $d['publish_date'] : '',
				'identifier'  => $isbn,
				'description' => isset( $d['notes'] ) && is_string( $d['notes'] ) ? $d['notes'] : '',
				'cover_ref'   => $cover,
				'cover_thumb' => '' !== $cover ? 'https://covers.openlibrary.org/b/id/' . $cover . '-S.jpg' : '',
			),
		);
	}
}
