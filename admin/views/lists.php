<?php
/**
 * Library - List of Values (admin-managed dropdown values).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_types = CLIB_LOV::types();
$clib_type  = CLIB_Util::enum( CLIB_Util::get( 'list' ), array_keys( $clib_types ), 'media_type' );
$clib_rows  = CLIB_LOV::all( $clib_type );
$clib_post  = esc_url( admin_url( 'admin-post.php' ) );
?>
<h1><?php esc_html_e( 'Library - List of Values', 'church-library' ); ?></h1>
<p><?php esc_html_e( 'Add, rename, reorder, hide or delete the values offered in the library\'s dropdowns. Untick "Active" to hide a value from dropdowns without changing items that already use it. Values that are in use cannot be deleted.', 'church-library' ); ?></p>

<nav class="nav-tab-wrapper">
	<?php foreach ( $clib_types as $clib_k => $clib_label ) : ?>
		<a class="nav-tab <?php echo $clib_k === $clib_type ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( CLIB_Admin::url( 'clib-lists', array( 'list' => $clib_k ) ) ); ?>"><?php echo esc_html( $clib_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( 'media_type' === $clib_type ) : ?>
	<p class="description"><?php esc_html_e( 'The ID prefix is used for new Item IDs, e.g. BOOK-000123. Tick "Music" for types that use the music sub-category and difficulty fields.', 'church-library' ); ?></p>
<?php elseif ( 'room' === $clib_type ) : ?>
	<p class="description"><?php esc_html_e( 'Link a room to its building so the item form only offers rooms in the chosen building. Renaming a room renames it on every item that uses it.', 'church-library' ); ?></p>
<?php elseif ( 'building' === $clib_type ) : ?>
	<p class="description"><?php esc_html_e( 'Link a building to its church so the item form only offers the right buildings.', 'church-library' ); ?></p>
<?php elseif ( in_array( $clib_type, CLIB_LOV::coded_types(), true ) ) : ?>
	<p class="description"><?php esc_html_e( 'Renaming a value updates every item that uses it. The stored value is set when an entry is created and never changes. Storage Status (Checked In / Checked Out / Not Available) is fixed because check in and check out depend on it.', 'church-library' ); ?></p>
<?php elseif ( 'liturgical_use' === $clib_type ) : ?>
	<p class="description"><?php esc_html_e( 'These are suggestions on the item form; staff can still type another value.', 'church-library' ); ?></p>
<?php endif; ?>

<table class="widefat striped clib-lov">
	<thead><tr>
		<th><?php esc_html_e( 'Label', 'church-library' ); ?></th>
		<?php if ( 'media_type' === $clib_type ) : ?>
			<th><?php esc_html_e( 'ID prefix', 'church-library' ); ?></th>
			<th><?php esc_html_e( 'Music', 'church-library' ); ?></th>
		<?php endif; ?>
		<?php if ( 'building' === $clib_type ) : ?>
			<th><?php esc_html_e( 'Church', 'church-library' ); ?></th>
		<?php endif; ?>
		<?php if ( 'room' === $clib_type ) : ?>
			<th><?php esc_html_e( 'Building', 'church-library' ); ?></th>
		<?php endif; ?>
		<?php if ( in_array( $clib_type, CLIB_LOV::coded_types(), true ) ) : ?>
			<th><?php esc_html_e( 'Stored value', 'church-library' ); ?></th>
		<?php endif; ?>
		<th><?php esc_html_e( 'Sort', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Active', 'church-library' ); ?></th>
		<th></th>
	</tr></thead>
	<tbody>
	<?php foreach ( $clib_rows as $clib_row ) : ?>
		<?php $clib_form = 'clib-lov-' . (int) $clib_row->id; ?>
		<tr>
			<td><input form="<?php echo esc_attr( $clib_form ); ?>" type="text" name="label" maxlength="100" required value="<?php echo esc_attr( $clib_row->label ); ?>"></td>
			<?php if ( 'media_type' === $clib_type ) : ?>
				<td><input form="<?php echo esc_attr( $clib_form ); ?>" type="text" name="code" maxlength="10" pattern="[A-Za-z0-9]{2,10}" required size="8" value="<?php echo esc_attr( $clib_row->code ); ?>"></td>
				<td><input form="<?php echo esc_attr( $clib_form ); ?>" type="checkbox" name="is_music" value="1" <?php checked( (int) $clib_row->is_music, 1 ); ?>></td>
			<?php endif; ?>
			<?php if ( 'building' === $clib_type ) : ?>
				<td><select form="<?php echo esc_attr( $clib_form ); ?>" name="parent_id"><?php echo CLIB_Admin::lov_options( 'church', $clib_row->parent_id, __( '(any)', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
			<?php endif; ?>
			<?php if ( 'room' === $clib_type ) : ?>
				<td><select form="<?php echo esc_attr( $clib_form ); ?>" name="parent_id"><?php echo CLIB_Admin::lov_options( 'building', $clib_row->parent_id, __( '(any)', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select></td>
			<?php endif; ?>
			<?php if ( in_array( $clib_type, CLIB_LOV::coded_types(), true ) ) : ?>
				<td><code><?php echo esc_html( $clib_row->code ); ?></code></td>
			<?php endif; ?>
			<td><input form="<?php echo esc_attr( $clib_form ); ?>" type="number" name="sort_order" min="0" max="100000" class="small-text" value="<?php echo esc_attr( (int) $clib_row->sort_order ); ?>"></td>
			<td><input form="<?php echo esc_attr( $clib_form ); ?>" type="checkbox" name="is_active" value="1" <?php checked( (int) $clib_row->is_active, 1 ); ?>></td>
			<td class="clib-lov-actions">
				<form id="<?php echo esc_attr( $clib_form ); ?>" method="post" action="<?php echo $clib_post; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above. ?>">
					<?php wp_nonce_field( 'clib_save_lov' ); ?>
					<input type="hidden" name="action" value="clib_save_lov">
					<input type="hidden" name="id" value="<?php echo esc_attr( (int) $clib_row->id ); ?>">
					<button class="button button-small"><?php esc_html_e( 'Save', 'church-library' ); ?></button>
				</form>
				<form method="post" action="<?php echo $clib_post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" data-clib-confirm="1">
					<?php wp_nonce_field( 'clib_delete_lov_' . (int) $clib_row->id ); ?>
					<input type="hidden" name="action" value="clib_delete_lov">
					<input type="hidden" name="id" value="<?php echo esc_attr( (int) $clib_row->id ); ?>">
					<button class="button button-small button-link-delete"><?php esc_html_e( 'Delete', 'church-library' ); ?></button>
				</form>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>

<h2><?php esc_html_e( 'Add new', 'church-library' ); ?></h2>
<form method="post" action="<?php echo $clib_post; // phpcs:ignore WordPress.Security.EscapeOutput ?>" class="clib-filters">
	<?php wp_nonce_field( 'clib_save_lov' ); ?>
	<input type="hidden" name="action" value="clib_save_lov">
	<input type="hidden" name="list_type" value="<?php echo esc_attr( $clib_type ); ?>">
	<input type="text" name="label" maxlength="100" required placeholder="<?php esc_attr_e( 'Label', 'church-library' ); ?>">
	<?php if ( 'media_type' === $clib_type ) : ?>
		<input type="text" name="code" maxlength="10" pattern="[A-Za-z0-9]{2,10}" required size="8" placeholder="<?php esc_attr_e( 'ID prefix', 'church-library' ); ?>">
		<label><input type="checkbox" name="is_music" value="1"> <?php esc_html_e( 'Music', 'church-library' ); ?></label>
	<?php endif; ?>
	<?php if ( 'building' === $clib_type ) : ?>
		<select name="parent_id"><?php echo CLIB_Admin::lov_options( 'church', 0, __( 'Church (any)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<?php endif; ?>
	<?php if ( 'room' === $clib_type ) : ?>
		<select name="parent_id"><?php echo CLIB_Admin::lov_options( 'building', 0, __( 'Building (any)', 'church-library' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></select>
	<?php endif; ?>
	<input type="number" name="sort_order" min="0" max="100000" class="small-text" value="<?php echo esc_attr( ( count( $clib_rows ) + 1 ) * 10 ); ?>">
	<button class="button button-primary"><?php esc_html_e( 'Add', 'church-library' ); ?></button>
</form>
