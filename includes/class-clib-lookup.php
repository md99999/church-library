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
		$list = apply_filters( 'clib_lookup_providers', array( new CLIB_Lookup_OpenLibrary(), new CLIB_Lookup_GoogleBooks(), new CLIB_Lookup_Wikidata(), new CLIB_Lookup_MusicBrainz(), new CLIB_Lookup_Hymnary(), new CLIB_Lookup_IMSLP(), new CLIB_Lookup_Discogs() ) );
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

	/** Provider kind: 'book' (default), 'music' or 'video'. */
	public static function kind( $p ) {
		$k = method_exists( $p, 'kind' ) ? (string) $p->kind() : 'book';
		return in_array( $k, array( 'music', 'video' ), true ) ? $k : 'book';
	}

	/**
	 * Which kind of source suits a media type: 'music' (flagged as music in
	 * List of Values), 'video' (DVD, VHS, Blu-ray, video, movie, film), 'book'
	 * (books, magazines, bibles, hymnals) or '' when unknown (all sources).
	 * Decided on the server from the stored list entry, not from the browser.
	 */
	public static function media_kind( $media_type_id ) {
		$mt = $media_type_id ? CLIB_LOV::get( (int) $media_type_id ) : null;
		if ( ! $mt || 'media_type' !== $mt->list_type ) {
			return '';
		}
		if ( (int) $mt->is_music === 1 ) {
			return 'music';
		}
		$text = $mt->code . ' ' . $mt->label;
		if ( preg_match( '/(DVD|VHS|BLU-?RAY|VIDEO|MOVIE|FILM)/i', $text ) ) {
			return 'video';
		}
		if ( preg_match( '/(BOOK|MAG|BIBLE|HYMNAL|NOVEL|PERIODICAL)/i', $text ) ) {
			return 'book';
		}
		return '';
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
			CLIB_Audit::log_error( 'lookup', 'Lookup rate limit reached (30 lookups in 5 minutes) for this user.' );
			wp_send_json_error( array( 'message' => __( 'Too many lookups. Please wait a few minutes.', 'church-library' ) ), 429 );
		}
		set_transient( $rl_key, $hits + 1, 5 * MINUTE_IN_SECONDS );

		$type   = CLIB_Util::enum( CLIB_Util::post( 'type', 'title' ), array( 'title', 'isbn' ), 'title' );
		$query  = CLIB_Util::text( CLIB_Util::post( 'q' ), 200 );
		$author = CLIB_Util::text( CLIB_Util::post( 'author' ), 255 );
		$kind   = self::media_kind( CLIB_Util::int( CLIB_Util::post( 'media_type_id' ) ) );
		if ( '' === $kind && CLIB_Util::post( 'music' ) ) {
			$kind = 'music'; // Older cached admin.js.
		}
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
			// Sources that suit the item's media type run first, in the order shown in
			// Library - Settings. The others run only if those find nothing, so a DVD
			// lookup is not slowed down or cluttered by book and music sources.
			$primary   = array();
			$secondary = array();
			foreach ( self::enabled_providers() as $pid => $provider ) {
				if ( '' === $kind || self::kind( $provider ) === $kind ) {
					$primary[ $pid ] = $provider;
				} else {
					$secondary[ $pid ] = $provider;
				}
			}
			$problems = array();
			$remote   = self::run_providers( $primary, $type, $query, $problems );
			if ( ! $remote && $secondary ) {
				$remote = self::run_providers( $secondary, $type, $query, $problems );
			}
			$response['problems'] = $problems;
			// Collate: best title (and author, when typed) match first. Ties keep source order.
			if ( 'title' === $type ) {
				foreach ( $remote as $i => &$r ) {
					$r['score'] = CLIB_Match::result_score( $query, $author, $r['title'], $r['creator'] );
					$r['order'] = $i;
				}
				unset( $r );
				usort(
					$remote,
					function ( $a, $b ) {
						return $b['score'] === $a['score'] ? $a['order'] - $b['order'] : $b['score'] - $a['score'];
					}
				);
				foreach ( $remote as &$r ) {
					unset( $r['order'] );
				}
				unset( $r );
			}
			$response['remote'] = array_slice( $remote, 0, 40 );
			// Credits for the sources shown (for example Google Books).
			$response['credits'] = array();
			foreach ( array_unique( wp_list_pluck( $response['remote'], 'provider' ) ) as $pid ) {
				$credit = CLIB_Attribution::for_provider( $pid );
				if ( $credit ) {
					$response['credits'][] = $credit;
				}
			}
		}
		wp_send_json_success( $response );
	}

	/**
	 * Run providers in order and return their cleaned results (cached per provider + query).
	 * Sources that did not answer are added to $problems (label + plain reason, no response body).
	 */
	private static function run_providers( array $providers, $type, $query, array &$problems ) {
		$out = array();
		foreach ( $providers as $pid => $provider ) {
			$cache_key = 'clib_lk_' . md5( $pid . '|' . $type . '|' . strtolower( $query ) );
			$results   = get_transient( $cache_key );
			if ( ! is_array( $results ) ) {
				self::$last_status  = 200;
				self::$last_problem = '';
				try {
					$results = $provider->search( $type, $query );
				} catch ( Throwable $e ) {
					$results = array(); // A broken third-party provider must not break the lookup.
					// Exception class only: messages can carry URLs or data from the remote side.
					CLIB_Audit::log_error( $pid, sprintf( 'Lookup source failed with %s.', get_class( $e ) ) );
				}
				if ( ! $results && 200 !== self::$last_status ) {
					// A provider may explain the failure itself (already plain text, escaped on output).
					$problem    = '' !== self::$last_problem ? sanitize_text_field( self::$last_problem ) : self::problem_text( $provider->label(), self::$last_status );
					$problems[] = $problem;
					/* translators: 1: plain-language problem, 2: HTTP status (0 = no answer) */
					CLIB_Audit::log_error( $pid, sprintf( __( '%1$s (HTTP status %2$d)', 'church-library' ), $problem, self::$last_status ) );
				}
				$results = is_array( $results ) ? array_slice( $results, 0, 10 ) : array();
				set_transient( $cache_key, $results, $results ? 12 * HOUR_IN_SECONDS : 5 * MINUTE_IN_SECONDS ); // Short cache for empty answers (may be a busy service).
			}
			foreach ( $results as $r ) {
				$clean = self::clean_result( $pid, $provider->label(), $r );
				if ( '' !== $clean['title'] ) { // Nothing useful to fill in without a title.
					$out[] = $clean;
				}
			}
		}
		return $out;
	}

	/** Plain-language reason a source gave no results. */
	private static function problem_text( $label, $status ) {
		$label = sanitize_text_field( $label );
		if ( 401 === $status || 403 === $status ) {
			/* translators: %s: source name */
			return sprintf( __( '%s refused the request: check its API key in Library - Settings.', 'church-library' ), $label );
		}
		if ( 429 === $status ) {
			/* translators: %s: source name */
			return sprintf( __( '%s is limiting requests right now (quota reached). Try again later, or add an API key in Library - Settings.', 'church-library' ), $label );
		}
		/* translators: %s: source name */
		return sprintf( __( '%s did not answer. Try again in a few minutes.', 'church-library' ), $label );
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
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_code, title FROM $t WHERE is_archived = 0 AND REPLACE(identifier, '-', '') = %s LIMIT 5", $query ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, item_code, title FROM $t WHERE is_archived = 0 AND title LIKE %s ORDER BY title LIMIT 5", '%' . $wpdb->esc_like( $query ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			CLIB_Audit::log_error( $provider_id, 'Cover URL refused: host not on the allow-list.', 'cover' );
			return new WP_Error( 'clib_cover', __( 'Cover host not allowed.', 'church-library' ) );
		}
		$id = CLIB_Items::sideload_image( $url, 'cover-' . sanitize_file_name( $provider_id ) . '.jpg', $title );
		if ( is_wp_error( $id ) ) {
			/* translators: %s: error message */
			CLIB_Audit::log_error( $provider_id, sprintf( __( 'Cover image could not be imported: %s', 'church-library' ), $id->get_error_message() ), 'cover' );
		} else {
			CLIB_Attribution::record_cover( $id, $provider_id ); // For the image credit.
		}
		return $id;
	}

	/** HTTP status of the last provider request (0 = no answer), so failures can be reported. */
	public static $last_status = 200;

	/** Body of the last refused request (4xx only, at most 4 KB), for providers that explain errors. */
	public static $last_error_body = '';

	/** Plain-language reason set by a provider for its last failure ('' = use the generic text). */
	public static $last_problem = '';

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
		self::$last_status     = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		self::$last_error_body = self::$last_status >= 400 && self::$last_status < 500 ? substr( (string) wp_remote_retrieve_body( $res ), 0, 4096 ) : '';
		if ( 200 !== self::$last_status ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $res );
	}

	/**
	 * This server's outbound IP addresses, as seen from outside, for adding to an
	 * API key's IP restriction. Asks ipify.org (no key, no data sent but the
	 * request itself). Only well-formed public addresses are returned.
	 *
	 * @return array [ 'ipv4' => string, 'ipv6' => string ] ('' when unknown).
	 */
	public static function outbound_ips() {
		$out = array(
			'ipv4' => '',
			'ipv6' => '',
		);
		$services = array(
			'ipv4' => array( 'https://api.ipify.org/?format=json', FILTER_FLAG_IPV4 ),
			'ipv6' => array( 'https://api6.ipify.org/?format=json', FILTER_FLAG_IPV6 ),
		);
		foreach ( $services as $family => $svc ) {
			$res = wp_safe_remote_get(
				$svc[0],
				array(
					'timeout'             => 6,
					'redirection'         => 0,
					'limit_response_size' => 1024,
					'user-agent'          => 'ChurchLibrary/' . CLIB_VERSION,
				)
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				continue; // No IPv6 route is normal on many hosts.
			}
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true, 4 );
			$ip   = is_array( $data ) && isset( $data['ip'] ) && is_string( $data['ip'] ) ? trim( $data['ip'] ) : '';
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP, $svc[1] | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$out[ $family ] = $ip;
			}
		}
		return $out;
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
