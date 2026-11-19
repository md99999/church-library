<?php
/**
 * Library - Reports.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_reports = CLIB_Reports::reports();
$clib_key     = CLIB_Util::enum( CLIB_Util::get( 'report' ), array_keys( $clib_reports ), 'summary' );
$clib_args    = CLIB_Reports::args( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, sanitized in args().
$clib_data    = CLIB_Reports::run( $clib_key, $clib_args );
?>
<h1><?php esc_html_e( 'Library - Reports', 'church-library' ); ?></h1>

<nav class="nav-tab-wrapper">
	<?php foreach ( $clib_reports as $clib_k => $clib_label ) : ?>
		<a class="nav-tab <?php echo $clib_k === $clib_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( CLIB_Admin::url( 'clib-reports', array( 'report' => $clib_k ) ) ); ?>"><?php echo esc_html( $clib_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( in_array( $clib_key, array( 'history', 'inventory' ), true ) ) : ?>
	<form method="get" class="clib-filters">
		<input type="hidden" name="page" value="clib-reports">
		<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
		<?php if ( 'history' === $clib_key ) : ?>
			<label><?php esc_html_e( 'From', 'church-library' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $clib_args['from'] ); ?>"></label>
			<label><?php esc_html_e( 'To', 'church-library' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $clib_args['to'] ); ?>"></label>
		<?php else : ?>
			<select name="church_id" class="clib-church"><?php echo CLIB_Admin::lov_options( 'church', $clib_args['church_id'], __( 'All churches', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="building_id" class="clib-building"><?php echo CLIB_Admin::lov_options( 'building', $clib_args['building_id'], __( 'All buildings', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="media_type_id"><?php echo CLIB_Admin::lov_options( 'media_type', $clib_args['media_type_id'], __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="status"><?php echo CLIB_Admin::map_options( CLIB_Util::status_labels(), $clib_args['status'], __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
		<?php endif; ?>
		<button class="button"><?php esc_html_e( 'Run', 'church-library' ); ?></button>
	</form>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-export">
	<?php wp_nonce_field( 'clib_export' ); ?>
	<input type="hidden" name="action" value="clib_export">
	<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
	<?php foreach ( array( 'from', 'to', 'church_id', 'building_id', 'media_type_id', 'status' ) as $clib_k ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $clib_k ); ?>" value="<?php echo esc_attr( $clib_args[ $clib_k ] ); ?>">
	<?php endforeach; ?>
	<button class="button"><?php esc_html_e( 'Download CSV', 'church-library' ); ?></button>
	<button type="button" class="button clib-print"><?php esc_html_e( 'Print', 'church-library' ); ?></button>
</form>

<?php foreach ( $clib_data as $clib_section ) : ?>
	<h2><?php echo esc_html( $clib_section['title'] ); ?></h2>
	<table class="widefat striped clib-report">
		<thead><tr>
			<?php foreach ( $clib_section['columns'] as $clib_label ) : ?>
				<th><?php echo esc_html( $clib_label ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php if ( ! $clib_section['rows'] ) : ?>
			<tr><td colspan="<?php echo esc_attr( count( $clib_section['columns'] ) ); ?>"><?php esc_html_e( 'No data.', 'church-library' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $clib_section['rows'] as $clib_row ) : ?>
			<tr class="<?php echo ! empty( $clib_row['days_over'] ) ? 'clib-overdue' : ''; ?>">
				<?php foreach ( array_keys( $clib_section['columns'] ) as $clib_col ) : ?>
					<td>
						<?php
						$clib_val = isset( $clib_row[ $clib_col ] ) ? (string) $clib_row[ $clib_col ] : '';
						if ( 'title' === $clib_col && ! empty( $clib_row['_item_id'] ) ) {
							echo '<a href="' . esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => (int) $clib_row['_item_id'] ) ) ) . '">' . esc_html( $clib_val ) . '</a>';
						} else {
							echo esc_html( $clib_val );
						}
						?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
<?php endforeach; ?>
