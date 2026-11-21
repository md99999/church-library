<?php
/**
 * Archive and Delete buttons on the front-end Edit item page.
 *
 * Each form carries the per-action nonce (checked in CLIB_Public::handle) and a
 * second nonce bound to this item id (checked in the action handler). The
 * capability is checked again on the server; Delete also needs the
 * confirmation box, which the server checks too.
 *
 * Expects: $clib_item (object, not archived).
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
$clib_iid = (int) $clib_item->id;
?>
<div class="clib-item-actions" id="clib-item-actions">
	<h3><?php esc_html_e( 'Archive or delete', 'church-library' ); ?></h3>
	<?php if ( $clib_can_archive ) : ?>
		<?php echo CLIB_Public::form_open( 'archive_item', 'class="clib-action-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $clib_iid ); ?>">
			<input type="hidden" name="_clib_item_nonce" value="<?php echo esc_attr( wp_create_nonce( 'clib_front_archive_item_' . $clib_iid ) ); ?>">
			<p><button type="submit" class="clib-btn"><?php esc_html_e( 'Archive item', 'church-library' ); ?></button>
			<span class="clib-muted"><?php esc_html_e( 'Hides the item from the catalog, reports, lookups and check out. Its record and loan history are kept, and an administrator can restore it from the dashboard (Library > Catalog > Archived items).', 'church-library' ); ?></span></p>
		</form>
	<?php endif; ?>
	<?php if ( $clib_can_delete ) : ?>
		<?php echo CLIB_Public::form_open( 'delete_item', 'class="clib-action-form clib-danger"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( $clib_iid ); ?>">
			<input type="hidden" name="_clib_item_nonce" value="<?php echo esc_attr( wp_create_nonce( 'clib_front_delete_item_' . $clib_iid ) ); ?>">
			<p><label><input type="checkbox" name="confirm_delete" value="1" required> <?php esc_html_e( 'I understand this permanently deletes the item and its loan history from the database. This cannot be undone.', 'church-library' ); ?></label></p>
			<p><button type="submit" class="clib-btn clib-btn-danger"><?php esc_html_e( 'Delete item permanently', 'church-library' ); ?></button></p>
		</form>
	<?php endif; ?>
</div>
