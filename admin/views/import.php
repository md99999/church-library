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
<h1><?php esc_html_e( 'Library - Import / Export', 'church-library' ); ?></h1>

<h2><?php esc_html_e( 'Export the catalog', 'church-library' ); ?></h2>
<p><?php esc_html_e( 'Download every item, with every field, as a CSV file that opens in Excel. You can edit it and import it back below with "Update items whose Item ID already exists" ticked. Spreadsheet formulas are neutralised so a cell can never run as a formula when the file is opened.', 'church-library' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-filters">
	<?php wp_nonce_field( 'clib_export_catalog' ); ?>
	<input type="hidden" name="action" value="clib_export_catalog">
	<select name="media_type_id"><?php echo CLIB_Form::lov_options( 'media_type', 0, __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="church_id" class="clib-church"><?php echo CLIB_Form::lov_options( 'church', 0, __( 'All churches', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="building_id" class="clib-building"><?php echo CLIB_Form::lov_options( 'building', 0, __( 'All buildings', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<select name="status"><?php echo CLIB_Form::map_options( CLIB_Util::status_labels(), '', __( 'Any status', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<label><input type="checkbox" name="music_only" value="1"> <?php esc_html_e( 'Music only', 'church-library' ); ?></label>
	<button class="button button-primary"><?php esc_html_e( 'Download CSV', 'church-library' ); ?></button>
</form>

<h2><?php esc_html_e( 'Music items', 'church-library' ); ?></h2>
<p><?php esc_html_e( 'Music uses the same CSV format as everything else; the music columns are Music Sub-category and Difficulty Level. Tick "Music only" above to export just the music library, or start from the blank music template. Values must match Library - List of Values exactly (any capitalisation); rows that do not match are skipped and listed so you can fix them.', 'church-library' ); ?></p>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'clib_music_template' ); ?>
	<input type="hidden" name="action" value="clib_music_template">
	<button class="button"><?php esc_html_e( 'Download blank music template', 'church-library' ); ?></button>
</form>
<details class="clib-allowed">
	<summary><?php esc_html_e( 'Allowed values for music columns', 'church-library' ); ?></summary>
	<?php
	$clib_music_types = array();
	foreach ( CLIB_LOV::all( 'media_type', true ) as $clib_mt ) {
		if ( (int) $clib_mt->is_music ) {
			$clib_music_types[] = $clib_mt->label;
		}
	}
	$clib_allowed = array(
		__( 'Media Type (music)', 'church-library' ) => $clib_music_types,
		__( 'Music Sub-category', 'church-library' ) => wp_list_pluck( CLIB_LOV::all( 'music_subcategory', true ), 'label' ),
		__( 'Difficulty Level', 'church-library' )   => array_values( CLIB_Util::difficulty_labels( true ) ),
		__( 'Condition', 'church-library' )          => array_values( CLIB_Util::condition_labels( true ) ),
		__( 'Classification', 'church-library' )     => wp_list_pluck( CLIB_LOV::all( 'classification', true ), 'label' ),
		__( 'Church Name', 'church-library' )        => wp_list_pluck( CLIB_LOV::all( 'church', true ), 'label' ),
		__( 'Building', 'church-library' )           => wp_list_pluck( CLIB_LOV::all( 'building', true ), 'label' ),
		__( 'Room', 'church-library' )               => wp_list_pluck( CLIB_LOV::all( 'room', true ), 'label' ),
	);
	?>
	<table class="widefat striped">
		<?php foreach ( $clib_allowed as $clib_col => $clib_vals ) : ?>
			<tr><th scope="row"><?php echo esc_html( $clib_col ); ?></th><td><?php echo $clib_vals ? esc_html( implode( ' · ', $clib_vals ) ) : '<em>' . esc_html__( '(none yet)', 'church-library' ) . '</em>'; ?></td></tr>
		<?php endforeach; ?>
	</table>
</details>

<hr>
<h2><?php esc_html_e( 'Import from CSV', 'church-library' ); ?></h2>

<?php if ( is_array( $clib_result ) ) : ?>
	<div class="notice <?php echo $clib_result['errors'] ? 'notice-warning' : 'notice-success'; ?>">
		<p><strong>
			<?php
			if ( $clib_result['dry_run'] ) {
				/* translators: 1: rows 2: would import 3: skipped */
				echo esc_html( sprintf( __( 'Preview only: %1$d rows read, %2$d would be added, %4$d would be updated, %3$d skipped.', 'church-library' ), $clib_result['rows'], $clib_result['imported'], $clib_result['skipped'], isset( $clib_result['updated'] ) ? $clib_result['updated'] : 0 ) );
			} else {
				/* translators: 1: rows 2: imported 3: skipped */
				echo esc_html( sprintf( __( '%1$d rows read, %2$d added, %4$d updated, %3$d skipped.', 'church-library' ), $clib_result['rows'], $clib_result['imported'], $clib_result['skipped'], isset( $clib_result['updated'] ) ? $clib_result['updated'] : 0 ) );
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
<p><?php esc_html_e( 'Recognised headings include: Item ID, Title (required), Author\'s Name / Author / Creator, Publisher, Description, Notes, Barcode, Cat # (or Call #), Classification, Cost, ISBN / Identifier, Media Type, Music Sub-category, Difficulty Level, Church, Building, Room, Location ID, Copies, Condition, Storage Status, Owned By, Liturgical Use, Acquired Date, Inventory Notes. Other columns are ignored.', 'church-library' ); ?></p>

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
				<label><input type="checkbox" name="update_existing" value="1"> <?php esc_html_e( 'Update items whose Item ID already exists (for files exported above). Columns left out of the file keep their current values.', 'church-library' ); ?></label><br>
				<label><input type="checkbox" name="dry_run" value="1"> <?php esc_html_e( 'Preview only (validate without saving)', 'church-library' ); ?></label>
			</td>
		</tr>
	</table>
	<p class="submit"><button class="button button-primary"><?php esc_html_e( 'Import', 'church-library' ); ?></button></p>
</form>
