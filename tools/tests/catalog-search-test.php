<?php
/**
 * Security tests for the public catalog search (Library - Catalog page).
 *
 * Run from the plugin folder:  php tools/tests/catalog-search-test.php
 * Exit code 0 means every check passed.
 *
 * @package ChurchLibrary
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/bootstrap.php';

/* ---------------------------------------------------- Search term sanitising. */

// Hostile strings: each must come back as plain text that is safe to bind and to print.
$hostile = array(
	"' OR '1'='1' -- ",
	'1; DROP TABLE wp_clib_items; --',
	"x' UNION SELECT user_login, user_pass FROM wp_users #",
	"' AND SLEEP(5) AND '1",
	'../../../../wp-config.php',
	'..\\..\\windows\\win.ini',
	'; rm -rf / | cat /etc/passwd `id` $(whoami)',
	'"><script>alert(1)</script>',
	"\"><img src=x onerror=alert('x')>",
	'javascript:alert(document.cookie)',
	"null\0byte\x01\x1F\x7Fend",
	"%27%20OR%201%3D1%20--",
	'{{7*7}} ${7*7} <?php system("id"); ?>',
);
foreach ( $hostile as $i => $raw ) {
	$t = CLIB_Util::search_term( $raw );
	clib_check( is_string( $t ), "hostile #$i returns a string" );
	clib_check( mb_strlen( $t, 'UTF-8' ) <= CLIB_Util::SEARCH_MAX, "hostile #$i length capped" );
	clib_check( 0 === preg_match( '/[\x00-\x1F\x7F]/', $t ), "hostile #$i has no control characters" );
	clib_check( false === strpos( $t, '<' ) || false !== strpos( $t, '&lt;' ), "hostile #$i has no raw tag opener" );
	$attr = esc_attr( $t );
	clib_check( false === strpos( $attr, '"' ) && false === strpos( $attr, '<' ) && false === strpos( $attr, '>' ), "hostile #$i is inert inside value=\"...\"" );
}
clib_check( '' === CLIB_Util::search_term( '<script>alert(1)</script>' ), 'script element removed with its body' );
clib_check( 'nullbyte\\end' !== CLIB_Util::search_term( "null\0byte\x01end" ) && 'nullbyteend' === CLIB_Util::search_term( "null\0byte\x01end" ), 'null byte and control characters stripped' );
clib_check( false === strpos( CLIB_Util::search_term( '%27%20OR%201%3D1' ), '%27' ), 'percent-encoded octets stripped' );
clib_check( 'Mere Christianity' === CLIB_Util::search_term( "  Mere \t\n Christianity  " ), 'whitespace collapsed and trimmed' );
clib_check( "C. S. Lewis's" === CLIB_Util::search_term( "C. S. Lewis\\'s" ), 'magic-quote slashes removed (wp_unslash)' );
clib_check( '50% off_sale' === CLIB_Util::search_term( '50% off_sale' ), 'ordinary % and _ kept as text (escaped later by esc_like)' );

// Wrong types (clib_q[]=x, objects, null) never reach the query.
clib_check( '' === CLIB_Util::search_term( array( 'x' ) ), 'array parameter rejected' );
clib_check( '' === CLIB_Util::search_term( array( 'a' => array( 'b' ) ) ), 'nested array rejected' );
clib_check( '' === CLIB_Util::search_term( null ), 'null rejected' );
clib_check( '' === CLIB_Util::search_term( new stdClass() ), 'object rejected' );
clib_check( '' === CLIB_Util::search_term( 12345 ) || '12345' === CLIB_Util::search_term( 12345 ), 'integer handled' );

// Oversized input is cut before the regular expressions run, quickly.
$start = microtime( true );
$big   = CLIB_Util::search_term( str_repeat( 'A<b>%41', 300000 ) ); // ~2 MB.
clib_check( mb_strlen( $big, 'UTF-8' ) <= CLIB_Util::SEARCH_MAX, '2 MB input capped' );
clib_check( microtime( true ) - $start < 0.5, '2 MB input handled in under 0.5 s' );

// Multibyte: the byte pre-cut never leaves broken UTF-8.
$mb = CLIB_Util::search_term( str_repeat( 'é', 2000 ) );
clib_check( 1 === preg_match( '//u', $mb ), 'multibyte result is valid UTF-8' );
clib_check( CLIB_Util::SEARCH_MAX === mb_strlen( $mb, 'UTF-8' ), 'multibyte capped at SEARCH_MAX characters' );
$emoji = CLIB_Util::search_term( str_repeat( 'a', 799 ) . "\xF0\x9F\x93\x96" ); // 4-byte char split by the 800-byte cut.
clib_check( 1 === preg_match( '//u', $emoji ), 'split 4-byte character removed, result valid UTF-8' );
clib_check( '' === CLIB_Util::search_term( "\xC3\x28\xA0\xA1" ) || 1 === preg_match( '//u', CLIB_Util::search_term( "\xC3\x28\xA0\xA1" ) ), 'invalid UTF-8 never passes through' );

/* ------------------------------------------------------------ Filters. */
clib_check( 0 === CLIB_Util::int( '1 OR 1=1', 0, 4294967295 ), 'id filter: SQL text gives 0' );
clib_check( 0 === CLIB_Util::int( '-5', 0, 4294967295 ), 'id filter: negative gives 0' );
clib_check( 0 === CLIB_Util::int( array( 1 ), 0, 4294967295 ), 'id filter: array gives 0' );
clib_check( 0 === CLIB_Util::int( '0x1A', 0, 4294967295 ), 'id filter: hex gives 0' );
clib_check( 4294967295 === CLIB_Util::int( '99999999999999999999', 0, 4294967295 ), 'id filter: huge value clamped' );
clib_check( 7 === CLIB_Util::int( '7', 0, 4294967295 ), 'id filter: normal value kept' );
clib_check( 10000 === CLIB_Util::int( '999999999', 1, 10000 ), 'page clamped to 10000' );
clib_check( 1 === CLIB_Util::int( 'abc', 1, 10000 ), 'page: text gives 1' );

$sorts = array( 'title', 'creator', 'item_code', 'updated_at' );
clib_check( 'title' === CLIB_Util::enum( 'title; DROP TABLE x', $sorts, 'title' ), 'sort: injection falls back to default' );
clib_check( 'title' === CLIB_Util::enum( '(SELECT 1)', $sorts, 'title' ), 'sort: subquery falls back to default' );
clib_check( 'title' === CLIB_Util::enum( array( 'creator' ), $sorts, 'title' ), 'sort: array falls back to default' );
clib_check( 'updated_at' === CLIB_Util::enum( 'updated_at', $sorts, 'title' ), 'sort: whitelisted value kept' );
clib_check( '' === CLIB_Util::enum( "available' OR 1=1", CLIB_Util::STATUSES, '' ), 'status: injection rejected' );
clib_check( 'checked_out' === CLIB_Util::enum( 'checked_out', CLIB_Util::STATUSES, '' ), 'status: whitelisted value kept' );

/* ------------------------------------------------- SQL built by search(). */

/** Run search() and return the prepared [template, values] of the row query. */
function clib_run_search( array $args ) {
	global $wpdb;
	$wpdb->prepared = array();
	$wpdb->raw      = array();
	CLIB_Items::search( $args );
	return end( $wpdb->prepared );
}

foreach ( $hostile as $i => $raw ) {
	$q                 = CLIB_Util::search_term( $raw );
	list( $sql, $vals ) = clib_run_search(
		array(
			'q'        => $q,
			'per_page' => 25,
		)
	);
	if ( '' !== $q ) {
		clib_check( false === strpos( $sql, $q ), "hostile #$i never appears in the SQL text" );
		clib_check( in_array( '%' . $GLOBALS['wpdb']->esc_like( $q ) . '%', $vals, true ), "hostile #$i is bound as an escaped LIKE value" );
	}
	clib_check( substr_count( $sql, '%s' ) + substr_count( $sql, '%d' ) === count( $vals ), "hostile #$i placeholder count matches values" );
}

list( $sql, $vals ) = clib_run_search( array( 'q' => '50%_off\\' ) );
clib_check( in_array( '%50\\%\\_off\\\\%', $vals, true ), 'LIKE wildcards in the term are escaped' );

list( $sql, $vals ) = clib_run_search(
	array(
		'q'              => 'BC-0042',
		'search_barcode' => false,
	)
);
clib_check( false === strpos( $sql, 'barcode' ), 'guest search does not match barcodes' );
clib_check( 5 + 2 === count( $vals ), 'guest search binds five LIKE values plus LIMIT/OFFSET' );

list( $sql, $vals ) = clib_run_search( array( 'q' => 'BC-0042' ) );
clib_check( false !== strpos( $sql, 'i.barcode LIKE %s' ), 'staff search (default) matches barcodes' );

list( $sql ) = clib_run_search(
	array(
		'orderby' => 'title DESC, (SELECT SLEEP(5))',
		'order'   => 'asc; DROP TABLE x',
	)
);
clib_check( false !== strpos( $sql, 'ORDER BY i.title ASC' ), 'ORDER BY injection falls back to whitelisted column and direction' );
clib_check( false === stripos( $sql, 'SLEEP' ) && false === stripos( $sql, 'DROP' ), 'ORDER BY text never reaches SQL' );

list( $sql, $vals ) = clib_run_search(
	array(
		'per_page' => 0,
		'page'     => -3,
	)
);
clib_check( 1 === $vals[ count( $vals ) - 2 ] && 0 === $vals[ count( $vals ) - 1 ], 'per_page 0 and negative page clamp to LIMIT 1 OFFSET 0' );

list( $sql, $vals ) = clib_run_search(
	array(
		'q'             => array( 'x' ),
		'media_type_id' => '3 OR 1=1',
		'status'        => array( 'available' ),
	)
);
clib_check( false === strpos( $sql, 'LIKE' ), 'array q ignored by search()' );
clib_check( array( 3, 25, 0 ) === $vals, 'string id cast to int and bound with %d' );
clib_check( false === strpos( $sql, 'storage_status' ), 'array status ignored' );

clib_check( array() === $GLOBALS['wpdb']->raw, 'no unprepared queries were run with filters present' );
list( $sql ) = clib_run_search( array( 'archived' => 0 ) );
clib_check( false !== strpos( $sql, 'i.is_archived = 0' ), 'archived items stay hidden by default' );

/* ------------------------------------------------------------ Rate limit. */
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$GLOBALS['clib_test_transients'] = array();
$over = array();
for ( $n = 0; $n < 5; $n++ ) {
	$over[] = CLIB_Util::rate_limited( 'catalog_search', 3, 60 );
}
clib_check( array( false, false, false, true, true ) === $over, 'rate limit: 4th and later hits refused' );
$stored = wp_json_encode_safe( $GLOBALS['clib_test_transients'] );
clib_check( false === strpos( $stored, '203.0.113.7' ), 'rate limit: raw IP never stored' );

$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
clib_check( false === CLIB_Util::rate_limited( 'catalog_search', 3, 60 ), 'rate limit: another IP has its own bucket' );

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
foreach ( $GLOBALS['clib_test_transients'] as $k => $t ) {
	if ( is_array( $t['v'] ) && 3 === $t['v'][1] ) {
		$GLOBALS['clib_test_transients'][ $k ]['v'][0] = time() - 61; // Window has passed.
	}
}
clib_check( false === CLIB_Util::rate_limited( 'catalog_search', 3, 60 ), 'rate limit: resets after the window' );

$_SERVER['REMOTE_ADDR'] = "1.2.3.4\r\nX-Evil: 1";
clib_check( false === CLIB_Util::rate_limited( 'catalog_search', 3, 60 ), 'rate limit: invalid REMOTE_ADDR does not crash' );

$GLOBALS['clib_test_transients'] = array();
$any                             = false;
for ( $n = 0; $n < 50; $n++ ) {
	$any = $any || CLIB_Util::rate_limited( 'catalog_search', 0, 60 );
}
clib_check( false === $any && array() === $GLOBALS['clib_test_transients'], 'rate limit 0 disables it and writes nothing' );

/** JSON without depending on WordPress. */
function wp_json_encode_safe( $v ) {
	return (string) json_encode( $v );
}

clib_done();
