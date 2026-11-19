<?php
/**
 * Library - Reports (front end).
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
$clib_base    = get_permalink();
?>
<nav class="clib-tabs">
	<?php foreach ( $clib_reports as $clib_k => $clib_label ) : ?>
		<a class="<?php echo $clib_k === $clib_key ? 'is-current' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'report', $clib_k, $clib_base ) ); ?>"><?php echo esc_html( $clib_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( in_array( $clib_key, array( 'history', 'inventory' ), true ) ) : ?>
	<form method="get" action="<?php echo esc_url( $clib_base ); ?>" class="clib-search">
		<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
		<?php if ( 'history' === $clib_key ) : ?>
			<label><?php esc_html_e( 'From', 'church-library' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $clib_args['from'] ); ?>"></label>
			<label><?php esc_html_e( 'To', 'church-library' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $clib_args['to'] ); ?>"></label>
		<?php else : ?>
			<select name="church_id" class="clib-church"><?php echo CLIB_Form::lov_options( 'church', $clib_args['church_id'], __( 'All churches', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="building_id" class="clib-building"><?php echo CLIB_Form::lov_options( 'building', $clib_args['building_id'], __( 'All buildings', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="media_type_id"><?php echo CLIB_Form::lov_options( 'media_type', $clib_args['media_type_id'], __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="status"><?php echo CLIB_Form::map_options( CLIB_Util::status_labels(), $clib_args['status'], __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
		<?php endif; ?>
		<button type="submit" class="clib-btn"><?php esc_html_e( 'Run', 'church-library' ); ?></button>
	</form>
<?php endif; ?>

<div class="clib-report-actions">
	<?php echo CLIB_Public::form_open( 'export' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
		<?php foreach ( array( 'from', 'to', 'church_id', 'building_id', 'media_type_id', 'status' ) as $clib_k ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $clib_k ); ?>" value="<?php echo esc_attr( $clib_args[ $clib_k ] ); ?>">
		<?php endforeach; ?>
		<button type="submit" class="clib-btn clib-btn-alt"><?php esc_html_e( 'Download CSV', 'church-library' ); ?></button>
		<button type="button" class="clib-btn clib-btn-alt clib-print"><?php esc_html_e( 'Print', 'church-library' ); ?></button>
	</form>
</div>

<?php foreach ( $clib_data as $clib_section ) : ?>
	<h3><?php echo esc_html( $clib_section['title'] ); ?></h3>
	<div class="clib-scroll">
	<table class="clib-table">
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
						if ( 'title' === $clib_col && ! empty( $clib_row['item_code'] ) ) {
							echo '<a href="' . esc_url( CLIB_Pages::url( 'catalog', array( 'clib_item' => $clib_row['item_code'] ) ) ) . '">' . esc_html( $clib_val ) . '</a>';
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
	</div>
<?php endforeach; ?>
