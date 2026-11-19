<?php
/**
 * Library - Check Out / In desk.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_loans = CLIB_Loans::active( false );
$clib_today = current_time( 'Y-m-d' );
?>
<h1><?php esc_html_e( 'Library - Check Out / In', 'church-library' ); ?></h1>

<div class="clib-desk">
	<div class="clib-section">
		<h2><?php esc_html_e( 'Check out', 'church-library' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'clib_checkout' ); ?>
			<input type="hidden" name="action" value="clib_checkout">
			<input type="hidden" name="return" value="circulation">
			<p>
				<label><?php esc_html_e( 'Item ID or barcode', 'church-library' ); ?> <span class="required">*</span><br>
					<input type="text" name="item_code" required maxlength="64" class="regular-text" autofocus></label>
			</p>
			<?php require CLIB_DIR . 'admin/views/partials/checkout-fields.php'; ?>
			<button class="button button-primary"><?php esc_html_e( 'Check Out', 'church-library' ); ?></button>
		</form>
	</div>

	<div class="clib-section">
		<h2><?php esc_html_e( 'Check in', 'church-library' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'clib_checkin' ); ?>
			<input type="hidden" name="action" value="clib_checkin">
			<input type="hidden" name="return" value="circulation">
			<p>
				<label><?php esc_html_e( 'Item ID or barcode', 'church-library' ); ?> <span class="required">*</span><br>
					<input type="text" name="item_code" required maxlength="64" class="regular-text"></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Condition on return', 'church-library' ); ?><br>
					<select name="return_condition"><?php echo CLIB_Admin::map_options( CLIB_Util::condition_labels( true ), '', __( '(unchanged)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
			</p>
			<p>
				<label><?php esc_html_e( 'Notes', 'church-library' ); ?><br>
					<input type="text" name="return_notes" maxlength="500" class="regular-text"></label>
			</p>
			<button class="button button-primary"><?php esc_html_e( 'Check In', 'church-library' ); ?></button>
		</form>
	</div>
</div>

<h2><?php esc_html_e( 'Currently checked out', 'church-library' ); ?></h2>
<table class="widefat striped">
	<thead><tr>
		<th><?php esc_html_e( 'Item ID', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Title', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Checked Out By', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Contact', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Due', 'church-library' ); ?></th>
		<th></th>
	</tr></thead>
	<tbody>
	<?php if ( ! $clib_loans ) : ?>
		<tr><td colspan="6"><?php esc_html_e( 'Nothing is checked out.', 'church-library' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $clib_loans as $clib_l ) : ?>
		<?php $clib_over = $clib_l->due_date && $clib_l->due_date < $clib_today; ?>
		<tr class="<?php echo $clib_over ? 'clib-overdue' : ''; ?>">
			<td><code><?php echo esc_html( $clib_l->item_code ); ?></code></td>
			<td><a href="<?php echo esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => (int) $clib_l->item_id ) ) ); ?>"><?php echo esc_html( $clib_l->title ); ?></a></td>
			<td><?php echo esc_html( $clib_l->borrower_name ); ?></td>
			<td><?php echo esc_html( trim( $clib_l->borrower_email . ' ' . $clib_l->borrower_contact ) ); ?></td>
			<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_l->due_date ) ); ?><?php echo $clib_over ? ' <strong>' . esc_html__( 'Overdue', 'church-library' ) . '</strong>' : ''; ?></td>
			<td>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'clib_checkin' ); ?>
					<input type="hidden" name="action" value="clib_checkin">
					<input type="hidden" name="loan_id" value="<?php echo esc_attr( (int) $clib_l->id ); ?>">
					<input type="hidden" name="return" value="circulation">
					<button class="button button-small"><?php esc_html_e( 'Check In', 'church-library' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
