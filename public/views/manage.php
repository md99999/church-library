<?php
/**
 * Library - Add or Edit Item.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_id   = CLIB_Util::int( CLIB_Util::get( 'id' ) );
$clib_item = $clib_id ? CLIB_Items::get( $clib_id ) : null;
if ( $clib_id && ( ! $clib_item || (int) $clib_item->is_archived ) ) { // Archived items are edited (or restored) in the dashboard.
	echo '<p>' . esc_html__( 'Item not found.', 'church-library' ) . '</p>';
	return;
}
$clib_v     = $clib_item ? (array) $clib_item : array();
$clib_retry = get_transient( 'clib_form_' . get_current_user_id() );
if ( is_array( $clib_retry ) ) {
	delete_transient( 'clib_form_' . get_current_user_id() );
	$clib_v = array_merge( $clib_v, $clib_retry );
}
$clib_f = function ( $k, $default = '' ) use ( $clib_v ) {
	return isset( $clib_v[ $k ] ) && null !== $clib_v[ $k ] ? $clib_v[ $k ] : $default;
};

echo '<h2>' . ( $clib_item
	? esc_html__( 'Edit item', 'church-library' ) . ' <code>' . esc_html( $clib_item->item_code ) . '</code>'
	: esc_html__( 'Add an item', 'church-library' ) ) . '</h2>';
if ( $clib_item && ( current_user_can( 'clib_manage_items' ) || current_user_can( 'clib_delete_items' ) ) ) {
	echo '<p class="clib-jump"><a href="#clib-item-actions">' . esc_html__( 'Archive or delete this item', 'church-library' ) . ' &darr;</a></p>';
}
if ( ! $clib_item ) {
	echo '<p>' . esc_html__( 'Type the title and press "Look up" to fill in details automatically, or enter them yourself. The Item ID is created when you save.', 'church-library' ) . '</p>';
}

$clib_form_url    = get_permalink();
$clib_form_hidden = '<input type="hidden" name="clib_front_action" value="save_item">' . wp_nonce_field( 'clib_front_save_item', '_clib_nonce', false, false );
$clib_cancel_url  = $clib_item ? CLIB_Pages::url( 'catalog', array( 'clib_item' => $clib_item->item_code ) ) : CLIB_Pages::url( 'catalog' );
require CLIB_DIR . 'includes/views/item-form.php';

// Archive / Delete sit outside the item form (forms cannot be nested).
if ( $clib_item ) {
	require CLIB_DIR . 'public/views/partials/item-actions.php';
}
