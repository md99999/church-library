<?php
/**
 * Library - Item (read-only detail with circulation).
 *
 * @package ChurchLibrary
 * @var int $clib_view_id
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_item = CLIB_Items::get( $clib_view_id );
// Archived items are visible only to people who can restore them.
if ( ! $clib_item || ( (int) $clib_item->is_archived && ! current_user_can( 'clib_manage_items' ) ) ) {
	echo '<h1>' . esc_html__( 'Library - Item', 'church-library' ) . '</h1><p>' . esc_html__( 'Item not found.', 'church-library' ) . '</p>';
	return;
}
$clib_active    = CLIB_Loans::active_for_item( $clib_item->id );
$clib_available = max( 0, (int) $clib_item->copies - count( $clib_active ) );
$clib_staff     = current_user_can( 'clib_circulate' );
$clib_today     = current_time( 'Y-m-d' );
$clib_fields    = array(
	__( 'Item ID', 'church-library' )           => $clib_item->item_code,
	__( 'Creator/Author', 'church-library' )    => $clib_item->creator,
	__( 'Publisher', 'church-library' )         => $clib_item->publisher,
	__( 'Media Type', 'church-library' )        => CLIB_LOV::label( $clib_item->media_type_id ),
	__( 'Music Sub-category', 'church-library' ) => CLIB_LOV::label( $clib_item->subcategory_id ),
	__( 'Difficulty', 'church-library' )        => CLIB_Util::label( CLIB_Util::difficulty_labels(), $clib_item->difficulty ),
	__( 'Classification', 'church-library' )    => CLIB_LOV::label( $clib_item->classification_id ),
	__( 'Liturgical Use', 'church-library' )    => $clib_item->liturgical_use,
	__( 'Condition', 'church-library' )         => CLIB_Util::label( CLIB_Util::condition_labels(), $clib_item->item_condition ),
	__( 'ISBN / Identifier', 'church-library' ) => $clib_item->identifier,
	__( 'Barcode', 'church-library' )           => $clib_item->barcode,
	__( 'Call #', 'church-library' )            => $clib_item->call_number,
	__( 'Owned By', 'church-library' )          => $clib_item->owned_by,
	__( 'Church', 'church-library' )            => CLIB_LOV::label( $clib_item->church_id ),
	__( 'Building', 'church-library' )          => CLIB_LOV::label( $clib_item->building_id ),
	__( 'Room', 'church-library' )              => $clib_item->room,
	__( 'Location ID', 'church-library' )       => $clib_item->location_code,
	/* translators: 1: available 2: total */
	__( 'Copies', 'church-library' )            => sprintf( __( '%1$d of %2$d available', 'church-library' ), $clib_available, (int) $clib_item->copies ),
	__( 'Storage Status', 'church-library' )    => CLIB_Util::label( CLIB_Util::status_labels(), $clib_item->storage_status ),
	__( 'Cost', 'church-library' )              => null !== $clib_item->cost ? number_format_i18n( (float) $clib_item->cost, 2 ) : '',
	__( 'Acquired', 'church-library' )          => CLIB_Util::fmt_date( $clib_item->acquired_date ),
);
if ( CLIB_Perms::can_see_borrowers() && $clib_active ) {
	$clib_fields[ __( 'Checked Out By', 'church-library' ) ] = implode( ', ', wp_list_pluck( $clib_active, 'borrower_name' ) );
}
?>
<h1 class="wp-heading-inline"><?php echo esc_html( 'Library - ' . $clib_item->title ); ?></h1>
<?php if ( current_user_can( 'clib_manage_items' ) ) : ?>
	<a class="page-title-action" href="<?php echo esc_url( CLIB_Admin::url( 'clib-item', array( 'id' => (int) $clib_item->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'church-library' ); ?></a>
<?php endif; ?>
<a class="page-title-action" href="<?php echo esc_url( CLIB_Admin::url( 'clib-catalog' ) ); ?>"><?php esc_html_e( 'Back to catalog', 'church-library' ); ?></a>
<hr class="wp-header-end">
<?php if ( (int) $clib_item->is_archived ) : ?>
	<div class="notice notice-warning inline"><p>
		<?php
		/* translators: %s: date */
		echo esc_html( sprintf( __( 'This item was archived on %s. It is hidden from the catalog, reports, lookups and check out until it is restored.', 'church-library' ), CLIB_Util::fmt_date( $clib_item->archived_at ) ) );
		?>
	</p></div>
<?php endif; ?>

<div class="clib-item-layout">
	<div class="clib-item-main">
		<table class="widefat striped clib-fields">
			<?php foreach ( $clib_fields as $clib_label => $clib_value ) : ?>
				<?php
				if ( '' === (string) $clib_value ) {
					continue;
				}
				?>
				<tr><th scope="row"><?php echo esc_html( $clib_label ); ?></th><td><?php echo esc_html( $clib_value ); ?></td></tr>
			<?php endforeach; ?>
		</table>

		<?php
		foreach ( array(
			__( 'Description', 'church-library' )     => $clib_item->description,
			__( 'Notes', 'church-library' )           => $clib_item->notes,
			__( 'Inventory Notes', 'church-library' ) => $clib_item->inventory_notes,
		) as $clib_label => $clib_text ) :
			if ( '' === (string) $clib_text ) {
				continue;
			}
			?>
			<h2><?php echo esc_html( $clib_label ); ?></h2>
			<div class="clib-text"><?php echo wp_kses_post( wpautop( esc_html( $clib_text ) ) ); ?></div>
		<?php endforeach; ?>

		<?php if ( $clib_staff ) : ?>
			<h2><?php esc_html_e( 'Checked out now', 'church-library' ); ?></h2>
			<?php if ( ! $clib_active ) : ?>
				<p><?php esc_html_e( 'No copies are checked out.', 'church-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Borrower', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Contact', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Checked Out', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Due', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Check In', 'church-library' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $clib_active as $clib_loan ) : ?>
						<tr class="<?php echo ( $clib_loan->due_date && $clib_loan->due_date < $clib_today ) ? 'clib-overdue' : ''; ?>">
							<td><?php echo esc_html( $clib_loan->borrower_name ); ?></td>
							<td><?php echo esc_html( trim( $clib_loan->borrower_email . ' ' . $clib_loan->borrower_contact ) ); ?></td>
							<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_loan->checked_out_at ) ); ?></td>
							<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_loan->due_date ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-inline">
									<?php wp_nonce_field( 'clib_checkin' ); ?>
									<input type="hidden" name="action" value="clib_checkin">
									<input type="hidden" name="loan_id" value="<?php echo esc_attr( (int) $clib_loan->id ); ?>">
									<input type="hidden" name="return" value="view">
									<select name="return_condition"><?php echo CLIB_Admin::map_options( CLIB_Util::condition_labels( true ), '', __( 'Condition (unchanged)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
									<input type="text" name="return_notes" maxlength="500" placeholder="<?php esc_attr_e( 'Notes', 'church-library' ); ?>">
									<button class="button"><?php esc_html_e( 'Check In', 'church-library' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $clib_available > 0 && 'not_available' !== $clib_item->storage_status && ! (int) $clib_item->is_archived ) : ?>
				<h2><?php esc_html_e( 'Check out', 'church-library' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-checkout-form">
					<?php wp_nonce_field( 'clib_checkout' ); ?>
					<input type="hidden" name="action" value="clib_checkout">
					<input type="hidden" name="item_id" value="<?php echo esc_attr( (int) $clib_item->id ); ?>">
					<input type="hidden" name="return" value="view">
					<?php require CLIB_DIR . 'admin/views/partials/checkout-fields.php'; ?>
					<button class="button button-primary"><?php esc_html_e( 'Check Out', 'church-library' ); ?></button>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Loan history', 'church-library' ); ?></h2>
			<?php $clib_hist = CLIB_Loans::history_for_item( $clib_item->id ); ?>
			<?php if ( ! $clib_hist ) : ?>
				<p><?php esc_html_e( 'No loans yet.', 'church-library' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Borrower', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Checked Out', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Due', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Returned', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'church-library' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $clib_hist as $clib_h ) : ?>
						<tr>
							<td><?php echo esc_html( $clib_h->borrower_name ); ?></td>
							<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_h->checked_out_at ) ); ?></td>
							<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_h->due_date ) ); ?></td>
							<td><?php echo esc_html( $clib_h->returned_at ? CLIB_Util::fmt_date( $clib_h->returned_at ) : __( 'Out', 'church-library' ) ); ?></td>
							<td><?php echo esc_html( trim( $clib_h->out_notes . ' ' . $clib_h->return_notes ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<?php
		$clib_return = 'view';
		require CLIB_DIR . 'admin/views/partials/item-actions.php';
		?>
	</div>

	<?php if ( $clib_item->image_id ) : ?>
		<div class="clib-item-side"><?php echo wp_get_attachment_image( (int) $clib_item->image_id, 'medium', false, array( 'alt' => $clib_item->title ) ); ?></div>
	<?php endif; ?>
</div>
