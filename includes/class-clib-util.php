<?php
/**
 * Input sanitization / validation helpers.
 *
 * Every value that comes from a request goes through one of these helpers:
 * unslash -> sanitize -> strip control characters -> enforce a hard length
 * limit (so oversized or appended strings are truncated, never stored whole).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Util {

	/** Storage status values (whitelist). */
	const STATUSES = array( 'available', 'checked_out', 'not_available' );

	public static function status_labels() {
		return array(
			'available'     => __( 'Checked In', 'church-library' ),
			'checked_out'   => __( 'Checked Out', 'church-library' ),
			'not_available' => __( 'Not Available', 'church-library' ),
		);
	}

	/** Built-in condition values (used to seed the list and as a fallback). */
	public static function default_conditions() {
		return array(
			'poor'      => __( 'Poor', 'church-library' ),
			'fair'      => __( 'Fair', 'church-library' ),
			'good'      => __( 'Good', 'church-library' ),
			'excellent' => __( 'Excellent', 'church-library' ),
		);
	}

	/** Built-in music difficulty values (used to seed the list and as a fallback). */
	public static function default_difficulties() {
		return array(
			'easy'        => __( 'Easy', 'church-library' ),
			'medium'      => __( 'Medium', 'church-library' ),
			'medium_hard' => __( 'Medium Hard', 'church-library' ),
			'hard'        => __( 'Hard', 'church-library' ),
		);
	}

	/**
	 * Condition values from Library - List of Values (stored code => label).
	 *
	 * @param bool $active_only Only entries shown in dropdowns.
	 */
	public static function condition_labels( $active_only = false ) {
		return self::coded_list( 'condition', $active_only, self::default_conditions() );
	}

	/** Music difficulty values from Library - List of Values (stored code => label). */
	public static function difficulty_labels( $active_only = false ) {
		return self::coded_list( 'difficulty', $active_only, self::default_difficulties() );
	}

	private static function coded_list( $type, $active_only, array $fallback ) {
		if ( ! class_exists( 'CLIB_LOV' ) ) {
			return $fallback;
		}
		$out = array();
		foreach ( CLIB_LOV::all( $type, $active_only ) as $row ) {
			if ( '' !== $row->code ) {
				$out[ $row->code ] = $row->label;
			}
		}
		return ( $out || CLIB_LOV::count( $type ) > 0 ) ? $out : $fallback;
	}

	public static function label( $map, $key ) {
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}

	/**
	 * Remove control characters (except tab/newline for textareas) and
	 * invalid UTF-8.
	 */
	private static function strip_controls( $value, $allow_newlines = false ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		$re    = $allow_newlines ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
		$clean = preg_replace( $re, '', $value );
		return null === $clean ? '' : $clean;
	}

	/** Hard length cap (multibyte safe). */
	public static function cap( $value, $max ) {
		$value = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, (int) $max, 'UTF-8' );
		}
		return substr( $value, 0, (int) $max );
	}

	/** Single-line text. */
	public static function text( $value, $max = 255 ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( wp_unslash( (string) $value ) );
		return trim( self::cap( self::strip_controls( $value ), $max ) );
	}

	/** Multi-line plain text. */
	public static function textarea( $value, $max = 5000 ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$value = sanitize_textarea_field( wp_unslash( (string) $value ) );
		return trim( self::cap( self::strip_controls( $value, true ), $max ) );
	}

	/** Non-negative integer within range. */
	public static function int( $value, $min = 0, $max = PHP_INT_MAX ) {
		if ( is_array( $value ) || is_object( $value ) || ! is_numeric( $value ) ) {
			return $min;
		}
		return max( $min, min( $max, (int) $value ) );
	}

	/** Optional ID: positive int or null. */
	public static function id_or_null( $value ) {
		$v = self::int( $value, 0 );
		return $v > 0 ? $v : null;
	}

	/** Whitelisted enum value. */
	public static function enum( $value, array $allowed, $default = '' ) {
		if ( ! is_string( $value ) ) {
			return $default;
		}
		$value = sanitize_key( wp_unslash( $value ) );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/** Money value -> string with 2 decimals, or null. */
	public static function money( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$value = preg_replace( '/[^0-9.]/', '', (string) wp_unslash( $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		$f = (float) $value;
		if ( $f < 0 || $f > 99999999 ) {
			return null;
		}
		return number_format( $f, 2, '.', '' );
	}

	/** Y-m-d date or null. */
	public static function date( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( wp_unslash( $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$d = DateTime::createFromFormat( '!Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : null;
	}

	/** Upper-case code (media type prefixes): A-Z 0-9 only. */
	public static function code( $value, $max = 10 ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $value ) ) );
		return substr( $value, 0, $max );
	}

	/** Item code / barcode style token: letters, digits, dash, dot, underscore. */
	public static function token( $value, $max = 64 ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = preg_replace( '/[^A-Za-z0-9\-_.]/', '', wp_unslash( $value ) );
		return substr( $value, 0, $max );
	}

	/** Neutralise spreadsheet formula injection for CSV export. */
	public static function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/** Read a POST field. */
	public static function post( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers verify nonces first.
		return isset( $_POST[ $key ] ) ? $_POST[ $key ] : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/** Read a GET field. */
	public static function get( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET[ $key ] ) ? $_GET[ $key ] : $default; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/** Prepared IN() placeholder list for integers. */
	public static function in_placeholders( array $values ) {
		return implode( ',', array_fill( 0, count( $values ), '%d' ) );
	}

	/** Current time in MySQL format (site timezone). */
	public static function now() {
		return current_time( 'mysql' );
	}

	/** Date for display. */
	public static function fmt_date( $mysql ) {
		if ( empty( $mysql ) || '0000-00-00' === substr( $mysql, 0, 10 ) ) {
			return '';
		}
		$ts = strtotime( $mysql );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}
}
