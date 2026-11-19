<?php
/**
 * Library - Check Out.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_prefill = CLIB_Util::token( CLIB_Util::get( 'item' ), 64 );
$clib_item    = '' !== $clib_prefill ? CLIB_Items::find_by_code( $clib_prefill ) : null;
?>
<p><?php esc_html_e( 'Scan or type the Item ID (for example BOOK-000123) or the barcode, then enter who is borrowing it.', 'church-library' ); ?></p>

<?php if ( $clib_item ) : ?>
	<?php $clib_avail = max( 0, (int) $clib_item->copies - CLIB_Loans::active_count( $clib_item->id ) ); ?>
	<div class="clib-panel">
		<strong><?php echo esc_html( $clib_item->title ); ?></strong>
		<?php echo $clib_item->creator ? ' &mdash; ' . esc_html( $clib_item->creator ) : ''; ?><br>
		<span class="clib-meta">
			<?php
			/* translators: 1: item id 2: available 3: total */
			echo esc_html( sprintf( __( '%1$s · %2$d of %3$d copies available', 'church-library' ), $clib_item->item_code, $clib_avail, (int) $clib_item->copies ) );
			?>
		</span>
	</div>
<?php endif; ?>

<?php echo CLIB_Public::form_open( 'checkout', 'class="clib-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?>
	<input type="hidden" name="return" value="<?php echo $clib_item ? 'item' : 'checkout'; ?>">
	<p>
		<label><?php esc_html_e( 'Item ID or barcode', 'church-library' ); ?> <span class="required">*</span><br>
			<input type="text" name="item_code" required maxlength="64" value="<?php echo esc_attr( $clib_item ? $clib_item->item_code : $clib_prefill ); ?>" <?php echo $clib_item ? '' : 'autofocus'; ?>></label>
	</p>
	<?php require CLIB_DIR . 'admin/views/partials/checkout-fields.php'; ?>
	<p><button type="submit" class="clib-btn"><?php esc_html_e( 'Check Out', 'church-library' ); ?></button></p>
</form>
