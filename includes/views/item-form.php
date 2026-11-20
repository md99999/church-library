<?php
/**
 * Shared item form (admin "Library - Add Item" and front-end "Library - Add or Edit Item").
 *
 * Expects: $clib_item (object|null), $clib_id (int), $clib_f (callable value getter),
 * $clib_form_url (string), $clib_form_hidden (pre-escaped hidden fields incl. nonce),
 * $clib_cancel_url (string).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// "Edit list" link beside each dropdown for people who manage the List of Values.
$clib_lov_link = function ( $list ) {
	if ( ! current_user_can( 'clib_manage_lists' ) ) {
		return '';
	}
	return ' <a class="clib-edit-list" href="' . esc_url( admin_url( 'admin.php?page=clib-lists&list=' . rawurlencode( $list ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Edit list', 'church-library' ) . '</a>';
};
?>
<form method="post" action="<?php echo esc_url( $clib_form_url ); ?>" enctype="multipart/form-data" class="clib-item-form" id="clib-item-form">
	<?php echo $clib_form_hidden; // phpcs:ignore WordPress.Security.EscapeOutput -- built from nonce fields and esc_attr by the caller. ?>
	<?php
	// Save buttons are shown above and below the form (long form: no scrolling to save).
	$clib_submit_row = function ( $clib_where ) use ( $clib_item, $clib_cancel_url ) {
		?>
		<p class="submit clib-submit <?php echo esc_attr( $clib_where ); ?>">
			<button type="submit" class="button button-primary"><?php echo $clib_item ? esc_html__( 'Save Item', 'church-library' ) : esc_html__( 'Add Item', 'church-library' ); ?></button>
			<?php if ( ! $clib_item ) : ?>
				<button type="submit" class="button" name="add_another" value="1"><?php esc_html_e( 'Add and start another', 'church-library' ); ?></button>
			<?php endif; ?>
			<a class="button-link" href="<?php echo esc_url( $clib_cancel_url ); ?>"><?php esc_html_e( 'Cancel', 'church-library' ); ?></a>
		</p>
		<?php
	};
	$clib_submit_row( 'clib-submit-top' );
	?>
	<input type="hidden" name="id" value="<?php echo esc_attr( $clib_id ); ?>">
	<input type="hidden" name="cover_provider" id="clib-cover-provider" value="">
	<input type="hidden" name="cover_ref" id="clib-cover-ref" value="">

	<div class="clib-form-grid">
		<div class="clib-section">
			<h2><?php esc_html_e( 'Item', 'church-library' ); ?></h2>

			<p class="clib-field clib-title-field">
				<label for="clib-title"><?php esc_html_e( 'Title', 'church-library' ); ?> <span class="required">*</span></label>
				<span class="clib-lookup-row">
					<input type="text" id="clib-title" name="title" required maxlength="255" value="<?php echo esc_attr( $clib_f( 'title' ) ); ?>">
					<button type="button" class="button" id="clib-lookup-title" data-type="title"><?php esc_html_e( 'Look up', 'church-library' ); ?></button>
				</span>
			</p>
			<div id="clib-lookup-results" class="clib-lookup-results" aria-live="polite" hidden></div>

			<p class="clib-field">
				<label for="clib-media"><?php esc_html_e( 'Media Type', 'church-library' ); ?> <span class="required">*</span><?php echo $clib_lov_link( 'media_type' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-media" name="media_type_id" required><?php echo CLIB_Form::lov_options( 'media_type', $clib_f( 'media_type_id' ), __( 'Choose...', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				<?php if ( $clib_item ) : ?>
					<span class="description"><?php esc_html_e( 'Changing the media type gives the item a new Item ID with that media type\'s prefix when you save. Reprint its label afterwards.', 'church-library' ); ?></span>
				<?php endif; ?>
			</p>

			<div class="clib-music-only">
				<p class="clib-field">
					<label for="clib-sub"><?php esc_html_e( 'Music Sub-category', 'church-library' ); ?><?php echo $clib_lov_link( 'music_subcategory' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
					<select id="clib-sub" name="subcategory_id"><?php echo CLIB_Form::lov_options( 'music_subcategory', $clib_f( 'subcategory_id' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				</p>
				<p class="clib-field">
					<label for="clib-diff"><?php esc_html_e( 'Difficulty Level', 'church-library' ); ?><?php echo $clib_lov_link( 'difficulty' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
					<select id="clib-diff" name="difficulty"><?php echo CLIB_Form::map_options( CLIB_Util::difficulty_labels( true ), $clib_f( 'difficulty' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
				</p>
			</div>

			<p class="clib-field">
				<label for="clib-creator"><?php esc_html_e( 'Creator/Author', 'church-library' ); ?></label>
				<input type="text" id="clib-creator" name="creator" maxlength="255" value="<?php echo esc_attr( $clib_f( 'creator' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-publisher"><?php esc_html_e( 'Publisher', 'church-library' ); ?></label>
				<input type="text" id="clib-publisher" name="publisher" maxlength="255" value="<?php echo esc_attr( $clib_f( 'publisher' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-identifier"><?php esc_html_e( 'ISBN or Identifier', 'church-library' ); ?></label>
				<span class="clib-lookup-row">
					<input type="text" id="clib-identifier" name="identifier" maxlength="64" value="<?php echo esc_attr( $clib_f( 'identifier' ) ); ?>">
					<button type="button" class="button" id="clib-lookup-isbn" data-type="isbn"><?php esc_html_e( 'Look up ISBN / barcode', 'church-library' ); ?></button>
				</span>
			</p>
			<p class="clib-field">
				<label for="clib-class"><?php esc_html_e( 'Classification', 'church-library' ); ?><?php echo $clib_lov_link( 'classification' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-class" name="classification_id"><?php echo CLIB_Form::lov_options( 'classification', $clib_f( 'classification_id' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-lit"><?php esc_html_e( 'Liturgical Use', 'church-library' ); ?><?php echo $clib_lov_link( 'liturgical_use' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<input type="text" id="clib-lit" name="liturgical_use" maxlength="255" list="clib-lit-list" value="<?php echo esc_attr( $clib_f( 'liturgical_use' ) ); ?>">
				<datalist id="clib-lit-list">
					<?php foreach ( CLIB_LOV::all( 'liturgical_use', true ) as $clib_row ) : ?>
						<option value="<?php echo esc_attr( $clib_row->label ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
			</p>
			<p class="clib-field">
				<label for="clib-desc"><?php esc_html_e( 'Description', 'church-library' ); ?></label>
				<textarea id="clib-desc" name="description" rows="5" maxlength="10000"><?php echo esc_textarea( $clib_f( 'description' ) ); ?></textarea>
			</p>
			<p class="clib-field">
				<label for="clib-notes"><?php esc_html_e( 'Notes', 'church-library' ); ?></label>
				<textarea id="clib-notes" name="notes" rows="3" maxlength="5000"><?php echo esc_textarea( $clib_f( 'notes' ) ); ?></textarea>
			</p>
		</div>

		<div class="clib-section">
			<h2><?php esc_html_e( 'Location', 'church-library' ); ?></h2>
			<p class="clib-field">
				<label for="clib-church"><?php esc_html_e( 'Church Name', 'church-library' ); ?><?php echo $clib_lov_link( 'church' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-church" name="church_id" class="clib-church"><?php echo CLIB_Form::lov_options( 'church', $clib_f( 'church_id' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-building"><?php esc_html_e( 'Building', 'church-library' ); ?><?php echo $clib_lov_link( 'building' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-building" name="building_id" class="clib-building"><?php echo CLIB_Form::lov_options( 'building', $clib_f( 'building_id' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-room"><?php esc_html_e( 'Room', 'church-library' ); ?><?php echo $clib_lov_link( 'room' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-room" name="room" class="clib-room"><?php echo CLIB_Form::room_options( $clib_f( 'room' ), __( '(none)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-loc"><?php esc_html_e( 'Location ID', 'church-library' ); ?></label>
				<input type="text" id="clib-loc" name="location_code" maxlength="64" placeholder="<?php esc_attr_e( 'e.g. A-12-3 (aisle-shelf-bin)', 'church-library' ); ?>" value="<?php echo esc_attr( $clib_f( 'location_code' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-call"><?php esc_html_e( 'Call #', 'church-library' ); ?></label>
				<input type="text" id="clib-call" name="call_number" maxlength="64" value="<?php echo esc_attr( $clib_f( 'call_number' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-barcode"><?php esc_html_e( 'Barcode', 'church-library' ); ?></label>
				<input type="text" id="clib-barcode" name="barcode" maxlength="64" value="<?php echo esc_attr( $clib_f( 'barcode' ) ); ?>">
			</p>

			<h2><?php esc_html_e( 'Inventory', 'church-library' ); ?></h2>
			<p class="clib-field">
				<label for="clib-copies"><?php esc_html_e( 'Number of Copies', 'church-library' ); ?></label>
				<input type="number" id="clib-copies" name="copies" min="1" max="9999" value="<?php echo esc_attr( $clib_f( 'copies', 1 ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-status"><?php esc_html_e( 'Storage Status', 'church-library' ); ?></label>
				<?php
				$clib_status_opts = array(
					'available'     => __( 'Available (Checked In / Checked Out follow loans)', 'church-library' ),
					'not_available' => __( 'Not Available', 'church-library' ),
				);
				$clib_cur_status  = 'not_available' === $clib_f( 'storage_status' ) ? 'not_available' : 'available';
				?>
				<select id="clib-status" name="storage_status"><?php echo CLIB_Form::map_options( $clib_status_opts, $clib_cur_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-cond"><?php esc_html_e( 'Condition', 'church-library' ); ?><?php echo $clib_lov_link( 'condition' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></label>
				<select id="clib-cond" name="item_condition"><?php echo CLIB_Form::map_options( CLIB_Util::condition_labels( true ), $clib_f( 'item_condition' ), __( '(not set)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
			</p>
			<p class="clib-field">
				<label for="clib-owned"><?php esc_html_e( 'Owned By', 'church-library' ); ?></label>
				<input type="text" id="clib-owned" name="owned_by" maxlength="150" value="<?php echo esc_attr( $clib_f( 'owned_by' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-cost"><?php esc_html_e( 'Cost', 'church-library' ); ?></label>
				<input type="text" id="clib-cost" name="cost" inputmode="decimal" maxlength="12" value="<?php echo esc_attr( $clib_f( 'cost' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-acq"><?php esc_html_e( 'Acquired Date', 'church-library' ); ?></label>
				<input type="date" id="clib-acq" name="acquired_date" value="<?php echo esc_attr( $clib_f( 'acquired_date' ) ); ?>">
			</p>
			<p class="clib-field">
				<label for="clib-inv"><?php esc_html_e( 'Inventory Notes', 'church-library' ); ?></label>
				<textarea id="clib-inv" name="inventory_notes" rows="3" maxlength="5000"><?php echo esc_textarea( $clib_f( 'inventory_notes' ) ); ?></textarea>
			</p>

			<h2><?php esc_html_e( 'Image', 'church-library' ); ?></h2>
			<div id="clib-cover-preview" class="clib-cover-preview">
				<?php
				if ( $clib_item && $clib_item->image_id ) {
					echo wp_get_attachment_image( (int) $clib_item->image_id, 'thumbnail' );
				}
				?>
			</div>
			<p class="clib-field">
				<input type="file" name="clib_image" accept="image/jpeg,image/png,image/gif,image/webp">
				<span class="description"><?php esc_html_e( 'JPG, PNG, GIF or WebP, up to 5 MB.', 'church-library' ); ?></span>
			</p>
			<?php if ( $clib_item && $clib_item->image_id ) : ?>
				<p><label><input type="checkbox" name="remove_image" value="1"> <?php esc_html_e( 'Remove image', 'church-library' ); ?></label></p>
			<?php endif; ?>
		</div>
	</div>

	<?php $clib_submit_row( 'clib-submit-bottom' ); ?>
</form>
