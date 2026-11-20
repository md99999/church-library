<?php
/**
 * Library - Data Tools: Item ID check and duplicate titles.
 *
 * Read-only scans run on page load; every change is a POST to admin-post.php
 * with a nonce, a capability check and a server-checked confirmation box.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_tab  = CLIB_Util::enum( CLIB_Util::get( 'tab' ), array( 'ids', 'dupes' ), 'ids' );
$clib_tabs = array(
	'ids'   => __( 'Item ID check', 'church-library' ),
	'dupes' => __( 'Duplicate titles', 'church-library' ),
);
?>
<h1><?php esc_html_e( 'Library - Data Tools', 'church-library' ); ?></h1>
<nav class="nav-tab-wrapper">
	<?php foreach ( $clib_tabs as $clib_k => $clib_label ) : ?>
		<a href="<?php echo esc_url( CLIB_Admin::url( 'clib-tools', array( 'tab' => $clib_k ) ) ); ?>" class="nav-tab <?php echo $clib_k === $clib_tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $clib_label ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( 'ids' === $clib_tab ) : ?>
	<?php $clib_bad = CLIB_Tools::id_mismatches(); ?>
	<p><?php esc_html_e( 'Item IDs start with the prefix of the item\'s media type (for example BOOK-000123 or DVD-000045). This lists items whose ID does not match their media type, such as an item moved from Books to DVD before IDs were regenerated, or items of a media type whose prefix was changed in List of Values.', 'church-library' ); ?></p>
	<?php if ( ! $clib_bad ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'Every Item ID matches its media type. Nothing to fix.', 'church-library' ); ?></p></div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-clib-confirm="fix" class="clib-tools-form">
			<?php wp_nonce_field( 'clib_fix_ids' ); ?>
			<input type="hidden" name="action" value="clib_fix_ids">
			<p class="clib-count">
				<?php
				/* translators: %s: number of items */
				echo esc_html( sprintf( _n( '%s item has an Item ID that does not match its media type.', '%s items have an Item ID that does not match their media type.', count( $clib_bad ), 'church-library' ), number_format_i18n( count( $clib_bad ) ) ) );
				if ( count( $clib_bad ) > CLIB_Tools::MAX_FIX ) {
					echo ' ';
					/* translators: %d: batch size */
					echo esc_html( sprintf( __( 'Up to %d are fixed per run; run it again for the rest.', 'church-library' ), CLIB_Tools::MAX_FIX ) );
				}
				?>
			</p>
			<table class="widefat striped clib-table">
				<thead><tr>
					<td class="manage-column check-column"><input type="checkbox" class="clib-check-all" checked aria-label="<?php esc_attr_e( 'Select all', 'church-library' ); ?>"></td>
					<th><?php esc_html_e( 'Current Item ID', 'church-library' ); ?></th>
					<th><?php esc_html_e( 'Title', 'church-library' ); ?></th>
					<th><?php esc_html_e( 'Media Type', 'church-library' ); ?></th>
					<th><?php esc_html_e( 'New ID will start with', 'church-library' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( array_slice( $clib_bad, 0, CLIB_Tools::MAX_FIX ) as $clib_r ) : ?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $clib_r['id'] ); ?>" checked aria-label="<?php echo esc_attr( $clib_r['item_code'] ); ?>"></th>
						<td><code><?php echo esc_html( $clib_r['item_code'] ); ?></code></td>
						<td>
							<a href="<?php echo esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => $clib_r['id'] ) ) ); ?>"><?php echo esc_html( $clib_r['title'] ); ?></a>
							<?php if ( $clib_r['is_archived'] ) : ?>
								<em>(<?php esc_html_e( 'archived', 'church-library' ); ?>)</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $clib_r['media_type'] ); ?></td>
						<td><code><?php echo esc_html( $clib_r['expected'] ); ?>-</code></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="clib-danger">
				<p><?php esc_html_e( 'Each ticked item gets the next free number for its media type\'s prefix. The old and new IDs are recorded in the audit log. Loans and history stay with the item. Labels printed with the old ID must be reprinted.', 'church-library' ); ?></p>
				<p><label><input type="checkbox" name="confirm_fix" value="1" required> <?php esc_html_e( 'I have reviewed the list and want to regenerate these Item IDs.', 'church-library' ); ?></label></p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Regenerate ticked Item IDs', 'church-library' ); ?></button></p>
			</div>
		</form>
	<?php endif; ?>

<?php else : ?>
	<?php
	$clib_close = '1' === CLIB_Util::get( 'close' );
	$clib_mt    = CLIB_Util::int( CLIB_Util::get( 'media_type_id' ) );
	$clib_mt    = CLIB_LOV::in_list( $clib_mt, 'media_type' ) ? $clib_mt : 0;
	$clib_dupes = CLIB_Tools::duplicate_groups( $clib_close, $clib_mt );
	?>
	<p><?php esc_html_e( 'Possible duplicates are catalog items with the same title in the same media type. Titles are compared ignoring capitals, accents, punctuation and a leading "The", "A" or "An". Archived items are not included. Check the author, ISBN and location before merging: two different editions may share a title.', 'church-library' ); ?></p>
	<form method="get" class="clib-filters">
		<input type="hidden" name="page" value="clib-tools">
		<input type="hidden" name="tab" value="dupes">
		<select name="media_type_id"><?php echo CLIB_Admin::lov_options( 'media_type', $clib_mt, __( 'All media types', 'church-library' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in builder. ?></select>
		<label><input type="checkbox" name="close" value="1" <?php checked( $clib_close ); ?>> <?php esc_html_e( 'Also show close spellings (for example "Hobbit" and "Hobit")', 'church-library' ); ?></label>
		<button class="button"><?php esc_html_e( 'Find duplicates', 'church-library' ); ?></button>
	</form>

	<?php if ( $clib_dupes['truncated'] ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'The catalog is large, so the close-spelling check stopped early. Choose a single media type to check the rest.', 'church-library' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $clib_dupes['groups'] ) : ?>
		<div class="notice notice-success inline"><p><?php esc_html_e( 'No possible duplicates found.', 'church-library' ); ?></p></div>
	<?php else : ?>
		<p class="clib-count">
			<?php
			/* translators: %s: number of groups */
			echo esc_html( sprintf( _n( '%s group of possible duplicates.', '%s groups of possible duplicates.', count( $clib_dupes['groups'] ), 'church-library' ), number_format_i18n( count( $clib_dupes['groups'] ) ) ) );
			?>
		</p>
		<?php foreach ( $clib_dupes['groups'] as $clib_gi => $clib_g ) : ?>
			<?php
			$clib_total = 0;
			foreach ( $clib_g['items'] as $clib_r ) {
				$clib_total += max( 1, (int) $clib_r->copies );
			}
			$clib_too_many = count( $clib_g['items'] ) > CLIB_Tools::MAX_MERGE;
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="clib-dupe-group" data-clib-confirm="merge">
				<?php wp_nonce_field( 'clib_merge_items' ); ?>
				<input type="hidden" name="action" value="clib_merge_items">
				<input type="hidden" name="close" value="<?php echo $clib_close ? '1' : ''; ?>">
				<input type="hidden" name="media_type_id" value="<?php echo esc_attr( $clib_mt ); ?>">
				<h3><?php echo esc_html( $clib_g['items'][0]->title ); ?> <span class="description">&middot; <?php echo esc_html( $clib_g['media_type'] ); ?></span></h3>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Keep', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Merge', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Item ID', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Title', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Creator/Author', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'ISBN / Barcode', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Location', 'church-library' ); ?></th>
						<th><?php esc_html_e( 'Copies', 'church-library' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $clib_g['items'] as $clib_n => $clib_r ) : ?>
						<?php $clib_rid = (int) $clib_r->id; ?>
						<tr>
							<td><input type="radio" name="keep" value="<?php echo esc_attr( $clib_rid ); ?>" <?php checked( 0 === $clib_n ); ?> required aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item id */ __( 'Keep %s', 'church-library' ), $clib_r->item_code ) ); ?>"></td>
							<td><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $clib_rid ); ?>" <?php checked( $clib_n < CLIB_Tools::MAX_MERGE ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item id */ __( 'Merge %s', 'church-library' ), $clib_r->item_code ) ); ?>"></td>
							<td><a href="<?php echo esc_url( CLIB_Admin::url( 'clib-catalog', array( 'view' => $clib_rid ) ) ); ?>"><code><?php echo esc_html( $clib_r->item_code ); ?></code></a></td>
							<td><?php echo esc_html( $clib_r->title ); ?></td>
							<td><?php echo esc_html( $clib_r->creator ); ?></td>
							<td><?php echo esc_html( trim( $clib_r->identifier . ( '' !== (string) $clib_r->barcode ? ' / ' . $clib_r->barcode : '' ), ' /' ) ); ?></td>
							<td><?php echo esc_html( trim( $clib_r->church_label . ' ' . $clib_r->room . ' ' . $clib_r->location_code ) ); ?></td>
							<td><?php echo esc_html( (int) $clib_r->copies ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<div class="clib-danger">
					<p>
						<?php
						/* translators: %d: total copies */
						echo esc_html( sprintf( __( 'Merging all ticked records gives the kept record a quantity of up to %d copies (the sum of every merged record). Empty fields on the kept record are filled from the others, loan history moves to it, the merged Item IDs and barcodes are noted in its inventory notes, and the other records are deleted.', 'church-library' ), $clib_total ) );
						if ( $clib_too_many ) {
							echo ' ';
							/* translators: %d: maximum */
							echo esc_html( sprintf( __( 'At most %d records can be merged at a time.', 'church-library' ), CLIB_Tools::MAX_MERGE ) );
						}
						?>
					</p>
					<p><label><input type="checkbox" name="confirm_merge" value="1" required> <?php esc_html_e( 'These are the same item. Merge the ticked records into the one I chose to keep.', 'church-library' ); ?></label></p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Merge records', 'church-library' ); ?></button></p>
				</div>
			</form>
		<?php endforeach; ?>
	<?php endif; ?>
<?php endif; ?>
