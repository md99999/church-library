<?php
/**
 * Library - Audit Log.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_page                   = CLIB_Util::int( CLIB_Util::get( 'paged', 1 ), 1, 100000 );
list( $clib_rows, $clib_total ) = CLIB_Audit::page( $clib_page, 50 );
$clib_pages                  = max( 1, (int) ceil( $clib_total / 50 ) );
?>
<h1><?php esc_html_e( 'Library - Audit Log', 'church-library' ); ?></h1>

<?php
// Page links are shown above and below the list.
$clib_pager = $clib_pages > 1 ? (string) paginate_links(
	array(
		'base'    => add_query_arg( 'paged', '%#%', CLIB_Admin::url( 'clib-audit' ) ),
		'format'  => '',
		'current' => $clib_page,
		'total'   => $clib_pages,
	)
) : '';
?>
<?php if ( $clib_pager ) : ?>
	<div class="tablenav top"><div class="tablenav-pages"><?php echo wp_kses_post( $clib_pager ); ?></div></div>
<?php endif; ?>
<table class="widefat striped">
	<thead><tr>
		<th><?php esc_html_e( 'When', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'User', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Action', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Details', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'IP', 'church-library' ); ?></th>
	</tr></thead>
	<tbody>
	<?php if ( ! $clib_rows ) : ?>
		<tr><td colspan="5"><?php esc_html_e( 'No entries yet.', 'church-library' ); ?></td></tr>
	<?php endif; ?>
	<?php foreach ( $clib_rows as $clib_r ) : ?>
		<?php $clib_u = $clib_r->user_id ? get_userdata( (int) $clib_r->user_id ) : null; ?>
		<tr>
			<td><?php echo esc_html( $clib_r->created_at ); ?></td>
			<td><?php echo esc_html( $clib_u ? $clib_u->display_name : '#' . (int) $clib_r->user_id ); ?></td>
			<td><code><?php echo esc_html( $clib_r->action ); ?></code></td>
			<td>
				<?php
				if ( 'item' === $clib_r->object_type && $clib_r->object_id && 'item_delete' !== $clib_r->action ) {
					echo '<a href="' . esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => (int) $clib_r->object_id ) ) ) . '">' . esc_html( $clib_r->details ) . '</a>';
				} else {
					echo esc_html( $clib_r->details );
				}
				?>
			</td>
			<td><?php echo esc_html( $clib_r->ip ); ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<?php if ( $clib_pager ) : ?>
	<div class="tablenav bottom"><div class="tablenav-pages"><?php echo wp_kses_post( $clib_pager ); ?></div></div>
<?php endif; ?>
