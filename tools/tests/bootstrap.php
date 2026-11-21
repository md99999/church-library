<?php
/**
 * Minimal stand-ins for the WordPress functions the tested classes call, so the
 * tests run with plain PHP and no WordPress install or database.
 *
 * The sanitizers (sanitize_text_field, wp_check_invalid_utf8, sanitize_key,
 * esc_like, esc_attr) follow WordPress core's own code paths closely, so the
 * results match what a live site produces. $wpdb->prepare() is a recording fake:
 * tests assert on the SQL template and the bound values separately.
 *
 * Development only: tools/ is never packed into a release zip.
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['clib_test_transients'] = array();
$GLOBALS['clib_test_user']       = 0;
$GLOBALS['clib_test_caps']       = array();

function __( $s ) {
	return $s;
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function wp_check_invalid_utf8( $string, $strip = false ) {
	$string = (string) $string;
	if ( '' === $string ) {
		return '';
	}
	if ( 1 === @preg_match( '/^./us', $string ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $string;
	}
	if ( $strip ) {
		$prev = mb_substitute_character();
		mb_substitute_character( 'none' );
		$out = mb_convert_encoding( $string, 'UTF-8', 'UTF-8' );
		mb_substitute_character( $prev );
		return $out;
	}
	return '';
}
function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}
function wp_pre_kses_less_than( $content ) {
	return preg_replace_callback(
		'%<[^>]*?((?=<)|>|$)%',
		function ( $m ) {
			return false === strpos( $m[0], '>' ) ? esc_html( $m[0] ) : $m[0];
		},
		$content
	);
}
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
	$text = strip_tags( $text );
	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}
	return trim( $text );
}
function sanitize_text_field( $str ) {
	$filtered = wp_check_invalid_utf8( $str );
	if ( false !== strpos( $filtered, '<' ) ) {
		$filtered = wp_pre_kses_less_than( $filtered );
		$filtered = wp_strip_all_tags( $filtered, false );
		$filtered = str_replace( "<\n", "&lt;\n", $filtered );
	}
	$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	$filtered = trim( $filtered );
	$found    = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}
	if ( $found ) {
		$filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
	}
	return $filtered;
}
function sanitize_textarea_field( $s ) {
	return sanitize_text_field( $s );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function wp_hash( $data ) {
	return hash_hmac( 'md5', (string) $data, 'test-salt' );
}
function is_user_logged_in() {
	return $GLOBALS['clib_test_user'] > 0;
}
function get_current_user_id() {
	return (int) $GLOBALS['clib_test_user'];
}
function get_transient( $key ) {
	return isset( $GLOBALS['clib_test_transients'][ $key ] ) ? $GLOBALS['clib_test_transients'][ $key ]['v'] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['clib_test_transients'][ $key ] = array(
		'v'   => $value,
		'ttl' => $ttl,
	);
	return true;
}

/** Recording $wpdb fake. */
class CLIB_Test_WPDB {
	public $prefix     = 'wp_';
	public $last_error = '';
	public $prepared   = array(); // Each: [ template, values ].
	public $raw        = array(); // Queries run without prepare().

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->prepared[] = array( $query, $args );
		return array( $query, $args ); // Opaque token for the get_* methods.
	}
	public $total = 0; // What COUNT(*) returns.

	public function get_var( $q ) {
		if ( is_string( $q ) ) {
			$this->raw[] = $q;
		}
		return $this->total;
	}
	public function get_results( $q ) {
		return array();
	}
	public function get_col( $q ) {
		return array();
	}
	public function suppress_errors( $s = true ) {
		return false;
	}
}
$GLOBALS['wpdb'] = new CLIB_Test_WPDB();

class CLIB_DB {
	public static function table( $name ) {
		return 'wp_clib_' . $name;
	}
}

require dirname( __DIR__, 2 ) . '/includes/class-clib-util.php';
require dirname( __DIR__, 2 ) . '/includes/class-clib-items.php';

/* ------------------------------------------------------------ Tiny runner. */
$GLOBALS['clib_test_pass'] = 0;
$GLOBALS['clib_test_fail'] = array();

function clib_check( $cond, $name ) {
	if ( $cond ) {
		++$GLOBALS['clib_test_pass'];
	} else {
		$GLOBALS['clib_test_fail'][] = $name;
		fwrite( STDERR, "FAIL: $name\n" );
	}
}

function clib_done() {
	$p = $GLOBALS['clib_test_pass'];
	$f = count( $GLOBALS['clib_test_fail'] );
	echo "$p passed, $f failed\n";
	exit( $f ? 1 : 0 );
}
