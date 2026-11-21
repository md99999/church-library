<?php
/**
 * Renders public/views/catalog.php (the search listing) with hostile query
 * strings and checks the HTML: the term is escaped, page links carry only the
 * whitelisted, encoded search values, and unrelated parameters are not echoed.
 *
 * Run from the plugin folder:  php tools/tests/catalog-render-test.php
 *
 * The URL helpers below are reduced versions of WordPress core's add_query_arg()
 * and esc_url() (same encoding behaviour for these inputs).
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/bootstrap.php';

function esc_url( $url ) {
	$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\x80-\xff]|i', '', (string) $url );
	if ( preg_match( '/^\s*(javascript|data|vbscript):/i', $url ) ) {
		return '';
	}
	return str_replace( array( '&amp;', "'" ), array( '&#038;', '&#039;' ), str_replace( '&', '&#038;', $url ) );
}
function add_query_arg( $args, $uri ) {
	$frag = '';
	if ( false !== strpos( $uri, '#' ) ) {
		list( $uri, $frag ) = explode( '#', $uri, 2 );
		$frag               = '#' . $frag;
	}
	$base  = $uri;
	$query = '';
	if ( false !== strpos( $uri, '?' ) ) {
		list( $base, $query ) = explode( '?', $uri, 2 );
	}
	parse_str( $query, $qs );
	$qs = array_map( 'urlencode', $qs ); // Core re-encodes existing values (urlencode_deep).
	foreach ( $args as $k => $v ) {
		$qs[ $k ] = $v; // New values are NOT encoded by core.
	}
	$pairs = array();
	foreach ( $qs as $k => $v ) {
		if ( false !== $v && null !== $v ) {
			$pairs[] = $k . '=' . $v;
		}
	}
	return $base . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $frag;
}
function get_permalink() {
	return 'https://church.example/?page_id=42';
}
function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['clib_test_caps'], true );
}
function apply_filters( $hook, $value ) {
	return $value;
}
function esc_html__( $s ) {
	return esc_html( $s );
}
function esc_html_e( $s ) {
	echo esc_html( $s );
}
function esc_attr_e( $s ) {
	echo esc_attr( $s );
}
function _n( $one, $many, $n ) {
	return 1 === (int) $n ? $one : $many;
}
function number_format_i18n( $n ) {
	return number_format( (float) $n );
}
function wp_get_attachment_image() {
	return '';
}

class CLIB_Settings {
	public static $per_page = 25;
	public static function get( $k ) {
		return 'per_page' === $k ? self::$per_page : null;
	}
}
class CLIB_Form {
	public static function lov_options() {
		return '';
	}
	public static function map_options() {
		return '';
	}
}
class CLIB_Audit {
	public static $logged = array();
	public static function log_error( $src, $msg ) {
		self::$logged[] = $msg;
	}
}

/** Render the listing for a $_GET and return the HTML. */
function clib_render( array $get, $total = 200 ) {
	$_GET                       = $get;
	$GLOBALS['wpdb']->total     = $total;
	$GLOBALS['wpdb']->prepared  = array();
	$GLOBALS['clib_test_transients'] = array();
	ob_start();
	include dirname( __DIR__, 2 ) . '/public/views/catalog.php';
	return ob_get_clean();
}

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

// Hostile term, an injected extra parameter and an injected page_id.
$html = clib_render(
	array(
		'clib_q'    => 'a&b=c "><svg onload=alert(1)> \' OR 1=1',
		'evil'      => '"><script>alert(1)</script>',
		'clib_page' => '3',
		'clib_sort' => 'creator',
		'clib_type' => '7 OR 1=1',
		'page_id'   => '42"><x',
	)
);
clib_check( false === stripos( $html, '<script' ), 'no script tag in output' );
clib_check( false === stripos( $html, '<svg' ), 'no svg tag in output' );
clib_check( false === strpos( $html, 'evil' ), 'unrelated parameter is not reflected anywhere' );
clib_check( 0 === preg_match( '/<[^>]*\sonload=/i', $html ), 'no onload attribute injected' );
preg_match_all( '/href="([^"]*)"/', $html, $m );
$links = $m[1];
clib_check( count( $links ) >= 5, 'page links rendered (' . count( $links ) . ')' );
$pager = array_values( array_filter( $links, function ( $u ) {
	return false !== strpos( $u, 'clib_q=' );
} ) );
clib_check( count( $pager ) >= 4, 'page links keep the search term' );
foreach ( $pager as $u ) {
	clib_check( 0 === strpos( $u, 'https://church.example/?page_id=42&#038;' ), 'page link starts at the permalink: ' . $u );
	clib_check( false === strpos( $u, ' ' ) && false === strpos( $u, '"' ) && false === strpos( $u, '<' ), 'page link has no raw space, quote or tag' );
	clib_check( false === strpos( $u, '&#038;b=' ), 'an & in the term cannot add a parameter' );
	clib_check( false !== strpos( $u, 'clib_sort=creator' ), 'whitelisted sort kept' );
	clib_check( false === strpos( $u, 'clib_type' ), 'non-numeric type filter dropped (became 0)' );
	clib_check( false === strpos( $u, '%22%3E%3Cx' ), 'injected page_id not copied into links' );
}
clib_check( 1 === preg_match( '/<span aria-current="page" class="page-numbers current">3<\/span>/', $html ), 'page 3 is current' );
clib_check( false === strpos( $html, 'name="page_id"' ), 'non-numeric page_id is dropped, no hidden field' );
clib_check( 1 === preg_match( '/name="clib_q" maxlength="100" value="([^"]*)"/', $html, $vm ), 'search box present with maxlength' );
clib_check( isset( $vm[1] ) && false === strpos( $vm[1], '<' ) && false === strpos( $vm[1], '"' ), 'search box value is escaped' );

$html = clib_render( array( 'page_id' => '42' ) );
clib_check( 1 === preg_match( '/name="page_id" value="42"/', $html ), 'numeric page_id kept for plain permalinks' );

// Page beyond the end is clamped in the links; huge page numbers are capped.
$html = clib_render( array( 'clib_page' => '999999999' ), 60 );
clib_check( 1 === preg_match( '/class="page-numbers current">3</', $html ), 'out-of-range page shows last page as current' );
$last = end( $GLOBALS['wpdb']->prepared );
clib_check( ( 10000 - 1 ) * 25 === $last[1][ count( $last[1] ) - 1 ], 'requested page capped at 10000 before the query' );

// A damaged per_page setting cannot cause a division by zero.
CLIB_Settings::$per_page = 0;
$html                    = clib_render( array(), 12 );
clib_check( false !== strpos( $html, '12 items' ), 'per_page 0 renders without error' );
CLIB_Settings::$per_page = 25;

// Guests cannot match barcodes; staff can.
clib_render( array( 'clib_q' => 'BC1' ) );
$last = end( $GLOBALS['wpdb']->prepared );
clib_check( false === strpos( $last[0], 'barcode' ), 'guest catalog search skips barcode' );
$GLOBALS['clib_test_caps'] = array( 'clib_circulate' );
clib_render( array( 'clib_q' => 'BC1' ) );
$last = end( $GLOBALS['wpdb']->prepared );
clib_check( false !== strpos( $last[0], 'barcode' ), 'staff catalog search includes barcode' );
$GLOBALS['clib_test_caps'] = array();

// Rate limit: the 61st search in a minute from one IP is refused without a query.
$_GET                            = array( 'clib_q' => 'hymn' );
$GLOBALS['clib_test_transients'] = array();
for ( $i = 0; $i < 60; $i++ ) {
	CLIB_Util::rate_limited( 'catalog_search', 60, 60 );
}
$GLOBALS['wpdb']->prepared = array();
ob_start();
include dirname( __DIR__, 2 ) . '/public/views/catalog.php';
$html = ob_get_clean();
clib_check( false !== strpos( $html, 'Too many searches' ), 'over-limit visitor sees the wait message' );
clib_check( array() === $GLOBALS['wpdb']->prepared, 'over-limit request runs no query' );

// Browsing without a term is never limited.
$_GET = array( 'clib_page' => '2' );
ob_start();
include dirname( __DIR__, 2 ) . '/public/views/catalog.php';
$html = ob_get_clean();
clib_check( false === strpos( $html, 'Too many searches' ), 'browsing without a term is not limited' );

// A failed query shows nothing technical and is logged.
$GLOBALS['wpdb'] = new class() extends CLIB_Test_WPDB {
	public function get_results( $q ) {
		$this->last_error = "Table 'wp_clib_items' doesn't exist";
		return null;
	}
};
$html = clib_render( array( 'clib_q' => 'x' ) );
clib_check( false === strpos( $html, 'wp_clib_items' ), 'database error text is not shown' );
clib_check( false !== strpos( $html, 'No items found' ), 'failed query shows the normal empty result' );
clib_check( 1 === count( CLIB_Audit::$logged ), 'failed query is logged' );

clib_done();
