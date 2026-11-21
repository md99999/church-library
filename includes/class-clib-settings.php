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

	public static function valid_discogs_token( $v ) {
		return is_string( $v ) && 1 === preg_match( '/^[A-Za-z0-9]{20,64}$/', $v );
	}

	/** Google API key: "AIza" followed by 35 letters, digits, - or _ (39 characters in all). */
	public static function valid_google_key( $v ) {
		return is_string( $v ) && 1 === preg_match( '/^AIza[A-Za-z0-9_-]{35}$/', $v );
	}

	/**
	 * What each secret field expects, for the error shown when a value is refused.
	 * field => [ label, expected format ].
	 */
	private static function secret_fields() {
		return array(
			'discogs_token'    => array( __( 'Discogs personal access token', 'church-library' ), __( '20 to 64 letters and digits', 'church-library' ) ),
			'discogs_key'      => array( __( 'Discogs consumer key', 'church-library' ), __( '10 to 64 letters and digits', 'church-library' ) ),
			'discogs_secret'   => array( __( 'Discogs consumer secret', 'church-library' ), __( '10 to 64 letters and digits', 'church-library' ) ),
			'google_books_key' => array( __( 'Google Books API key', 'church-library' ), __( '39 characters starting with AIza', 'church-library' ) ),
		);
	}

	/**
	 * Clean a pasted credential: credentials never contain spaces, line breaks or
	 * invisible characters, so those (often picked up when copying) are removed.
	 * Nothing else is changed: a value that is still wrong is refused, never cut.
	 */
	private static function clean_secret( $v ) {
		if ( ! is_string( $v ) ) {
			return '';
		}
		$v = wp_unslash( $v );
		if ( strlen( $v ) > 1024 ) {
			return $v; // Far too long to be a key: refused by the validator as-is.
		}
		$clean = preg_replace( '/[\s\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+/u', '', $v );
		return null === $clean ? $v : $clean; // Invalid UTF-8: leave it for the validator to refuse.
	}

	/**
	 * Secret field rules shared by every API credential: blank keeps the saved
	 * value, the "clear" box removes it, anything else must pass $valid or it is
	 * refused (and reported with the length received) while the old value is
	 * kept. Values are never truncated, and never echoed back to the page.
	 */
	private static function secret( array $raw, array &$out, $field, $clear, $valid ) {
		if ( ! empty( $raw[ $clear ] ) ) {
			$out[ $field ] = '';
			return;
		}
		$v = isset( $raw[ $field ] ) ? self::clean_secret( $raw[ $field ] ) : '';
		if ( '' === $v ) {
			return;
		}
		if ( call_user_func( $valid, $v ) ) {
			$out[ $field ] = $v;
			return;
		}
		$f    = self::secret_fields();
		$info = isset( $f[ $field ] ) ? $f[ $field ] : array( $field, '' );
		/* translators: 1: setting name, 2: number of characters received, 3: expected format */
		self::$rejected[] = sprintf( __( '%1$s (received %2$d characters; expected %3$s)', 'church-library' ), $info[0], strlen( $v ), $info[1] );
	}

	/**
	 * Hint about a saved credential so an admin can check it was stored whole:
	 * its length and last four characters. Empty when nothing is saved.
	 */
	public static function secret_hint( $field ) {
		$v = (string) self::get( $field );
		if ( '' === $v ) {
			return '';
		}
		$valid = array(
			'discogs_token'    => 'valid_discogs_token',
			'discogs_key'      => 'valid_discogs_credential',
			'discogs_secret'   => 'valid_discogs_credential',
			'google_books_key' => 'valid_google_key',
		);
		/* translators: 1: last four characters of the saved key, 2: its length */
		$hint = sprintf( __( 'Saved: ends in %1$s, %2$d characters.', 'church-library' ), substr( $v, -4 ), strlen( $v ) );
		if ( isset( $valid[ $field ] ) && ! call_user_func( array( __CLASS__, $valid[ $field ] ), $v ) ) {
			$hint .= ' ' . __( 'This saved value is not a valid key and is not used. Enter the key again.', 'church-library' );
		}
		return $hint;
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

		// API credentials: blank keeps the saved value, a "clear" box removes it, anything else must be valid.
		self::$rejected = array();
		self::secret( $raw, $out, 'discogs_token', 'discogs_token_clear', array( __CLASS__, 'valid_discogs_token' ) );
		if ( ! empty( $raw['discogs_app_clear'] ) ) {
			$out['discogs_key']    = '';
			$out['discogs_secret'] = '';
		} else {
			self::secret( $raw, $out, 'discogs_key', 'discogs_key_clear', array( __CLASS__, 'valid_discogs_credential' ) );
			self::secret( $raw, $out, 'discogs_secret', 'discogs_secret_clear', array( __CLASS__, 'valid_discogs_credential' ) );
		}
		self::secret( $raw, $out, 'google_books_key', 'google_books_key_clear', array( __CLASS__, 'valid_google_key' ) );
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
