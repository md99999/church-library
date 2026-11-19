<?php
/**
 * Library - Catalog (list + single item view).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_view_id = CLIB_Util::int( CLIB_Util::get( 'view' ) );
if ( $clib_view_id ) {
	include CLIB_DIR . 'admin/views/item-view.php';
	return;
}

$clib_args = array(
	'q'             => CLIB_Util::text( CLIB_Util::get( 's' ), 100 ),
	'media_type_id' => CLIB_Util::int( CLIB_Util::get( 'media_type_id' ) ),
	'church_id'     => CLIB_Util::int( CLIB_Util::get( 'church_id' ) ),
	'building_id'   => CLIB_Util::int( CLIB_Util::get( 'building_id' ) ),
	'status'        => CLIB_Util::enum( CLIB_Util::get( 'status' ), CLIB_Util::STATUSES, '' ),
	'orderby'       => CLIB_Util::enum( CLIB_Util::get( 'orderby' ), array_keys( CLIB_Items::sort_columns() ), 'title' ),
	'order'         => CLIB_Util::enum( CLIB_Util::get( 'order' ), array( 'asc', 'desc' ), 'asc' ),
	'page'          => CLIB_Util::int( CLIB_Util::get( 'paged', 1 ), 1, 100000 ),
	'per_page'      => (int) CLIB_Settings::get( 'per_page' ),
);
list( $clib_rows, $clib_total ) = CLIB_Items::search( $clib_args );
$clib_pages                     = max( 1, (int) ceil( $clib_total / $clib_args['per_page'] ) );
$clib_status                    = CLIB_Util::status_labels();

$clib_filters = array_filter(
	array(
		's'             => $clib_args['q'],
		'media_type_id' => $clib_args['media_type_id'],
		'church_id'     => $clib_args['church_id'],
		'building_id'   => $clib_args['building_id'],
		'status'        => $clib_args['status'],
	)
);

$clib_sort_link = function ( $col, $label ) use ( $clib_args, $clib_filters ) {
	$order = ( $clib_args['orderby'] === $col && 'asc' === $clib_args['order'] ) ? 'desc' : 'asc';
	$url   = CLIB_Admin::url( 'clib-catalog', array_merge( $clib_filters, array( 'orderby' => $col, 'order' => $order ) ) );
	$arrow = $clib_args['orderby'] === $col ? ( 'asc' === $clib_args['order'] ? ' &#9650;' : ' &#9660;' ) : '';
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $arrow . '</a>';
};
?>
<h1 class="wp-heading-inline"><?php esc_html_e( 'Library - Catalog', 'church-library' ); ?></h1>
<?php if ( current_user_can( 'clib_manage_items' ) ) : ?>
	<a href="<?php echo esc_url( CLIB_Admin::url( 'clib-item' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add Item', 'church-library' ); ?></a>
<?php endif; ?>
<hr class="wp-header-end">

<form method="get" class="clib-filters">
	<input type="hidden" name="page" value="clib-catalog">
	<input type="search" name="s" maxlength="100" value="<?php echo esc_attr( $clib_args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Title, author, ISBN, barcode, item ID', 'church-library' ); ?>">
	<select name="media_type_id"><?php echo CLIB_Admin::lov_options( 'media_type', $clib_args['media_type_id'], __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></select>
	<select name="church_id" class="clib-church"><?php echo CLIB_Admin::lov_options( 'church', $clib_args['church_id'], __( 'All churches', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="building_id" class="clib-building"><?php echo CLIB_Admin::lov_options( 'building', $clib_args['building_id'], __( 'All buildings', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="status"><?php echo CLIB_Admin::map_options( $clib_status, $clib_args['status'], __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<button class="button"><?php esc_html_e( 'Filter', 'church-library' ); ?></button>
</form>

<p class="clib-count">
	<?php
	/* translators: %s: count */
	echo esc_html( sprintf( _n( '%s item', '%s items', $clib_total, 'church-library' ), number_format_i18n( $clib_total ) ) );
	?>
</p>

<table class="widefat striped clib-table">
	<thead>
		<tr>
			<th class="clib-col-img"></th>
			<th><?php echo $clib_sort_link( 'item_code', __( 'Item ID', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'title', __( 'Title', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'creator', __( 'Creator/Author', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'media_type', __( 'Media Type', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'church', __( 'Church / Building', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'location', __( 'Room / Location', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
			<th><?php echo $clib_sort_link( 'storage_status', __( 'Status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></th>
		</tr>
	</thead>
	<tbody>
	<?php if ( ! $clib_rows ) : ?>
		<tr><td colspan="8"><?php esc_html_e( 'No items found.', 'church-library' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $clib_rows as $clib_r ) : ?>
		<?php $clib_view_url = CLIB_Admin::url( 'clib-catalog', array( 'view' => (int) $clib_r->id ) ); ?>
		<tr>
			<td class="clib-col-img"><?php echo $clib_r->image_id ? wp_get_attachment_image( (int) $clib_r->image_id, array( 40, 40 ) ) : ''; ?></td>
			<td><code><?php echo esc_html( $clib_r->item_code ); ?></code></td>
			<td>
				<strong><a href="<?php echo esc_url( $clib_view_url ); ?>"><?php echo esc_html( $clib_r->title ); ?></a></strong>
				<div class="row-actions">
					<a href="<?php echo esc_url( $clib_view_url ); ?>"><?php esc_html_e( 'View', 'church-library' ); ?></a>
					<?php if ( current_user_can( 'clib_manage_items' ) ) : ?>
						| <a href="<?php echo esc_url( CLIB_Admin::url( 'clib-item', array( 'id' => (int) $clib_r->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'church-library' ); ?></a>
					<?php endif; ?>
				</div>
			</td>
			<td><?php echo esc_html( $clib_r->creator ); ?></td>
			<td><?php echo esc_html( $clib_r->media_type_label ); ?></td>
			<td><?php echo esc_html( trim( $clib_r->church_label . ( $clib_r->building_label ? ' / ' . $clib_r->building_label : '' ) ) ); ?></td>
			<td><?php echo esc_html( trim( $clib_r->room . ( $clib_r->location_code ? ' / ' . $clib_r->location_code : '' ), ' /' ) ); ?></td>
			<td><span class="clib-status clib-status-<?php echo esc_attr( $clib_r->storage_status ); ?>"><?php echo esc_html( CLIB_Util::label( $clib_status, $clib_r->storage_status ) ); ?></span></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $clib_pages > 1 ) : ?>
	<div class="tablenav"><div class="tablenav-pages">
		<?php
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', CLIB_Admin::url( 'clib-catalog', array_merge( $clib_filters, array( 'orderby' => $clib_args['orderby'], 'order' => $clib_args['order'] ) ) ) ),
					'format'  => '',
					'current' => $clib_args['page'],
					'total'   => $clib_pages,
				)
			)
		);
		?>
	</div></div>
<?php endif; ?>
