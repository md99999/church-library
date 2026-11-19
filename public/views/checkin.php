<?php
/**
 * Library - Check In.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_prefill = CLIB_Util::token( CLIB_Util::get( 'item' ), 64 );
$clib_item    = '' !== $clib_prefill ? CLIB_Items::find_by_code( $clib_prefill ) : null;
$clib_loans   = $clib_item ? CLIB_Loans::active_for_item( $clib_item->id ) : array();
$clib_today   = current_time( 'Y-m-d' );
?>
<p><?php esc_html_e( 'Scan or type the Item ID or barcode of the returned item. If several copies are out, pick the right borrower from the list.', 'church-library' ); ?></p>

<?php echo CLIB_Public::form_open( 'checkin', 'class="clib-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<input type="hidden" name="return" value="checkin">
	<p>
		<label><?php esc_html_e( 'Item ID or barcode', 'church-library' ); ?> <span class="required">*</span><br>
			<input type="text" name="item_code" required maxlength="64" value="<?php echo esc_attr( $clib_item ? $clib_item->item_code : $clib_prefill ); ?>" autofocus></label>
	</p>
	<p>
		<label><?php esc_html_e( 'Condition on return', 'church-library' ); ?><br>
			<select name="return_condition"><?php echo CLIB_Form::map_options( CLIB_Util::condition_labels( true ), '', __( '(unchanged)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
	</p>
	<p>
		<label><?php esc_html_e( 'Notes', 'church-library' ); ?><br>
			<input type="text" name="return_notes" maxlength="500"></label>
	</p>
	<p><button type="submit" class="clib-btn"><?php esc_html_e( 'Check In', 'church-library' ); ?></button></p>
</form>

<?php
// Either the copies of the chosen item, or everything currently out.
$clib_list = $clib_item ? array_map(
	function ( $l ) use ( $clib_item ) {
		$l->item_code = $clib_item->item_code;
		$l->title     = $clib_item->title;
		return $l;
	},
	$clib_loans
) : CLIB_Loans::active( false );
?>
<h3>
	<?php
	echo $clib_item
		/* translators: %s: item title */
		? esc_html( sprintf( __( 'Copies of "%s" checked out', 'church-library' ), $clib_item->title ) )
		: esc_html__( 'Everything checked out now', 'church-library' );
	?>
</h3>
<?php if ( ! $clib_list ) : ?>
	<p><?php esc_html_e( 'Nothing is checked out.', 'church-library' ); ?></p>
<?php else : ?>
	<table class="clib-table">
		<thead><tr>
			<th><?php esc_html_e( 'Item', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Checked Out By', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Due', 'church-library' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $clib_list as $clib_l ) : ?>
			<?php $clib_over = $clib_l->due_date && $clib_l->due_date < $clib_today; ?>
			<tr class="<?php echo $clib_over ? 'clib-overdue' : ''; ?>">
				<td><a href="<?php echo esc_url( CLIB_Pages::url( 'catalog', array( 'clib_item' => $clib_l->item_code ) ) ); ?>"><?php echo esc_html( $clib_l->title ); ?></a><br><small><?php echo esc_html( $clib_l->item_code ); ?></small></td>
				<td><?php echo esc_html( $clib_l->borrower_name ); ?><?php echo $clib_l->borrower_contact ? '<br><small>' . esc_html( $clib_l->borrower_contact ) . '</small>' : ''; ?></td>
				<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_l->due_date ) ); ?><?php echo $clib_over ? ' <strong>' . esc_html__( 'Overdue', 'church-library' ) . '</strong>' : ''; ?></td>
				<td>
					<?php echo CLIB_Public::form_open( 'checkin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<input type="hidden" name="loan_id" value="<?php echo esc_attr( (int) $clib_l->id ); ?>">
						<input type="hidden" name="return" value="checkin">
						<button type="submit" class="clib-btn clib-btn-small"><?php esc_html_e( 'Check In', 'church-library' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
