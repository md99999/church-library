<?php
/**
 * Building and Room are for logged-in users only (1.4.0). Renders
 * public/views/catalog.php as a guest and as a logged-in user and checks the
 * list, the item detail, the filter form, the page links and the SQL.
 *
 * Run from the plugin folder:  php tools/tests/catalog-place-test.php
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/bootstrap.php';

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function add_query_arg( $args, $uri ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => func_get_arg( 1 ) );
		$uri  = func_get_arg( 2 );
	}
	$pairs = array();
	foreach ( $args as $k => $v ) {
		$pairs[] = $k . '=' . $v;
	}
	return $uri . ( $pairs ? '&' . implode( '&', $pairs ) : '' );
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
function wp_kses_post( $s ) {
	return $s;
}
function wpautop( $s ) {
	return '<p>' . $s . '</p>';
}
function wp_list_pluck( $list, $field ) {
	return array_map(
		function ( $o ) use ( $field ) {
			return $o->$field;
		},
		$list
	);
}
function current_time( $f ) {
	return gmdate( $f );
}

class CLIB_Settings {
	public static function get( $k ) {
		return 'per_page' === $k ? 25 : null;
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
	public static function log_error() {}
}
class CLIB_LOV {
	public static function label( $id ) {
		$map = array(
			3 => 'St Mark Church',
			9 => 'SECRET-BUILDING',
		);
		return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : '';
	}
	public static function all() {
		return array();
	}
	public static function count() {
		return 0;
	}
}
class CLIB_Loans {
	public static function active_for_item() {
		return array();
	}
}
class CLIB_Perms {
	public static function can_see_borrowers() {
		return false;
	}
}
class CLIB_Pages {
	public static function url() {
		return 'https://church.example/x';
	}
}
class CLIB_Attribution {
	public static function image_credit() {
		return '';
	}
}

/** One item row, as SELECT i.*, ... would return it. */
function clib_test_row() {
	return (object) array(
		'id'                => 1,
		'item_code'         => 'BOOK-000001',
		'title'             => 'Hymns Ancient',
		'creator'           => 'A. Author',
		'publisher'         => '',
		'description'       => '',
		'media_type_id'     => 0,
		'subcategory_id'    => 0,
		'difficulty'        => '',
		'classification_id' => 0,
		'liturgical_use'    => '',
		'identifier'        => '',
		'call_number'       => '',
		'item_condition'    => '',
		'church_id'         => 3,
		'building_id'       => 9,
		'room'              => 'SECRET-ROOM',
		'location_code'     => 'SHELF-7',
		'owned_by'          => '',
		'copies'            => 1,
		'barcode'           => '',
		'image_id'          => 0,
		'storage_status'    => 'checked_in',
		'media_type_label'  => 'Book',
		'church_label'      => 'St Mark Church',
		'building_label'    => 'SECRET-BUILDING',
	);
}

$GLOBALS['wpdb'] = new class() extends CLIB_Test_WPDB {
	public function get_results( $q ) {
		return array( clib_test_row() );
	}
	public function get_row( $q ) {
		return clib_test_row();
	}
};

function clib_render( array $get ) {
	$_GET                            = $get;
	$GLOBALS['wpdb']->total          = 1;
	$GLOBALS['wpdb']->prepared       = array();
	$GLOBALS['clib_test_transients'] = array();
	ob_start();
	include dirname( __DIR__, 2 ) . '/public/views/catalog.php';
	return ob_get_clean();
}
function clib_last_sql() {
	$all = $GLOBALS['wpdb']->prepared;
	return $all ? end( $all ) : array( '', array() );
}

$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

/* ---------------------------------------------------------------- Guest */
$GLOBALS['clib_test_user'] = 0;
$GLOBALS['clib_test_caps'] = array();

$html = clib_render( array() );
clib_check( false === strpos( $html, 'name="clib_building"' ), 'guest: no Building filter in the form' );
clib_check( false === strpos( $html, 'SECRET-BUILDING' ), 'guest list: building not in output' );
clib_check( false === strpos( $html, 'SECRET-ROOM' ), 'guest list: room not in output' );
clib_check( false !== strpos( $html, 'Hymns Ancient' ), 'guest list: item still listed' );

$html = clib_render(
	array(
		'clib_building' => '9',
		'clib_q'        => 'hymn',
	)
);
$sql  = clib_last_sql();
clib_check( false === strpos( $sql[0], 'i.building_id = %d' ), 'guest: clib_building parameter is ignored in the query' );
clib_check( ! in_array( 9, $sql[1], true ), 'guest: building id is not bound as a value' );
clib_check( false === strpos( $html, 'clib_building' ), 'guest: clib_building is not echoed into links or fields' );
foreach ( array( 'room', 'building' ) as $col ) {
	clib_check( false === stripos( $sql[0], "i.$col LIKE" ), "guest: search text never matches $col" );
}

$html = clib_render( array( 'clib_item' => 'BOOK-000001' ) );
clib_check( false !== strpos( $html, 'Hymns Ancient' ), 'guest detail: item shown' );
clib_check( false !== strpos( $html, 'St Mark Church' ), 'guest detail: church still shown' );
clib_check( false !== strpos( $html, 'SHELF-7' ), 'guest detail: location ID still shown' );
clib_check( false === strpos( $html, '>Building<' ), 'guest detail: no Building row' );
clib_check( false === strpos( $html, '>Room<' ), 'guest detail: no Room row' );
clib_check( false === strpos( $html, 'SECRET-BUILDING' ), 'guest detail: building value not in output' );
clib_check( false === strpos( $html, 'SECRET-ROOM' ), 'guest detail: room value not in output' );

/* ---------------------------------------------------------------- Logged in (subscriber, no staff caps) */
$GLOBALS['clib_test_user'] = 5;
$GLOBALS['clib_test_caps'] = array();

$html = clib_render( array( 'clib_building' => '9' ) );
$sql  = clib_last_sql();
clib_check( false !== strpos( $html, 'name="clib_building"' ), 'logged in: Building filter shown' );
clib_check( false !== strpos( $sql[0], 'i.building_id = %d' ) && in_array( 9, $sql[1], true ), 'logged in: Building filter applied' );

$html = clib_render( array( 'clib_item' => 'BOOK-000001' ) );
clib_check( false !== strpos( $html, '>Building<' ) && false !== strpos( $html, 'SECRET-BUILDING' ), 'logged in detail: Building shown' );
clib_check( false !== strpos( $html, '>Room<' ) && false !== strpos( $html, 'SECRET-ROOM' ), 'logged in detail: Room shown' );
$p_church = strpos( $html, '>Church<' );
$p_bldg   = strpos( $html, '>Building<' );
$p_room   = strpos( $html, '>Room<' );
$p_loc    = strpos( $html, '>Location ID<' );
clib_check( $p_church < $p_bldg && $p_bldg < $p_room && $p_room < $p_loc, 'logged in detail: field order Church, Building, Room, Location ID' );

clib_done();
