<?php
/**
 * Plugin settings (stored in one option, validated on save).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Settings {

	const OPTION = 'clib_settings';

	/** Word an administrator must type to enable delete-on-uninstall. */
	const DELETE_PHRASE = 'DELETE';

	public static function defaults() {
		return array(
			'public_access'    => 1,  // Guests may view the catalog.
			'loan_days'        => 21,
			'per_page'         => 25,
			'lookup_enabled'   => 1,  // Title / ISBN lookup master switch.
			'lookup_providers' => array( 'openlibrary', 'googlebooks', 'wikidata', 'musicbrainz', 'hymnary', 'imslp' ),
			'google_books_key' => '', // Optional; raises the Google Books quota.
			'discogs_token'    => '',
			'discogs_key'      => '',
			'discogs_secret'   => '',
			'full_width'       => 1,  // Library pages without the theme sidebar.
			'show_borrowers'   => 0,  // Show "checked out by" names to view-only users.
			'uninstall_delete' => 0,  // Drop tables on uninstall.
			'catalog_page_id'  => 0,
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		} else {
			update_option( self::OPTION, array_merge( self::defaults(), $current ), false );
		}
	}

	/** Settings of sources that were removed (TMDB, dropped in 1.3.2). Never read; purged on upgrade. */
	const REMOVED_KEYS = array( 'tmdb_token', 'tmdb_key' );

	public static function all() {
		$opt = get_option( self::OPTION );
		$all = array_merge( self::defaults(), is_array( $opt ) ? $opt : array() );
		return array_diff_key( $all, array_flip( self::REMOVED_KEYS ) );
	}

	/** Delete stored credentials and choices of removed sources (run once per plugin version). */
	public static function purge_removed() {
		$opt = get_option( self::OPTION );
		if ( ! is_array( $opt ) ) {
			return;
		}
		$clean = array_diff_key( $opt, array_flip( self::REMOVED_KEYS ) );
		if ( isset( $clean['lookup_providers'] ) && is_array( $clean['lookup_providers'] ) ) {
			$had_tmdb                  = in_array( 'tmdb', $clean['lookup_providers'], true );
			$clean['lookup_providers'] = array_values( array_diff( $clean['lookup_providers'], array( 'tmdb' ) ) );
			// Sites that used TMDB for movies get the free Wikidata source instead.
			if ( $had_tmdb && ! in_array( 'wikidata', $clean['lookup_providers'], true ) ) {
				$clean['lookup_providers'][] = 'wikidata';
			}
		}
		if ( $clean !== $opt ) {
			update_option( self::OPTION, $clean, false );
		}
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Fields rejected by the last sanitize() call (for an admin notice). */
	public static $rejected = array();

	/** Discogs consumer key / secret: letters and digits only, sane length. */
	public static function valid_discogs_credential( $v ) {
		return is_string( $v ) && 1 === preg_match( '/^[A-Za-z0-9]{10,64}$/', $v );
	}

	/** Google API key: "AIza" style keys are 39 characters of letters, digits, - and _. */
	public static function valid_google_key( $v ) {
		return is_string( $v ) && 1 === preg_match( '/^[A-Za-z0-9_-]{30,64}$/', $v );
	}

	/**
	 * Secret field rules shared by every API credential: blank keeps the saved
	 * value, the "clear" box removes it, anything else must pass $valid or it is
	 * rejected (and reported) while the old value is kept. Secrets are never
	 * echoed back to the settings page.
	 */
	private static function secret( array $raw, array &$out, $field, $clear, $valid, $label ) {
		if ( ! empty( $raw[ $clear ] ) ) {
			$out[ $field ] = '';
			return;
		}
		$v = isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) ? trim( wp_unslash( $raw[ $field ] ) ) : '';
		if ( '' === $v ) {
			return;
		}
		if ( call_user_func( $valid, $v ) ) {
			$out[ $field ] = $v;
		} else {
			self::$rejected[] = $label;
		}
	}

	/** Sanitize a raw settings array (from POST). Unknown keys are dropped. */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::all();

		$out['public_access']    = empty( $raw['public_access'] ) ? 0 : 1;
		$out['lookup_enabled']   = empty( $raw['lookup_enabled'] ) ? 0 : 1;
		$out['show_borrowers']   = empty( $raw['show_borrowers'] ) ? 0 : 1;
		$out['full_width']       = empty( $raw['full_width'] ) ? 0 : 1;
		$out['uninstall_delete'] = empty( $raw['uninstall_delete'] ) ? 0 : 1;
		$out['loan_days']        = CLIB_Util::int( isset( $raw['loan_days'] ) ? $raw['loan_days'] : 21, 1, 365 );
		$out['per_page']         = CLIB_Util::int( isset( $raw['per_page'] ) ? $raw['per_page'] : 25, 5, 200 );

		$known                   = array_keys( CLIB_Lookup::providers() );
		$posted                  = isset( $raw['lookup_providers'] ) && is_array( $raw['lookup_providers'] ) ? array_map( 'sanitize_key', $raw['lookup_providers'] ) : array();
		$out['lookup_providers'] = array_values( array_intersect( $known, $posted ) );

		// Token: blank keeps the saved one; "clear" box removes it; otherwise it must look like a Discogs token.
		$token = isset( $raw['discogs_token'] ) && is_string( $raw['discogs_token'] ) ? trim( wp_unslash( $raw['discogs_token'] ) ) : '';
		self::$rejected = array();
		if ( ! empty( $raw['discogs_token_clear'] ) ) {
			$out['discogs_token'] = '';
		} elseif ( '' !== $token ) {
			if ( preg_match( '/^[A-Za-z0-9]{20,64}$/', $token ) ) {
				$out['discogs_token'] = $token;
			} else {
				self::$rejected[] = __( 'Discogs personal access token', 'church-library' );
			}
		}

		// Consumer key + secret: same rules (blank keeps, clear box removes, must be letters/digits).
		if ( ! empty( $raw['discogs_app_clear'] ) ) {
			$out['discogs_key']    = '';
			$out['discogs_secret'] = '';
		} else {
			foreach ( array(
				'discogs_key'    => __( 'Discogs consumer key', 'church-library' ),
				'discogs_secret' => __( 'Discogs consumer secret', 'church-library' ),
			) as $field => $label ) {
				$v = isset( $raw[ $field ] ) && is_string( $raw[ $field ] ) ? trim( wp_unslash( $raw[ $field ] ) ) : '';
				if ( '' === $v ) {
					continue;
				}
				if ( self::valid_discogs_credential( $v ) ) {
					$out[ $field ] = $v;
				} else {
					self::$rejected[] = $label;
				}
			}
		}

		self::secret( $raw, $out, 'google_books_key', 'google_books_key_clear', array( __CLASS__, 'valid_google_key' ), __( 'Google Books API key', 'church-library' ) );
		return $out;
	}

	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;
		update_option( self::OPTION, $all, false );
	}

	public static function save( array $values ) {
		update_option( self::OPTION, $values, false );
	}
}
