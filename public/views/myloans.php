<?php
/**
 * Library - My Loans: items checked out to the logged-in user's account.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_loans = CLIB_Loans::for_user( get_current_user_id() );
$clib_today = current_time( 'Y-m-d' );
?>
<p><?php esc_html_e( 'Items the library has checked out to your account. To return an item, bring it to a library team member.', 'church-library' ); ?></p>
<?php if ( ! $clib_loans ) : ?>
	<p><?php esc_html_e( 'You have no loans on record.', 'church-library' ); ?></p>
<?php else : ?>
	<table class="clib-table">
		<thead><tr>
			<th><?php esc_html_e( 'Item', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Checked Out', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Due', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Returned', 'church-library' ); ?></th>
		</tr></thead>
		<tbody>
		<?php foreach ( $clib_loans as $clib_l ) : ?>
			<?php $clib_over = ! $clib_l->returned_at && $clib_l->due_date && $clib_l->due_date < $clib_today; ?>
			<tr class="<?php echo $clib_over ? 'clib-overdue' : ''; ?>">
				<td><a href="<?php echo esc_url( CLIB_Pages::url( 'catalog', array( 'clib_item' => $clib_l->item_code ) ) ); ?>"><?php echo esc_html( $clib_l->title ); ?></a></td>
				<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_l->checked_out_at ) ); ?></td>
				<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_l->due_date ) ); ?><?php echo $clib_over ? ' <strong>' . esc_html__( 'Overdue', 'church-library' ) . '</strong>' : ''; ?></td>
				<td><?php echo esc_html( $clib_l->returned_at ? CLIB_Util::fmt_date( $clib_l->returned_at ) : __( 'Still out', 'church-library' ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
