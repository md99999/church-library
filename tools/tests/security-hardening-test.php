<?php
/**
 * Tests for the 1.4.1 OWASP hardening: CSV formula neutralising, credential
 * headers never following redirects, and staff-only item fields.
 *
 * Run from the plugin folder:  php tools/tests/security-hardening-test.php
 * Exit code 0 means every check passed.
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/bootstrap.php';

/* ------------------------------------------------ Stand-ins used only here. */

define( 'CLIB_VERSION', 'test' );

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['clib_test_caps'], true );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
}
function wp_safe_remote_get( $url, $args ) {
	$GLOBALS['clib_test_http'][] = array( $url, $args );
	return array( 'code' => 200, 'body' => '{}' );
}
function wp_remote_retrieve_response_code( $res ) {
	return $res['code'];
}
function wp_remote_retrieve_body( $res ) {
	return $res['body'];
}
function is_wp_error( $v ) {
	return false;
}
function add_action() {}
function apply_filters( $hook, $value ) {
	return $value;
}

class CLIB_Settings {
	public static function get( $key ) {
		return null;
	}
}

require dirname( __DIR__, 2 ) . '/includes/class-clib-perms.php';
require dirname( __DIR__, 2 ) . '/includes/class-clib-lookup.php';

/* ----------------------------------------------- CSV formula neutralising. */

$formulas = array(
	'=HYPERLINK("https://evil.example","x")',
	'+1+1',
	'-2+3',
	'@SUM(A1)',
	"\t=1",
	"\r=1",
	"\n=1",
	' =cmd|"/c calc"!A1',
	'   +1',
	'  @x',
);
foreach ( $formulas as $i => $f ) {
	clib_check( CLIB_Util::csv_is_formula( $f ), "formula #$i detected" );
	clib_check( "'" . $f === CLIB_Util::csv_safe( $f ), "formula #$i neutralised with a leading quote" );
}
$plain = array( '', 'The Hymnal', '12.50', 'Smith, John', '   ', ' plain text', "Don't panic", 'a=b' );
foreach ( $plain as $i => $p ) {
	clib_check( ! CLIB_Util::csv_is_formula( $p ), "plain #$i not treated as a formula" );
	clib_check( $p === CLIB_Util::csv_safe( $p ), "plain #$i unchanged" );
}

/* ------------------------------- Credentials never follow an HTTP redirect. */

$cases = array(
	array( array(), 2, 'no headers' ),
	array( array( 'Accept-Language' => 'en' ), 2, 'harmless header' ),
	array( array( 'Authorization' => 'Discogs token=abc' ), 0, 'Authorization' ),
	array( array( 'authorization' => 'x' ), 0, 'lower-case authorization' ),
	array( array( 'X-goog-api-key' => 'AIza' ), 0, 'Google API key header' ),
	array( array( ' X-API-KEY ' => 'k' ), 0, 'padded upper-case X-API-Key' ),
	array( array( 'Cookie' => 'a=b' ), 0, 'Cookie' ),
);
foreach ( $cases as $c ) {
	$GLOBALS['clib_test_http'] = array();
	CLIB_Lookup::http_body( 'https://api.example.org/x', $c[0] );
	$args = $GLOBALS['clib_test_http'][0][1];
	clib_check( $c[1] === $args['redirection'], 'redirection for ' . $c[2] );
}
$GLOBALS['clib_test_http'] = array();
clib_check( null === CLIB_Lookup::http_body( 'http://api.example.org/x' ), 'plain http refused' );
clib_check( array() === $GLOBALS['clib_test_http'], 'plain http never requested' );

/* ------------------------------------------------- Staff-only item fields. */

$GLOBALS['clib_test_user'] = 0;
$GLOBALS['clib_test_caps'] = array( 'clib_circulate' );
clib_check( ! CLIB_Perms::can_see_staff_fields(), 'guest never sees staff fields' );

$GLOBALS['clib_test_user'] = 7;
$GLOBALS['clib_test_caps'] = array( 'clib_view' );
clib_check( ! CLIB_Perms::can_see_staff_fields(), 'view-only role does not see staff fields' );
foreach ( array( 'clib_circulate', 'clib_manage_items', 'clib_reports' ) as $cap ) {
	$GLOBALS['clib_test_caps'] = array( 'clib_view', $cap );
	clib_check( CLIB_Perms::can_see_staff_fields(), "$cap sees staff fields" );
}

clib_done();
