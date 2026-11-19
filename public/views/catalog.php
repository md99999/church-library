<?php
/**
 * Library - Catalog: search, browse and item detail.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_base     = get_permalink();
$clib_statuses = CLIB_Util::status_labels();
$clib_code     = CLIB_Util::token( CLIB_Util::get( 'clib_item' ), 32 );
$clib_staff    = current_user_can( 'clib_circulate' );
$clib_editor   = current_user_can( 'clib_manage_items' );

/* ---------------------------------------------------------------- Item detail */
if ( '' !== $clib_code ) :
	$clib_item = CLIB_Items::find_by_code( $clib_code );
	if ( ! $clib_item || $clib_item->item_code !== $clib_code ) {
		echo '<p>' . esc_html__( 'Item not found.', 'church-library' ) . '</p><p><a href="' . esc_url( $clib_base ) . '">' . esc_html__( 'Back to catalog', 'church-library' ) . '</a></p>';
		return;
	}
	$clib_active = CLIB_Loans::active_for_item( $clib_item->id );
	$clib_avail  = max( 0, (int) $clib_item->copies - count( $clib_active ) );
	$clib_fields = array(
		__( 'Item ID', 'church-library' )           => $clib_item->item_code,
		__( 'Creator/Author', 'church-library' )    => $clib_item->creator,
		__( 'Publisher', 'church-library' )         => $clib_item->publisher,
		__( 'Media Type', 'church-library' )        => CLIB_LOV::label( $clib_item->media_type_id ),
		__( 'Sub-category', 'church-library' )      => CLIB_LOV::label( $clib_item->subcategory_id ),
		__( 'Difficulty', 'church-library' )        => CLIB_Util::label( CLIB_Util::difficulty_labels(), $clib_item->difficulty ),
		__( 'Classification', 'church-library' )    => CLIB_LOV::label( $clib_item->classification_id ),
		__( 'Liturgical Use', 'church-library' )    => $clib_item->liturgical_use,
		__( 'ISBN / Identifier', 'church-library' ) => $clib_item->identifier,
		__( 'Call #', 'church-library' )            => $clib_item->call_number,
		__( 'Condition', 'church-library' )         => CLIB_Util::label( CLIB_Util::condition_labels(), $clib_item->item_condition ),
		__( 'Church', 'church-library' )            => CLIB_LOV::label( $clib_item->church_id ),
		__( 'Building', 'church-library' )          => CLIB_LOV::label( $clib_item->building_id ),
		__( 'Room', 'church-library' )              => $clib_item->room,
		__( 'Location ID', 'church-library' )       => $clib_item->location_code,
		__( 'Owned By', 'church-library' )          => $clib_item->owned_by,
		/* translators: 1: available copies, 2: total copies */
		__( 'Copies', 'church-library' )            => sprintf( __( '%1$d of %2$d available', 'church-library' ), $clib_avail, (int) $clib_item->copies ),
	);
	if ( $clib_staff ) {
		$clib_fields[ __( 'Barcode', 'church-library' ) ] = $clib_item->barcode;
	}
	if ( CLIB_Perms::can_see_borrowers() && $clib_active ) {
		$clib_fields[ __( 'Checked Out By', 'church-library' ) ] = implode( ', ', wp_list_pluck( $clib_active, 'borrower_name' ) );
	}
	?>
	<p><a href="<?php echo esc_url( $clib_base ); ?>">&larr; <?php esc_html_e( 'Back to catalog', 'church-library' ); ?></a></p>
	<div class="clib-item-head">
		<?php if ( $clib_item->image_id ) : ?>
			<div class="clib-item-image"><?php echo wp_get_attachment_image( (int) $clib_item->image_id, 'medium', false, array( 'alt' => $clib_item->title ) ); ?></div>
		<?php endif; ?>
		<div>
			<h2><?php echo esc_html( $clib_item->title ); ?></h2>
			<span class="clib-status clib-status-<?php echo esc_attr( $clib_item->storage_status ); ?>"><?php echo esc_html( CLIB_Util::label( $clib_statuses, $clib_item->storage_status ) ); ?></span>
			<?php if ( $clib_item->description ) : ?>
				<div class="clib-desc"><?php echo wp_kses_post( wpautop( esc_html( $clib_item->description ) ) ); ?></div>
			<?php endif; ?>
			<?php if ( $clib_staff || $clib_editor ) : ?>
				<p class="clib-actions">
					<?php if ( $clib_staff && $clib_avail > 0 && 'not_available' !== $clib_item->storage_status ) : ?>
						<a class="clib-btn" href="<?php echo esc_url( CLIB_Pages::url( 'checkout', array( 'item' => $clib_item->item_code ) ) ); ?>"><?php esc_html_e( 'Check Out', 'church-library' ); ?></a>
					<?php endif; ?>
					<?php if ( $clib_staff && $clib_active ) : ?>
						<a class="clib-btn" href="<?php echo esc_url( CLIB_Pages::url( 'checkin', array( 'item' => $clib_item->item_code ) ) ); ?>"><?php esc_html_e( 'Check In', 'church-library' ); ?></a>
					<?php endif; ?>
					<?php if ( $clib_editor ) : ?>
						<a class="clib-btn clib-btn-alt" href="<?php echo esc_url( CLIB_Pages::url( 'manage', array( 'id' => (int) $clib_item->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'church-library' ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
	</div>
	<table class="clib-fields">
		<?php foreach ( $clib_fields as $clib_label => $clib_value ) : ?>
			<?php
			if ( '' === (string) $clib_value ) {
				continue;
			}
			?>
			<tr><th scope="row"><?php echo esc_html( $clib_label ); ?></th><td><?php echo esc_html( $clib_value ); ?></td></tr>
		<?php endforeach; ?>
	</table>
	<?php if ( $clib_staff && $clib_active ) : ?>
		<h3><?php esc_html_e( 'Checked out now', 'church-library' ); ?></h3>
		<table class="clib-table">
			<thead><tr><th><?php esc_html_e( 'Borrower', 'church-library' ); ?></th><th><?php esc_html_e( 'Contact', 'church-library' ); ?></th><th><?php esc_html_e( 'Due', 'church-library' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $clib_active as $clib_l ) : ?>
				<tr class="<?php echo ( $clib_l->due_date && $clib_l->due_date < current_time( 'Y-m-d' ) ) ? 'clib-overdue' : ''; ?>">
					<td><?php echo esc_html( $clib_l->borrower_name ); ?></td>
					<td><?php echo esc_html( $clib_l->borrower_contact ); ?></td>
					<td><?php echo esc_html( CLIB_Util::fmt_date( $clib_l->due_date ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
	return;
endif;

/* ---------------------------------------------------------------- Search / browse */
$clib_args = array(
	'q'                 => CLIB_Util::text( CLIB_Util::get( 'clib_q' ), 100 ),
	'media_type_id'     => CLIB_Util::int( CLIB_Util::get( 'clib_type' ) ),
	'church_id'         => CLIB_Util::int( CLIB_Util::get( 'clib_church' ) ),
	'building_id'       => CLIB_Util::int( CLIB_Util::get( 'clib_building' ) ),
	'classification_id' => CLIB_Util::int( CLIB_Util::get( 'clib_class' ) ),
	'status'            => CLIB_Util::enum( CLIB_Util::get( 'clib_status' ), CLIB_Util::STATUSES, '' ),
	'orderby'           => CLIB_Util::enum( CLIB_Util::get( 'clib_sort' ), array( 'title', 'creator', 'item_code', 'updated_at' ), 'title' ),
	'order'             => 'updated_at' === CLIB_Util::get( 'clib_sort' ) ? 'desc' : 'asc',
	'page'              => CLIB_Util::int( CLIB_Util::get( 'clib_page', 1 ), 1, 100000 ),
	'per_page'          => (int) CLIB_Settings::get( 'per_page' ),
);
list( $clib_rows, $clib_total ) = CLIB_Items::search( $clib_args );
$clib_pages                     = max( 1, (int) ceil( $clib_total / $clib_args['per_page'] ) );
$clib_page_id                   = CLIB_Util::int( CLIB_Util::get( 'page_id' ) );
?>
<form class="clib-search" method="get" action="<?php echo esc_url( $clib_base ); ?>" role="search">
	<?php if ( $clib_page_id ) : ?>
		<input type="hidden" name="page_id" value="<?php echo esc_attr( $clib_page_id ); ?>">
	<?php endif; ?>
	<input type="search" name="clib_q" maxlength="100" value="<?php echo esc_attr( $clib_args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Search title, author, publisher, ISBN, Item ID...', 'church-library' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'church-library' ); ?>">
	<select name="clib_type" aria-label="<?php esc_attr_e( 'Media type', 'church-library' ); ?>"><?php echo CLIB_Form::lov_options( 'media_type', $clib_args['media_type_id'], __( 'All media types', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="clib_church" class="clib-church" aria-label="<?php esc_attr_e( 'Church', 'church-library' ); ?>"><?php echo CLIB_Form::lov_options( 'church', $clib_args['church_id'], __( 'All churches', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="clib_building" class="clib-building" aria-label="<?php esc_attr_e( 'Building', 'church-library' ); ?>"><?php echo CLIB_Form::lov_options( 'building', $clib_args['building_id'], __( 'All buildings', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="clib_class" aria-label="<?php esc_attr_e( 'Classification', 'church-library' ); ?>"><?php echo CLIB_Form::lov_options( 'classification', $clib_args['classification_id'], __( 'All classifications', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="clib_status" aria-label="<?php esc_attr_e( 'Status', 'church-library' ); ?>"><?php echo CLIB_Form::map_options( $clib_statuses, $clib_args['status'], __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="clib_sort" aria-label="<?php esc_attr_e( 'Sort by', 'church-library' ); ?>">
		<?php
		echo CLIB_Form::map_options( // phpcs:ignore WordPress.Security.EscapeOutput
			array(
				'title'      => __( 'Sort: Title', 'church-library' ),
				'creator'    => __( 'Sort: Author', 'church-library' ),
				'item_code'  => __( 'Sort: Item ID', 'church-library' ),
				'updated_at' => __( 'Sort: Recently updated', 'church-library' ),
			),
			$clib_args['orderby']
		);
		?>
	</select>
	<button type="submit" class="clib-btn"><?php esc_html_e( 'Search', 'church-library' ); ?></button>
	<a class="clib-reset" href="<?php echo esc_url( $clib_base ); ?>"><?php esc_html_e( 'Clear', 'church-library' ); ?></a>
</form>

<p class="clib-count">
	<?php
	/* translators: %s: number of items */
	echo esc_html( sprintf( _n( '%s item', '%s items', $clib_total, 'church-library' ), number_format_i18n( $clib_total ) ) );
	?>
</p>

<?php if ( $clib_rows ) : ?>
	<ul class="clib-grid">
		<?php foreach ( $clib_rows as $clib_r ) : ?>
			<li class="clib-card">
				<a href="<?php echo esc_url( add_query_arg( 'clib_item', rawurlencode( $clib_r->item_code ), $clib_base ) ); ?>">
					<span class="clib-thumb">
						<?php
						if ( $clib_r->image_id ) {
							echo wp_get_attachment_image( (int) $clib_r->image_id, 'thumbnail', false, array( 'alt' => '' ) );
						}
						?>
					</span>
					<span class="clib-card-body">
						<strong><?php echo esc_html( $clib_r->title ); ?></strong>
						<?php if ( $clib_r->creator ) : ?>
							<span class="clib-creator"><?php echo esc_html( $clib_r->creator ); ?></span>
						<?php endif; ?>
						<span class="clib-meta"><?php echo esc_html( $clib_r->item_code . ' · ' . $clib_r->media_type_label . ( $clib_r->church_label ? ' · ' . $clib_r->church_label : '' ) ); ?></span>
						<span class="clib-status clib-status-<?php echo esc_attr( $clib_r->storage_status ); ?>"><?php echo esc_html( CLIB_Util::label( $clib_statuses, $clib_r->storage_status ) ); ?></span>
					</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else : ?>
	<p><?php esc_html_e( 'No items found.', 'church-library' ); ?></p>
<?php endif; ?>

<?php if ( $clib_pages > 1 ) : ?>
	<nav class="clib-pages" aria-label="<?php esc_attr_e( 'Pages', 'church-library' ); ?>">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'     => add_query_arg( 'clib_page', '%#%' ), // paginate_links() runs esc_url() on output.
					'format'   => '',
					'current'  => $clib_args['page'],
					'total'    => $clib_pages,
					'add_args' => false,
				)
			)
		);
		?>
	</nav>
<?php endif; ?>
