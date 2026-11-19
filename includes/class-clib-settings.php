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

	public static function defaults() {
		return array(
			'public_access'    => 1,  // Guests may view the catalog.
			'loan_days'        => 21,
			'per_page'         => 25,
			'lookup_enabled'   => 1,  // Open Library title / ISBN lookup.
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

	public static function all() {
		$opt = get_option( self::OPTION );
		return array_merge( self::defaults(), is_array( $opt ) ? $opt : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Sanitize a raw settings array (from POST). Unknown keys are dropped. */
	public static function sanitize( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::all();

		$out['public_access']    = empty( $raw['public_access'] ) ? 0 : 1;
		$out['lookup_enabled']   = empty( $raw['lookup_enabled'] ) ? 0 : 1;
		$out['show_borrowers']   = empty( $raw['show_borrowers'] ) ? 0 : 1;
		$out['uninstall_delete'] = empty( $raw['uninstall_delete'] ) ? 0 : 1;
		$out['loan_days']        = CLIB_Util::int( isset( $raw['loan_days'] ) ? $raw['loan_days'] : 21, 1, 365 );
		$out['per_page']         = CLIB_Util::int( isset( $raw['per_page'] ) ? $raw['per_page'] : 25, 5, 200 );
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
