<?php
/**
 * Library - Add Item / Edit Item.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_id   = CLIB_Util::int( CLIB_Util::get( 'id' ) );
$clib_item = $clib_id ? CLIB_Items::get( $clib_id ) : null;
if ( $clib_id && ! $clib_item ) {
	echo '<h1>' . esc_html__( 'Library - Edit Item', 'church-library' ) . '</h1><p>' . esc_html__( 'Item not found.', 'church-library' ) . '</p>';
	return;
}
$clib_v = $clib_item ? (array) $clib_item : array();

// Refill after a validation error (values were already sanitized server-side).
$clib_retry = get_transient( 'clib_form_' . get_current_user_id() );
if ( is_array( $clib_retry ) ) {
	delete_transient( 'clib_form_' . get_current_user_id() );
	$clib_v = array_merge( $clib_v, $clib_retry );
}
$clib_f = function ( $k, $default = '' ) use ( $clib_v ) {
	return isset( $clib_v[ $k ] ) && null !== $clib_v[ $k ] ? $clib_v[ $k ] : $default;
};

$clib_title = $clib_item ? __( 'Library - Edit Item', 'church-library' ) : __( 'Library - Add Item', 'church-library' );
?>
<h1><?php echo esc_html( $clib_title ); ?><?php echo $clib_item ? ' <code>' . esc_html( $clib_item->item_code ) . '</code>' : ''; ?></h1>
<?php if ( $clib_item && ( current_user_can( 'clib_manage_items' ) || current_user_can( 'clib_delete_items' ) ) ) : ?>
	<p class="clib-jump"><a href="#clib-item-actions"><?php esc_html_e( 'Archive or delete this item', 'church-library' ); ?> &darr;</a></p>
<?php endif; ?>
<?php if ( $clib_item && (int) $clib_item->is_archived ) : ?>
	<div class="notice notice-warning inline"><p><?php esc_html_e( 'This item is archived and hidden from the catalog. Use "Restore to catalog" below to bring it back.', 'church-library' ); ?></p></div>
<?php endif; ?>

<?php
$clib_form_url    = admin_url( 'admin-post.php' );
$clib_form_hidden = wp_nonce_field( 'clib_save_item', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="clib_save_item">';
$clib_cancel_url  = $clib_item ? CLIB_Admin::url( 'clib-catalog', array( 'view' => (int) $clib_item->id ) ) : CLIB_Admin::url( 'clib-catalog' );
require CLIB_DIR . 'includes/views/item-form.php';

// Archive / Delete sit outside the item form (forms cannot be nested).
if ( $clib_item ) {
	$clib_return = 'edit';
	require CLIB_DIR . 'admin/views/partials/item-actions.php';
}
