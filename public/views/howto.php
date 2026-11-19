<?php
/**
 * Library - How To: a plain-language guide for library team members.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_link = function ( $key, $text = '' ) {
	$label = '' !== $text ? $text : CLIB_Pages::PAGES[ $key ][0];
	if ( CLIB_Pages::can_access( $key ) ) {
		return '<a href="' . esc_url( CLIB_Pages::url( $key ) ) . '">' . esc_html( $label ) . '</a>';
	}
	return '<strong>' . esc_html( $label ) . '</strong>';
};
$clib_allowed = array(
	'a'      => array( 'href' => array() ),
	'strong' => array(),
	'em'     => array(),
	'code'   => array(),
);
$clib_p = function ( $html ) use ( $clib_allowed ) {
	echo '<p>' . wp_kses( $html, $clib_allowed ) . '</p>';
};
$clib_li = function ( array $items ) use ( $clib_allowed ) {
	echo '<ol>';
	foreach ( $items as $html ) {
		echo '<li>' . wp_kses( $html, $clib_allowed ) . '</li>';
	}
	echo '</ol>';
};
$clib_days = (int) CLIB_Settings::get( 'loan_days' );
?>
<div class="clib-howto">
	<nav class="clib-toc">
		<strong><?php esc_html_e( 'On this page', 'church-library' ); ?></strong>
		<a href="#clib-find"><?php esc_html_e( 'Finding an item', 'church-library' ); ?></a>
		<a href="#clib-out"><?php esc_html_e( 'Checking an item out', 'church-library' ); ?></a>
		<a href="#clib-in"><?php esc_html_e( 'Checking an item in', 'church-library' ); ?></a>
		<a href="#clib-add"><?php esc_html_e( 'Adding a new item', 'church-library' ); ?></a>
		<a href="#clib-edit"><?php esc_html_e( 'Updating an item', 'church-library' ); ?></a>
		<a href="#clib-shelf"><?php esc_html_e( 'Locations and Item IDs', 'church-library' ); ?></a>
		<a href="#clib-reports"><?php esc_html_e( 'Reports and inventory', 'church-library' ); ?></a>
		<a href="#clib-access"><?php esc_html_e( 'Who can do what', 'church-library' ); ?></a>
	</nav>

	<h2 id="clib-find"><?php esc_html_e( 'Finding an item', 'church-library' ); ?></h2>
	<?php
	$clib_li(
		array(
			/* translators: %s: link to catalog page */
			sprintf( __( 'Open %s.', 'church-library' ), $clib_link( 'catalog' ) ),
			__( 'Type part of the title, the author, the publisher, the ISBN or the Item ID in the search box. You can also narrow the list by media type, church, building, classification or status.', 'church-library' ),
			__( 'Press <strong>Search</strong>. Press <strong>Clear</strong> to start over.', 'church-library' ),
			__( 'Click an item to see its details: where it is kept (church, building, room and location ID), its condition, and how many copies are available.', 'church-library' ),
		)
	);
	$clib_p( __( 'The coloured label shows the status: <strong>Checked In</strong> (on the shelf), <strong>Checked Out</strong> (every copy is lent out) or <strong>Not Available</strong> (being repaired, lost or withdrawn).', 'church-library' ) );
	?>

	<h2 id="clib-out"><?php esc_html_e( 'Checking an item out', 'church-library' ); ?></h2>
	<?php
	$clib_li(
		array(
			/* translators: %s: link to check out page */
			sprintf( __( 'Open %s, or find the item in the catalog and press <strong>Check Out</strong> on its page.', 'church-library' ), $clib_link( 'checkout' ) ),
			__( 'Scan the barcode or type the Item ID (for example <code>BOOK-000123</code>).', 'church-library' ),
			__( 'Enter the borrower\'s name. If they have an account on this website, also enter their username or email so the loan shows on their <em>My Loans</em> page.', 'church-library' ),
			__( 'Add a phone number or email in <strong>Contact</strong> so the item can be chased up if it is late.', 'church-library' ),
			/* translators: %d: number of days */
			sprintf( __( 'Check the due date (it defaults to %d days from today) and press <strong>Check Out</strong>.', 'church-library' ), $clib_days ),
		)
	);
	$clib_p( __( 'If an item has several copies it stays <strong>Checked In</strong> until the last copy goes out.', 'church-library' ) );
	?>

	<h2 id="clib-in"><?php esc_html_e( 'Checking an item in', 'church-library' ); ?></h2>
	<?php
	$clib_li(
		array(
			/* translators: %s: link to check in page */
			sprintf( __( 'Open %s.', 'church-library' ), $clib_link( 'checkin' ) ),
			__( 'Scan or type the Item ID. If its condition has changed, choose the new condition, and add a note if anything is damaged or missing.', 'church-library' ),
			__( 'Press <strong>Check In</strong>. If more than one copy is out, the page lists them: press <strong>Check In</strong> next to the right borrower.', 'church-library' ),
			__( 'Put the item back at the location shown on its catalog page.', 'church-library' ),
		)
	);
	$clib_p( __( 'The bottom of the Check In page always lists everything that is out, with overdue items highlighted.', 'church-library' ) );
	?>

	<h2 id="clib-add"><?php esc_html_e( 'Adding a new item', 'church-library' ); ?></h2>
	<?php
	$clib_li(
		array(
			/* translators: %s: link to manage page */
			sprintf( __( 'Open %s.', 'church-library' ), $clib_link( 'manage' ) ),
			__( 'Type the title and press <strong>Look up</strong>, or type the ISBN and press <strong>Look up ISBN</strong>. The lookup first shows anything already in our catalog (so you don\'t add it twice), then matches found online. Press <strong>Use this</strong> on the right one to fill in the title, author, publisher, ISBN, description and cover picture.', 'church-library' ),
			__( 'If nothing matches, type the details in yourself. Only the title and media type are required.', 'church-library' ),
			__( 'Choose the media type. For music, extra boxes appear for the music sub-category (sheet music, choir, handbell...) and the difficulty level.', 'church-library' ),
			__( 'Fill in where it will live: church, building, room and location ID. Enter the number of copies and the condition.', 'church-library' ),
			__( 'Optionally upload a photo (JPG, PNG, GIF or WebP up to 5 MB).', 'church-library' ),
			__( 'Press <strong>Add Item</strong>. The Item ID is created for you. Write it on the item\'s label. Use <strong>Add and start another</strong> when you are entering a pile of items.', 'church-library' ),
		)
	);
	?>

	<h2 id="clib-edit"><?php esc_html_e( 'Updating an item', 'church-library' ); ?></h2>
	<?php
	$clib_p( __( 'Find the item in the catalog and press <strong>Edit</strong>. Change what you need and press <strong>Save Item</strong>. To take an item out of circulation (being repaired, lost, withdrawn), set <strong>Storage Status</strong> to <strong>Not Available</strong>. Deleting items is done by an editor or administrator in the WordPress dashboard.', 'church-library' ) );
	?>

	<h2 id="clib-shelf"><?php esc_html_e( 'Locations and Item IDs', 'church-library' ); ?></h2>
	<?php
	$clib_p( __( 'Every item has an <strong>Item ID</strong> made from its media type and a number, for example <code>BOOK-000123</code> or <code>MUSIC-000007</code>. It never changes, so it is safe to print on labels.', 'church-library' ) );
	$clib_p( __( 'The <strong>Location ID</strong> works like a store aisle tag. We suggest <em>aisle-shelf-position</em>, for example <code>A-12-3</code> means aisle A, shelf 12, position 3. Use the same pattern everywhere so items are easy to find.', 'church-library' ) );
	?>

	<h2 id="clib-reports"><?php esc_html_e( 'Reports and inventory', 'church-library' ); ?></h2>
	<?php
	$clib_p(
		/* translators: %s: link to reports page */
		sprintf( __( '%s shows a summary of the collection, what is checked out, what is overdue, the loan history for a date range, and a shelf list sorted by location for stock-taking. Every report can be downloaded for Excel or printed.', 'church-library' ), $clib_link( 'reports' ) )
	);
	?>

	<h2 id="clib-access"><?php esc_html_e( 'Who can do what', 'church-library' ); ?></h2>
	<?php
	$clib_p( __( 'What you can do depends on your website role. An administrator sets this under <em>Library - Settings</em>. By default: subscribers can browse the catalog; contributors and authors can also add and edit items, check items in and out, and see reports; editors can also delete items, import spreadsheets and manage the List of Values (the dropdown choices such as churches, buildings, rooms and media types); administrators can do everything.', 'church-library' ) );
	$clib_p( __( 'Borrowers\' names are only shown to library team members. Please keep them private.', 'church-library' ) );
	?>
</div>
