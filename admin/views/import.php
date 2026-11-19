<?php
/**
 * Library - Import (CSV).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_result = get_transient( 'clib_import_' . get_current_user_id() );
if ( is_array( $clib_result ) ) {
	delete_transient( 'clib_import_' . get_current_user_id() );
}
?>
<h1><?php esc_html_e( 'Library - Import', 'church-library' ); ?></h1>

<?php if ( is_array( $clib_result ) ) : ?>
	<div class="notice <?php echo $clib_result['errors'] ? 'notice-warning' : 'notice-success'; ?>">
		<p><strong>
			<?php
			if ( $clib_result['dry_run'] ) {
				/* translators: 1: rows 2: would import 3: skipped */
				echo esc_html( sprintf( __( 'Preview only: %1$d rows read, %2$d would be imported, %3$d skipped.', 'church-library' ), $clib_result['rows'], $clib_result['imported'], $clib_result['skipped'] ) );
			} else {
				/* translators: 1: rows 2: imported 3: skipped */
				echo esc_html( sprintf( __( '%1$d rows read, %2$d imported, %3$d skipped.', 'church-library' ), $clib_result['rows'], $clib_result['imported'], $clib_result['skipped'] ) );
			}
			?>
		</strong></p>
		<p><?php esc_html_e( 'Columns recognised:', 'church-library' ); ?> <code><?php echo esc_html( implode( ', ', $clib_result['columns'] ) ); ?></code></p>
		<?php if ( $clib_result['errors'] ) : ?>
			<ul class="ul-disc">
				<?php foreach ( $clib_result['errors'] as $clib_e ) : ?>
					<li><?php echo esc_html( $clib_e ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
<?php endif; ?>

<p><?php esc_html_e( 'Import items from a CSV file. In Excel, open your sheet and use File > Save As > "CSV UTF-8". The first row must be column headings.', 'church-library' ); ?></p>
<p><?php esc_html_e( 'Recognised headings include: Title (required), Author\'s Name / Author / Creator, Publisher, Description, Notes, Barcode, Call #, Classification, Cost, ISBN / Identifier, Media Type, Church, Building, Room, Location ID, Copies, Condition, Owned By, Liturgical Use, Inventory Notes. Other columns are ignored.', 'church-library' ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="clib-section">
	<?php wp_nonce_field( 'clib_import' ); ?>
	<input type="hidden" name="action" value="clib_import">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="clib-csv"><?php esc_html_e( 'CSV file', 'church-library' ); ?></label></th>
			<td><input type="file" id="clib-csv" name="clib_csv" accept=".csv,text/csv" required> <span class="description"><?php esc_html_e( 'Up to 5 MB / 5,000 rows.', 'church-library' ); ?></span></td>
		</tr>
		<tr>
			<th scope="row"><label for="clib-imp-media"><?php esc_html_e( 'Default media type', 'church-library' ); ?></label></th>
			<td><select id="clib-imp-media" name="media_type_id" required><?php echo CLIB_Admin::lov_options( 'media_type', 0, __( 'Choose...', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				<p class="description"><?php esc_html_e( 'Used when a row has no Media Type column value.', 'church-library' ); ?></p></td>
		</tr>
		<tr>
			<th scope="row"><label for="clib-imp-church"><?php esc_html_e( 'Default church', 'church-library' ); ?></label></th>
			<td><select id="clib-imp-church" name="church_id" class="clib-church"><?php echo CLIB_Admin::lov_options( 'church', 0, __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
		</tr>
		<tr>
			<th scope="row"><label for="clib-imp-building"><?php esc_html_e( 'Default building', 'church-library' ); ?></label></th>
			<td><select id="clib-imp-building" name="building_id" class="clib-building"><?php echo CLIB_Admin::lov_options( 'building', 0, __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Options', 'church-library' ); ?></th>
			<td>
				<label><input type="checkbox" name="skip_dupes" value="1" checked> <?php esc_html_e( 'Skip rows whose barcode (or title + author) already exists', 'church-library' ); ?></label><br>
				<label><input type="checkbox" name="add_classes" value="1" checked> <?php esc_html_e( 'Add unknown classifications to the Classifications list', 'church-library' ); ?></label><br>
				<label><input type="checkbox" name="dry_run" value="1"> <?php esc_html_e( 'Preview only (validate without saving)', 'church-library' ); ?></label>
			</td>
		</tr>
	</table>
	<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Import', 'church-library' ); ?></button></p>
</form>
