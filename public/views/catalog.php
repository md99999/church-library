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
		__( 'Cat #', 'church-library' )            => $clib_item->call_number,
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
			<div class="clib-item-image"><?php echo wp_get_attachment_image( (int) $clib_item->image_id, 'medium', false, array( 'alt' => $clib_item->title ) ); ?><?php echo CLIB_Attribution::image_credit( (int) $clib_item->image_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></div>
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
					<td><?php echo esc_html( trim( $clib_l->borrower_email . ' ' . $clib_l->borrower_contact ) ); ?></td>
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
/*
 * This form is public (no login when the catalog is public), so every value is
 * treated as hostile: each parameter is read through a typed helper (text with
 * a hard length cap, integer, or whitelisted enum), only bound as a prepared
 * value in CLIB_Items::search(), and escaped again on output. Nothing from the
 * request reaches SQL text, a file path, an include or a shell command.
 */
$clib_sorts    = array( 'title', 'creator', 'item_code', 'updated_at' );
$clib_per_page = CLIB_Util::int( CLIB_Settings::get( 'per_page' ), 5, 200 ); // Never 0: also guards the page-count division below.
$clib_args     = array(
	'q'                 => CLIB_Util::search_term( CLIB_Util::get( 'clib_q' ) ),
	'media_type_id'     => CLIB_Util::int( CLIB_Util::get( 'clib_type' ), 0, 4294967295 ),
	'church_id'         => CLIB_Util::int( CLIB_Util::get( 'clib_church' ), 0, 4294967295 ),
	'building_id'       => CLIB_Util::int( CLIB_Util::get( 'clib_building' ), 0, 4294967295 ),
	'classification_id' => CLIB_Util::int( CLIB_Util::get( 'clib_class' ), 0, 4294967295 ),
	'status'            => CLIB_Util::enum( CLIB_Util::get( 'clib_status' ), CLIB_Util::STATUSES, '' ),
	'orderby'           => CLIB_Util::enum( CLIB_Util::get( 'clib_sort' ), $clib_sorts, 'title' ),
	'page'              => CLIB_Util::int( CLIB_Util::get( 'clib_page', 1 ), 1, 10000 ),
	'per_page'          => $clib_per_page,
	'search_barcode'    => $clib_staff, // Guests cannot probe for barcodes, which only staff can see.
);
$clib_args['order'] = 'updated_at' === $clib_args['orderby'] ? 'desc' : 'asc'; // From the whitelisted value, not the raw parameter.

/**
 * Searches per minute allowed from one guest IP address (or one logged-in
 * user). Filter to 0 to turn the limit off, for example when every visitor
 * reaches the site through one proxy address.
 */
$clib_limit   = (int) apply_filters( 'clib_catalog_search_rate_limit', 60 );
$clib_limited = '' !== $clib_args['q'] && CLIB_Util::rate_limited( 'catalog_search', $clib_limit, MINUTE_IN_SECONDS );

$clib_rows  = array();
$clib_total = 0;
if ( $clib_limited ) {
	echo '<div class="clib-notice clib-notice-warning" role="status">' . esc_html__( 'Too many searches in a short time. Please wait a minute and try again.', 'church-library' ) . '</div>';
} else {
	// Database errors are never shown to visitors (even with WP_DEBUG_DISPLAY on);
	// a failure is written to the Audit Log, at most once an hour.
	global $wpdb;
	$clib_prev_suppress = $wpdb->suppress_errors( true );
	$wpdb->last_error   = '';
	list( $clib_rows, $clib_total ) = CLIB_Items::search( $clib_args );
	if ( $wpdb->last_error ) {
		CLIB_Audit::log_error( 'catalog', 'Catalog search query failed: ' . CLIB_Util::cap( $wpdb->last_error, 200 ), 'catalog' );
		$clib_rows  = array();
		$clib_total = 0;
	}
	$wpdb->suppress_errors( $clib_prev_suppress );
	$clib_rows  = is_array( $clib_rows ) ? $clib_rows : array();
	$clib_total = max( 0, (int) $clib_total );
}
$clib_pages   = max( 1, (int) ceil( $clib_total / $clib_per_page ) );
$clib_page_id = CLIB_Util::int( CLIB_Util::get( 'page_id' ), 0, 4294967295 );

/*
 * Page links are built here from the permalink plus the sanitised values above
 * only. paginate_links() is not used because it copies every query parameter of
 * the current request URL into its links (even with add_args => false), which
 * would reflect unrelated or hostile parameters back into the page.
 */
$clib_link_args = array();
foreach (
	array(
		'clib_q'        => $clib_args['q'],
		'clib_type'     => $clib_args['media_type_id'],
		'clib_church'   => $clib_args['church_id'],
		'clib_building' => $clib_args['building_id'],
		'clib_class'    => $clib_args['classification_id'],
		'clib_status'   => $clib_args['status'],
		'clib_sort'     => 'title' === $clib_args['orderby'] ? '' : $clib_args['orderby'],
	) as $clib_k => $clib_v
) {
	if ( '' !== (string) $clib_v && '0' !== (string) $clib_v ) {
		$clib_link_args[ $clib_k ] = rawurlencode( (string) $clib_v ); // add_query_arg() does not encode values.
	}
}
$clib_current = min( $clib_args['page'], $clib_pages );
$clib_pager   = '';
if ( $clib_pages > 1 ) {
	$clib_page_url = function ( $n ) use ( $clib_link_args, $clib_base ) {
		$a = $clib_link_args;
		if ( $n > 1 ) {
			$a['clib_page'] = (int) $n;
		}
		return add_query_arg( $a, $clib_base );
	};
	$clib_parts = array();
	if ( $clib_current > 1 ) {
		$clib_parts[] = '<a class="prev page-numbers" href="' . esc_url( $clib_page_url( $clib_current - 1 ) ) . '">' . '&laquo; ' . esc_html__( 'Previous', 'church-library' ) . '</a>';
	}
	$clib_gap = false;
	for ( $clib_n = 1; $clib_n <= $clib_pages; $clib_n++ ) {
		if ( 1 === $clib_n || $clib_pages === $clib_n || abs( $clib_n - $clib_current ) <= 2 ) {
			if ( $clib_n === $clib_current ) {
				$clib_parts[] = '<span aria-current="page" class="page-numbers current">' . esc_html( number_format_i18n( $clib_n ) ) . '</span>';
			} else {
				$clib_parts[] = '<a class="page-numbers" href="' . esc_url( $clib_page_url( $clib_n ) ) . '">' . esc_html( number_format_i18n( $clib_n ) ) . '</a>';
			}
			$clib_gap = false;
		} elseif ( ! $clib_gap ) {
			$clib_parts[] = '<span class="page-numbers dots">&hellip;</span>';
			$clib_gap     = true;
		}
	}
	if ( $clib_current < $clib_pages ) {
		$clib_parts[] = '<a class="next page-numbers" href="' . esc_url( $clib_page_url( $clib_current + 1 ) ) . '">' . esc_html__( 'Next', 'church-library' ) . ' &raquo;' . '</a>';
	}
	$clib_pager = implode( "\n", $clib_parts );
}
?>
<form class="clib-search" method="get" action="<?php echo esc_url( $clib_base ); ?>" role="search">
	<?php if ( $clib_page_id ) : ?>
		<input type="hidden" name="page_id" value="<?php echo esc_attr( $clib_page_id ); ?>">
	<?php endif; ?>
	<input type="search" name="clib_q" maxlength="<?php echo (int) CLIB_Util::SEARCH_MAX; ?>" value="<?php echo esc_attr( $clib_args['q'] ); ?>" placeholder="<?php esc_attr_e( 'Search title, author, publisher, ISBN, Item ID...', 'church-library' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'church-library' ); ?>">
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

<?php if ( $clib_pager ) : ?>
	<nav class="clib-pages clib-pages-top" aria-label="<?php esc_attr_e( 'Pages (top)', 'church-library' ); ?>"><?php echo $clib_pager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped where built above. ?></nav>
<?php endif; ?>

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

<?php if ( $clib_pager ) : ?>
	<nav class="clib-pages clib-pages-bottom" aria-label="<?php esc_attr_e( 'Pages (bottom)', 'church-library' ); ?>"><?php echo $clib_pager; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part escaped where built above. ?></nav>
<?php endif; ?>
