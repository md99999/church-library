<?php
/**
 * Archive / Restore and Delete buttons for one item (item view and edit pages).
 *
 * Each action is its own POST form with a nonce bound to the action and the
 * item id; the handlers check the nonce and capability again. Delete also needs
 * a ticked confirmation box, which the server checks (not only the browser).
 *
 * Expects: $clib_item (object), $clib_return ('view' or 'edit').
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_can_archive = current_user_can( 'clib_manage_items' );
$clib_can_delete  = current_user_can( 'clib_delete_items' );
if ( ! $clib_can_archive && ! $clib_can_delete ) {
	return;
}
$clib_iid      = (int) $clib_item->id;
$clib_archived = (int) $clib_item->is_archived;
$clib_ret      = 'edit' === $clib_return ? 'edit' : 'view';
?>
<div class="clib-item-actions" id="clib-item-actions">
	<h2><?php esc_html_e( 'Archive or delete', 'church-library' ); ?></h2>
	<?php if ( $clib_can_archive ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-action-form">
			<?php if ( $clib_archived ) : ?>
				<?php wp_nonce_field( 'clib_restore_item_' . $clib_iid ); ?>
				<input type="hidden" name="action" value="clib_restore_item">
				<input type="hidden" name="id" value="<?php echo esc_attr( $clib_iid ); ?>">
				<input type="hidden" name="return" value="<?php echo esc_attr( $clib_ret ); ?>">
				<button class="button"><?php esc_html_e( 'Restore to catalog', 'church-library' ); ?></button>
				<span class="description"><?php esc_html_e( 'Shows the item in the catalog, reports and check out again.', 'church-library' ); ?></span>
			<?php else : ?>
				<?php wp_nonce_field( 'clib_archive_item_' . $clib_iid ); ?>
				<input type="hidden" name="action" value="clib_archive_item">
				<input type="hidden" name="id" value="<?php echo esc_attr( $clib_iid ); ?>">
				<input type="hidden" name="return" value="<?php echo esc_attr( $clib_ret ); ?>">
				<button class="button"><?php esc_html_e( 'Archive item', 'church-library' ); ?></button>
				<span class="description"><?php esc_html_e( 'Marks the item inactive and hides it from the catalog, reports, lookups and check out. Its record and loan history are kept, and it can be restored from Catalog > Archived items.', 'church-library' ); ?></span>
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if ( $clib_can_delete ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-action-form clib-danger" data-clib-confirm="1">
			<?php wp_nonce_field( 'clib_delete_item_' . $clib_iid ); ?>
			<input type="hidden" name="action" value="clib_delete_item">
			<input type="hidden" name="id" value="<?php echo esc_attr( $clib_iid ); ?>">
			<input type="hidden" name="return" value="<?php echo esc_attr( $clib_ret ); ?>">
			<p><label><input type="checkbox" name="confirm_delete" value="1" required> <?php esc_html_e( 'I understand this permanently deletes the item and its loan history from the database. This cannot be undone.', 'church-library' ); ?></label></p>
			<p><button class="button button-link-delete"><?php esc_html_e( 'Delete item permanently', 'church-library' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
