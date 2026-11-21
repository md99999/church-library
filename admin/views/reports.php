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

<?php if ( 'history' === $clib_key || in_array( $clib_key, CLIB_Reports::ITEM_FILTER_REPORTS, true ) ) : ?>
	<form method="get" class="clib-filters">
		<input type="hidden" name="page" value="clib-reports">
		<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
		<?php if ( 'history' === $clib_key ) : ?>
			<label><?php esc_html_e( 'From', 'church-library' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $clib_args['from'] ); ?>"></label>
			<label><?php esc_html_e( 'To', 'church-library' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $clib_args['to'] ); ?>"></label>
		<?php else : ?>
			<?php if ( 'missing' === $clib_key ) : ?>
				<label><?php esc_html_e( 'Missing', 'church-library' ); ?> <select name="missing"><?php echo CLIB_Form::map_options( CLIB_Reports::missing_choices(), $clib_args['missing'], __( 'Any field', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
			<?php endif; ?>
			<select name="church_id" class="clib-church" aria-label="<?php esc_attr_e( 'Church', 'church-library' ); ?>"><?php echo CLIB_Admin::lov_options( 'church', $clib_args['church_id'], __( 'All churches', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<select name="building_id" class="clib-building" aria-label="<?php esc_attr_e( 'Building', 'church-library' ); ?>"><?php echo CLIB_Admin::lov_options( 'building', $clib_args['building_id'], __( 'All buildings', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<?php if ( 'inventory' !== $clib_key ) : ?>
				<select name="room" class="clib-room" aria-label="<?php esc_attr_e( 'Room', 'church-library' ); ?>"><?php echo CLIB_Form::room_options( $clib_args['room'], __( 'All rooms', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<?php endif; ?>
			<select name="media_type_id" aria-label="<?php esc_attr_e( 'Media Type', 'church-library' ); ?>"><?php echo CLIB_Admin::lov_options( 'media_type', $clib_args['media_type_id'], __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<?php if ( 'missing' !== $clib_key ) : ?>
				<select name="status" aria-label="<?php esc_attr_e( 'Status', 'church-library' ); ?>"><?php echo CLIB_Admin::map_options( CLIB_Util::status_labels(), $clib_args['status'], __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			<?php endif; ?>
			<?php if ( 'checklist' === $clib_key ) : ?>
				<label><?php esc_html_e( 'Sort by', 'church-library' ); ?> <select name="sort_by"><?php echo CLIB_Form::map_options( CLIB_Reports::checklist_sorts(), $clib_args['sort_by'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label>
				<label><input type="checkbox" name="page_break" value="1" <?php checked( 1, $clib_args['page_break'] ); ?>> <?php esc_html_e( 'New page for each room', 'church-library' ); ?></label>
			<?php endif; ?>
		<?php endif; ?>
		<button class="button"><?php esc_html_e( 'Run', 'church-library' ); ?></button>
	</form>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-export">
	<?php wp_nonce_field( 'clib_export' ); ?>
	<input type="hidden" name="action" value="clib_export">
	<input type="hidden" name="report" value="<?php echo esc_attr( $clib_key ); ?>">
	<?php foreach ( CLIB_Reports::FILTER_KEYS as $clib_k ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $clib_k ); ?>" value="<?php echo esc_attr( $clib_args[ $clib_k ] ); ?>">
	<?php endforeach; ?>
	<button class="button"><?php esc_html_e( 'Download CSV', 'church-library' ); ?></button>
	<button type="button" class="button clib-print"><?php esc_html_e( 'Print', 'church-library' ); ?></button>
</form>

<?php
$clib_can_edit = current_user_can( 'clib_manage_items' );
foreach ( $clib_data as $clib_section ) :
	?>
	<div class="clib-report-section <?php echo esc_attr( isset( $clib_section['class'] ) ? $clib_section['class'] : '' ); ?>">
	<h2><?php echo esc_html( $clib_section['title'] ); ?></h2>
	<?php if ( ! empty( $clib_section['note'] ) ) : ?>
		<p class="clib-report-note"><?php echo esc_html( $clib_section['note'] ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $clib_section['signoff'] ) ) : ?>
		<p class="clib-signoff"><?php esc_html_e( 'Counted by:', 'church-library' ); ?> <span class="clib-blank"></span> <?php esc_html_e( 'Date:', 'church-library' ); ?> <span class="clib-blank clib-blank-short"></span></p>
	<?php endif; ?>
	<table class="widefat striped clib-report">
		<thead><tr>
			<?php foreach ( $clib_section['columns'] as $clib_col => $clib_label ) : ?>
				<th class="clib-col-<?php echo esc_attr( $clib_col ); ?>"><?php echo esc_html( $clib_label ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php if ( ! $clib_section['rows'] ) : ?>
			<tr><td colspan="<?php echo esc_attr( count( $clib_section['columns'] ) ); ?>"><?php esc_html_e( 'No data.', 'church-library' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $clib_section['rows'] as $clib_row ) : ?>
			<tr class="<?php echo ! empty( $clib_row['days_over'] ) ? 'clib-overdue' : ''; ?>">
				<?php foreach ( array_keys( $clib_section['columns'] ) as $clib_col ) : ?>
					<td class="clib-col-<?php echo esc_attr( $clib_col ); ?>">
						<?php
						$clib_val     = isset( $clib_row[ $clib_col ] ) ? (string) $clib_row[ $clib_col ] : '';
						$clib_item_id = ! empty( $clib_row['_item_id'] ) ? (int) $clib_row['_item_id'] : 0;
						if ( 'found' === $clib_col ) {
							echo '<span class="clib-tickbox" role="img" aria-label="' . esc_attr__( 'Found: tick on paper', 'church-library' ) . '"></span>';
						} elseif ( 'edit' === $clib_col ) {
							if ( $clib_item_id && $clib_can_edit ) {
								echo '<a href="' . esc_url( CLIB_Admin::url( 'clib-item', array( 'id' => $clib_item_id ) ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Edit', 'church-library' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'church-library' ) . '</span></a>';
							}
						} elseif ( 'title' === $clib_col && $clib_item_id ) {
							echo '<a href="' . esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => $clib_item_id ) ) ) . '">' . esc_html( $clib_val ) . '</a>';
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
