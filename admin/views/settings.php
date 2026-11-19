<?php
/**
 * Library - Settings (general settings + role permission matrix).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$clib_s      = CLIB_Settings::all();
$clib_caps   = CLIB_Perms::caps();
$clib_matrix = CLIB_Perms::matrix();
$clib_roles  = wp_roles()->get_names();
$clib_page   = $clib_s['catalog_page_id'] ? get_post( (int) $clib_s['catalog_page_id'] ) : null;
?>
<h1><?php esc_html_e( 'Library - Settings', 'church-library' ); ?></h1>

<div class="clib-disclaimer">
	<h2><?php esc_html_e( 'Disclaimer', 'church-library' ); ?></h2>
	<p><strong><?php esc_html_e( 'You run this plugin at your own risk. The author accepts no responsibility or liability for any loss, damage or compromise arising from its use.', 'church-library' ); ?></strong>
		<?php esc_html_e( 'Every effort has been made to write it safely, but new vulnerabilities appear in software of every kind every day and no website can be guaranteed secure. It is provided as is, without warranty of any kind, under the GNU General Public License v3 or later.', 'church-library' ); ?></p>
	<p class="description"><?php esc_html_e( 'Test on a staging site first, keep backups of your database and files, keep WordPress, PHP, your theme and plugins up to date, and serve the site over HTTPS. Deleting items and the delete-on-uninstall option erase library data permanently and cannot be undone. Full details are in the plugin\'s README.md.', 'church-library' ); ?></p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'clib_save_settings' ); ?>
	<input type="hidden" name="action" value="clib_save_settings">

	<h2><?php esc_html_e( 'General', 'church-library' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Public catalog', 'church-library' ); ?></th>
			<td>
				<label><input type="checkbox" name="clib[public_access]" value="1" <?php checked( $clib_s['public_access'], 1 ); ?>> <?php esc_html_e( 'Allow visitors who are not logged in to view the catalog', 'church-library' ); ?></label>
				<p class="description"><?php esc_html_e( 'When unticked, visitors must log in with a role that has "View catalog" below (Subscriber or higher by default).', 'church-library' ); ?></p>
				<?php if ( $clib_page && 'trash' !== $clib_page->post_status ) : ?>
					<p><?php esc_html_e( 'Catalog page:', 'church-library' ); ?> <a href="<?php echo esc_url( get_permalink( $clib_page ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_the_title( $clib_page ) ); ?></a>
					&middot; <?php esc_html_e( 'Or add the [church_library] shortcode to any page.', 'church-library' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Borrower names', 'church-library' ); ?></th>
			<td><label><input type="checkbox" name="clib[show_borrowers]" value="1" <?php checked( $clib_s['show_borrowers'], 1 ); ?>> <?php esc_html_e( 'Show "Checked Out By" names to logged-in view-only users (staff always see them; guests never do)', 'church-library' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><label for="clib-loan-days"><?php esc_html_e( 'Default loan period (days)', 'church-library' ); ?></label></th>
			<td><input type="number" id="clib-loan-days" name="clib[loan_days]" min="1" max="365" value="<?php echo esc_attr( $clib_s['loan_days'] ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th scope="row"><label for="clib-per-page"><?php esc_html_e( 'Items per page', 'church-library' ); ?></label></th>
			<td><input type="number" id="clib-per-page" name="clib[per_page]" min="5" max="200" value="<?php echo esc_attr( $clib_s['per_page'] ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Title / ISBN lookup', 'church-library' ); ?></th>
			<td><label><input type="checkbox" name="clib[lookup_enabled]" value="1" <?php checked( $clib_s['lookup_enabled'], 1 ); ?>> <?php esc_html_e( 'Allow staff to look up titles and covers online (Open Library). Titles typed in the lookup are sent to that service.', 'church-library' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Uninstall', 'church-library' ); ?></th>
			<td>
				<label><input type="checkbox" id="clib-uninstall-delete" name="clib[uninstall_delete]" value="1" <?php checked( $clib_s['uninstall_delete'], 1 ); ?>> <?php esc_html_e( 'Delete all library tables and settings when the plugin is deleted (cannot be undone)', 'church-library' ); ?></label>
				<?php if ( $clib_s['uninstall_delete'] ) : ?>
					<div class="clib-danger">
						<strong><?php esc_html_e( 'This is ON.', 'church-library' ); ?></strong>
						<?php esc_html_e( 'Deleting the plugin from the Plugins screen will permanently erase every item, loan, list and the audit log. Untick and save to keep your data if you uninstall.', 'church-library' ); ?>
					</div>
				<?php else : ?>
					<div class="clib-danger" id="clib-uninstall-confirm">
						<p><strong><?php esc_html_e( 'Warning:', 'church-library' ); ?></strong>
							<?php esc_html_e( 'with this on, deleting the plugin permanently erases the whole catalog, every loan record, all lists of values and the audit log. There is no undo: take a database backup first. You run this plugin at your own risk; the author accepts no responsibility for data loss.', 'church-library' ); ?></p>
						<p><label><input type="checkbox" name="clib_confirm_warning" value="1"> <?php esc_html_e( 'I understand this erases all library data when the plugin is deleted.', 'church-library' ); ?></label></p>
						<p><label>
							<?php
							/* translators: %s: confirmation word */
							printf( esc_html__( 'Type %s to confirm:', 'church-library' ), '<code>' . esc_html( CLIB_Settings::DELETE_PHRASE ) . '</code>' );
							?>
							<input type="text" name="clib_confirm_text" maxlength="20" autocomplete="off" spellcheck="false"></label></p>
					</div>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<h2><?php esc_html_e( 'Role permissions', 'church-library' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Administrators always have every permission. Settings, permissions and the audit log are limited to administrators.', 'church-library' ); ?></p>
	<table class="widefat striped clib-matrix">
		<thead><tr>
			<th><?php esc_html_e( 'Role', 'church-library' ); ?></th>
			<?php foreach ( $clib_caps as $clib_label ) : ?>
				<th><?php echo esc_html( $clib_label ); ?></th>
			<?php endforeach; ?>
		</tr></thead>
		<tbody>
		<?php foreach ( $clib_roles as $clib_role => $clib_name ) : ?>
			<?php $clib_is_admin = 'administrator' === $clib_role; ?>
			<tr>
				<th scope="row"><?php echo esc_html( translate_user_role( $clib_name ) ); ?></th>
				<?php foreach ( array_keys( $clib_caps ) as $clib_cap ) : ?>
					<td>
						<input type="checkbox"
							name="perm[<?php echo esc_attr( $clib_role ); ?>][<?php echo esc_attr( $clib_cap ); ?>]"
							value="1"
							aria-label="<?php echo esc_attr( translate_user_role( $clib_name ) . ': ' . $clib_caps[ $clib_cap ] ); ?>"
							<?php checked( ! empty( $clib_matrix[ $clib_role ][ $clib_cap ] ) ); ?>
							<?php disabled( $clib_is_admin ); ?>>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php submit_button( __( 'Save Settings', 'church-library' ) ); ?>
</form>
