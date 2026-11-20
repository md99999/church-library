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
$clib_pids   = get_option( CLIB_Pages::OPTION, array() );
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

	<p><?php esc_html_e( 'Dropdown values (churches, buildings, media types, classifications, conditions and more) are managed under', 'church-library' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=clib-lists' ) ); ?>"><?php esc_html_e( 'Library - List of Values', 'church-library' ); ?></a>.</p>

<h2><?php esc_html_e( 'General', 'church-library' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Public catalog', 'church-library' ); ?></th>
			<td>
				<label><input type="checkbox" name="clib[public_access]" value="1" <?php checked( $clib_s['public_access'], 1 ); ?>> <?php esc_html_e( 'Allow visitors who are not logged in to view the catalog', 'church-library' ); ?></label>
				<p class="description"><?php esc_html_e( 'When unticked, visitors must log in with a role that has "View catalog" below (Subscriber or higher by default).', 'church-library' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Borrower names', 'church-library' ); ?></th>
			<td><label><input type="checkbox" name="clib[show_borrowers]" value="1" <?php checked( $clib_s['show_borrowers'], 1 ); ?>> <?php esc_html_e( 'Show "Checked Out By" names to logged-in view-only users (staff always see them; guests never do)', 'church-library' ); ?></label></td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Page layout', 'church-library' ); ?></th>
			<td><label><input type="checkbox" name="clib[full_width]" value="1" <?php checked( $clib_s['full_width'], 1 ); ?>> <?php esc_html_e( 'Show the "Library - ..." pages full width, without the theme sidebar.', 'church-library' ); ?></label>
				<p class="description"><?php
				$clib_tpl = CLIB_Layout::theme_template();
				if ( '' !== $clib_tpl ) {
					/* translators: %s: page template file */
					echo esc_html( sprintf( __( 'Your theme has a full-width page template (%s); library pages that still use the default template are switched to it. A template you pick by hand in the page editor is never changed.', 'church-library' ), $clib_tpl ) );
				} else {
					esc_html_e( 'Your theme has no full-width page template, so the plugin hides sidebar widget areas and widens the content on library pages instead. If a sidebar still shows, pick a full-width template for the page in the page editor.', 'church-library' );
				}
				?></p>
			</td>
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
			<td>
				<label><input type="checkbox" name="clib[lookup_enabled]" value="1" <?php checked( $clib_s['lookup_enabled'], 1 ); ?>> <?php esc_html_e( 'Allow staff to look up titles, barcodes and covers online. Text typed in the lookup is sent to the sources ticked below.', 'church-library' ); ?></label>
				<fieldset class="clib-providers">
					<?php
					$clib_pdesc = array(
						'openlibrary' => __( 'Open Library: books, by title or ISBN. Free, no key.', 'church-library' ),
					'googlebooks' => __( 'Google Books: books, by title or ISBN, with covers. Free; works without a key at low volume, or add an optional API key below for a higher quota.', 'church-library' ),
					'tmdb'        => __( 'TMDB (The Movie Database): movies on DVD, Blu-ray or VHS, by title, with posters and director. Needs a free TMDB account and API Read Access Token (or v3 API key), entered below. This product uses the TMDB API but is not endorsed or certified by TMDB.', 'church-library' ),
						'musicbrainz' => __( 'MusicBrainz + Cover Art Archive: CDs, albums and recordings, by title or CD barcode, with album art. Free, no key; limited to one request per second.', 'church-library' ),
						'hymnary'     => __( 'Hymnary.org: hymn texts by title or first line, with authors and meter. Free, no key; no cover images.', 'church-library' ),
						'imslp'       => __( 'IMSLP (Petrucci Music Library): public-domain scores, by work title, with composer. Free, no key; no cover images.', 'church-library' ),
						'discogs'     => __( 'Discogs: CDs, records and other music releases, by title or barcode, with cover images. Needs a free Discogs account and either a personal access token or the consumer key and secret of a Discogs app. Review the Discogs API terms before turning this on.', 'church-library' ),
					);
					// Fixed, https-only links shown after a source's description.
					$clib_plinks = array(
					'googlebooks' => array(
						'https://developers.google.com/books/docs/v1/using#APIKey' => __( 'How to get a Google Books API key', 'church-library' ),
					),
					'tmdb'        => array(
						'https://www.themoviedb.org/settings/api' => __( 'Get your TMDB API Read Access Token', 'church-library' ),
						'https://www.themoviedb.org/api-terms-of-use' => __( 'TMDB API terms of use', 'church-library' ),
					),
						'discogs' => array(
							'https://www.discogs.com/settings/developers' => __( 'Get your Discogs personal access token', 'church-library' ),
							'https://www.discogs.com/developers' => __( 'Discogs API documentation and terms', 'church-library' ),
						),
					);
					foreach ( CLIB_Lookup::providers() as $clib_pid => $clib_p ) :
						?>
						<label><input type="checkbox" name="clib[lookup_providers][]" value="<?php echo esc_attr( $clib_pid ); ?>" <?php checked( in_array( $clib_pid, (array) $clib_s['lookup_providers'], true ) ); ?>>
							<?php echo esc_html( isset( $clib_pdesc[ $clib_pid ] ) ? $clib_pdesc[ $clib_pid ] : $clib_p->label() ); ?></label>
						<?php if ( isset( $clib_plinks[ $clib_pid ] ) ) : ?>
							<?php foreach ( $clib_plinks[ $clib_pid ] as $clib_lurl => $clib_ltext ) : ?>
								&middot; <a href="<?php echo esc_url( $clib_lurl, array( 'https' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $clib_ltext ); ?> <span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'church-library' ); ?></span>&#8599;</a>
							<?php endforeach; ?>
						<?php endif; ?>
						<br>
					<?php endforeach; ?>
					<label><?php esc_html_e( 'Discogs personal access token', 'church-library' ); ?>
						<input type="password" name="clib[discogs_token]" value="" maxlength="64" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['discogs_token'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label>
					<?php if ( $clib_s['discogs_token'] ) : ?>
						<label><input type="checkbox" name="clib[discogs_token_clear]" value="1"> <?php esc_html_e( 'Remove saved token', 'church-library' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Or, instead of a token, the consumer key and consumer secret of a Discogs app you registered (on the same Discogs developers page linked above). The API address is built in and cannot be changed.', 'church-library' ); ?></p>
					<label><?php esc_html_e( 'Discogs consumer key', 'church-library' ); ?>
						<input type="password" name="clib[discogs_key]" value="" maxlength="64" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['discogs_key'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label><br>
					<label><?php esc_html_e( 'Discogs consumer secret', 'church-library' ); ?>
						<input type="password" name="clib[discogs_secret]" value="" maxlength="64" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['discogs_secret'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label>
					<?php if ( $clib_s['discogs_key'] || $clib_s['discogs_secret'] ) : ?>
						<br><label><input type="checkbox" name="clib[discogs_app_clear]" value="1"> <?php esc_html_e( 'Remove saved key and secret', 'church-library' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'API keys are stored in the WordPress options table, sent only over https to the fixed address of their own service, and never shown on this page again. Leave a field blank to keep the saved value.', 'church-library' ); ?></p>
					<label><?php esc_html_e( 'Google Books API key (optional)', 'church-library' ); ?>
						<input type="password" name="clib[google_books_key]" value="" maxlength="64" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['google_books_key'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label>
					<?php if ( $clib_s['google_books_key'] ) : ?>
						<label><input type="checkbox" name="clib[google_books_key_clear]" value="1"> <?php esc_html_e( 'Remove saved key', 'church-library' ); ?></label>
					<?php endif; ?>
					<br><label><?php esc_html_e( 'TMDB API Read Access Token', 'church-library' ); ?>
						<input type="password" name="clib[tmdb_token]" value="" maxlength="1024" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['tmdb_token'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label><br>
					<label><?php esc_html_e( 'or TMDB API key (v3)', 'church-library' ); ?>
						<input type="password" name="clib[tmdb_key]" value="" maxlength="32" autocomplete="off" class="regular-text" placeholder="<?php echo $clib_s['tmdb_key'] ? esc_attr__( 'Saved (leave blank to keep)', 'church-library' ) : ''; ?>"></label>
					<?php if ( $clib_s['tmdb_token'] || $clib_s['tmdb_key'] ) : ?>
						<br><label><input type="checkbox" name="clib[tmdb_clear]" value="1"> <?php esc_html_e( 'Remove saved TMDB credentials', 'church-library' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'IMDb is not offered: it has no free official API, and reading its web pages automatically is against its terms.', 'church-library' ); ?></p>
				</fieldset>
			</td>
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

<h2><?php esc_html_e( 'Library pages', 'church-library' ); ?></h2>
<p class="description"><?php esc_html_e( 'Each library task has its own page on your site. Add the ones your team uses to a menu under Appearance. Each page checks permissions on the server before showing anything.', 'church-library' ); ?></p>
<table class="widefat striped clib-pages-table">
	<thead><tr>
		<th><?php esc_html_e( 'Page', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Shortcode', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Who can open it', 'church-library' ); ?></th>
		<th><?php esc_html_e( 'Status', 'church-library' ); ?></th>
	</tr></thead>
	<tbody>
	<?php foreach ( CLIB_Pages::PAGES as $clib_k => $clib_def ) : ?>
		<?php
		$clib_pid   = isset( $clib_pids[ $clib_k ] ) ? (int) $clib_pids[ $clib_k ] : 0;
		$clib_ok    = $clib_pid && 'publish' === get_post_status( $clib_pid );
		$clib_caps2 = CLIB_Perms::caps();
		$clib_who   = 'view' === $clib_def[4] ? ( $clib_s['public_access'] ? __( 'Everyone', 'church-library' ) : __( 'Logged-in users with View catalog', 'church-library' ) )
			: ( 'login' === $clib_def[4] ? __( 'Logged-in users with View catalog', 'church-library' ) : ( isset( $clib_caps2[ $clib_def[4] ] ) ? $clib_caps2[ $clib_def[4] ] : $clib_def[4] ) );
		?>
		<tr>
			<td><?php echo $clib_ok ? '<a href="' . esc_url( get_permalink( $clib_pid ) ) . '" target="_blank" rel="noopener">' . esc_html( $clib_def[0] ) . '</a>' : esc_html( $clib_def[0] ); ?></td>
			<td><code>[<?php echo esc_html( $clib_def[2] ); ?>]</code></td>
			<td><?php echo esc_html( $clib_who ); ?></td>
			<td><?php echo $clib_ok ? esc_html__( 'Published', 'church-library' ) : '<strong>' . esc_html__( 'Missing', 'church-library' ) . '</strong>'; ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'clib_setup_pages' ); ?>
	<input type="hidden" name="action" value="clib_setup_pages">
	<?php submit_button( __( 'Create / repair library pages', 'church-library' ), 'secondary' ); ?>
</form>
