<?php
/**
 * Installation health check (Library - Settings > Installation health).
 *
 * Plenty of people will take GitHub's "Download ZIP", or copy a working clone,
 * and upload that instead of a release zip. The plugin runs, but the folder is
 * named after the branch (so the next proper install becomes a second copy),
 * development files land in the web root, and a clone carries a .git folder
 * that some servers will serve to anyone. Documentation only helps the people
 * who read it, so the plugin checks itself and says so in wp-admin.
 *
 * Every check only reads. The local checks run when the settings page loads
 * (the file scan is cached for an hour); the checks that make web requests run
 * only when an administrator presses the button, behind a nonce and
 * manage_options (see CLIB_Admin::handle_health_check()). Results are plain
 * text and escaped where they are printed.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Health {

	/** The folder name a release zip unpacks to, and the one updates use. */
	const SLUG = 'church-library';

	/** The plugin's main file, used to recognise other copies. */
	const MAIN_FILE = 'church-library.php';

	const MIN_PHP   = '7.4';
	const MIN_WP    = '6.0';
	const MIN_MYSQL = '5.7';
	const MIN_MARIA = '10.3';

	/** Transient names. */
	const SCAN_CACHE = 'clib_health_scan';
	const NET_PREFIX = 'clib_health_net_';

	/** Limits on the file scan, so a stray node_modules cannot make it slow. */
	const SCAN_MAX_DEPTH   = 8;
	const SCAN_MAX_ENTRIES = 200;

	/**
	 * Every path a release of this plugin contains, directories with a trailing
	 * slash. tools/build-zip.php compares what it packs against this list and
	 * refuses to build if they disagree, so adding a file to the plugin means
	 * adding it here.
	 */
	const MANIFEST = array(
		'.htaccess', 'LICENSE', 'README.md', 'SECURITY.md', 'admin/', 'admin/.htaccess',
		'admin/class-clib-admin.php', 'admin/index.php', 'admin/views/', 'admin/views/.htaccess',
		'admin/views/audit.php', 'admin/views/bulk-confirm.php', 'admin/views/catalog.php',
		'admin/views/circulation.php', 'admin/views/import.php', 'admin/views/index.php',
		'admin/views/item-edit.php', 'admin/views/item-view.php', 'admin/views/lists.php',
		'admin/views/partials/', 'admin/views/partials/.htaccess',
		'admin/views/partials/checkout-fields.php', 'admin/views/partials/health.php',
		'admin/views/partials/index.php', 'admin/views/partials/item-actions.php',
		'admin/views/partials/secret-field.php', 'admin/views/reports.php',
		'admin/views/settings.php', 'admin/views/tools.php', 'assets/', 'assets/.htaccess',
		'assets/admin.css', 'assets/admin.js', 'assets/index.php', 'assets/public.css',
		'church-library.php', 'includes/', 'includes/.htaccess',
		'includes/class-clib-attribution.php', 'includes/class-clib-audit.php',
		'includes/class-clib-bulk.php', 'includes/class-clib-db.php',
		'includes/class-clib-export.php', 'includes/class-clib-form.php',
		'includes/class-clib-health.php', 'includes/class-clib-import.php',
		'includes/class-clib-items.php', 'includes/class-clib-layout.php',
		'includes/class-clib-loans.php', 'includes/class-clib-lookup-media.php',
		'includes/class-clib-lookup-music.php', 'includes/class-clib-lookup.php',
		'includes/class-clib-lov.php', 'includes/class-clib-match.php',
		'includes/class-clib-pages.php', 'includes/class-clib-perms.php',
		'includes/class-clib-privacy.php', 'includes/class-clib-reports.php',
		'includes/class-clib-settings.php', 'includes/class-clib-tools.php',
		'includes/class-clib-util.php', 'includes/index.php', 'includes/views/',
		'includes/views/.htaccess', 'includes/views/index.php', 'includes/views/item-form.php',
		'index.php', 'public/', 'public/.htaccess', 'public/class-clib-public.php',
		'public/index.php', 'public/views/', 'public/views/.htaccess', 'public/views/catalog.php',
		'public/views/checkin.php', 'public/views/checkout.php', 'public/views/howto.php',
		'public/views/index.php', 'public/views/manage.php', 'public/views/myloans.php',
		'public/views/partials/', 'public/views/partials/.htaccess',
		'public/views/partials/index.php', 'public/views/partials/item-actions.php',
		'public/views/reports.php', 'uninstall.php',
	);

	/** Release folders whose files the browser loads directly, so they do not get a deny-all .htaccess. */
	const PUBLIC_DIRS = array( 'assets/' );

	/* ---------------------------------------------------------------------
	 * Results.
	 * ------------------------------------------------------------------ */

	/**
	 * Build one result row. Every string is plain text; the view escapes it.
	 *
	 * @param string $label  What was checked.
	 * @param string $status ok | info | warning | error.
	 * @param string $detail What was found and what to do about it.
	 * @param array  $items  Optional list of paths or values, shown as code.
	 * @return array
	 */
	private static function row( $label, $status, $detail, array $items = array() ) {
		if ( ! in_array( $status, array( 'ok', 'info', 'warning', 'error' ), true ) ) {
			$status = 'info';
		}
		return array(
			'label'  => (string) $label,
			'status' => $status,
			'detail' => (string) $detail,
			'items'  => array_map( 'strval', array_values( $items ) ),
		);
	}

	/**
	 * All local checks, grouped for display. Reads only.
	 *
	 * @return array section title => list of rows.
	 */
	public static function local_checks() {
		return array(
			__( 'How the plugin is installed', 'church-library' ) => array(
				self::check_folder(),
				self::check_duplicates(),
				self::check_git(),
				self::check_unexpected(),
				self::check_guards(),
			),
			__( 'Server and WordPress', 'church-library' ) => array(
				self::check_php(),
				self::check_wp(),
				self::check_db_server(),
				self::check_https(),
				self::check_debug_display(),
			),
			__( 'Library data', 'church-library' ) => array(
				self::check_tables(),
				self::check_db_version(),
				self::check_uploads(),
				self::check_lookup(),
			),
		);
	}

	/**
	 * Count rows by status, for the summary line.
	 *
	 * @param array $sections section => rows, as from local_checks().
	 * @return array status => count.
	 */
	public static function summary( array $sections ) {
		$count = array(
			'ok'      => 0,
			'info'    => 0,
			'warning' => 0,
			'error'   => 0,
		);
		foreach ( $sections as $rows ) {
			foreach ( (array) $rows as $r ) {
				if ( isset( $r['status'], $count[ $r['status'] ] ) ) {
					$count[ $r['status'] ]++;
				}
			}
		}
		return $count;
	}

	/** The folder this copy lives in, e.g. "church-library-main". */
	public static function folder() {
		return basename( untrailingslashit( CLIB_DIR ) );
	}

	/* ---------------------------------------------------------------------
	 * How the plugin is installed.
	 * ------------------------------------------------------------------ */

	private static function check_folder() {
		$label  = __( 'Plugin folder name', 'church-library' );
		$folder = self::folder();
		if ( self::SLUG === $folder ) {
			/* translators: %s: folder name */
			return self::row( $label, 'ok', sprintf( __( 'Installed in wp-content/plugins/%s, the folder updates expect.', 'church-library' ), $folder ) );
		}
		return self::row(
			$label,
			'warning',
			sprintf(
				/* translators: 1: folder name, 2: expected folder name */
				__( 'Installed as wp-content/plugins/%1$s, which is what GitHub\'s "Download ZIP" produces (it names the folder after the branch). It works, but WordPress identifies a plugin by its folder, so installing a release zip later adds a second copy instead of updating this one. Deactivate the plugin, rename the folder to %2$s over SFTP or your host\'s file manager, then activate it again. Library data is in the database and is not affected.', 'church-library' ),
				$folder,
				self::SLUG
			)
		);
	}

	private static function check_duplicates() {
		$label  = __( 'Other copies of the plugin', 'church-library' );
		$others = self::other_copies();
		if ( ! $others ) {
			return self::row( $label, 'ok', __( 'This is the only copy in wp-content/plugins.', 'church-library' ) );
		}
		return self::row(
			$label,
			'error',
			sprintf(
				/* translators: %s: folder name */
				__( 'Other folders in wp-content/plugins also hold Church Library. Every copy shares the same database tables, and WordPress treats them as separate plugins, so an update or deactivation can land on the wrong one. Keep the copy in %s and delete the others from the Plugins screen (deleting a copy does not touch library data unless "Delete all library data on uninstall" is ticked, so check that setting first).', 'church-library' ),
				self::SLUG
			),
			$others
		);
	}

	private static function check_git() {
		$label = __( 'Repository files (.git)', 'church-library' );
		if ( ! self::has_git() ) {
			return self::row( $label, 'ok', __( 'No .git folder in the plugin folder.', 'church-library' ) );
		}
		return self::row(
			$label,
			'warning',
			__( 'This copy was installed from a git clone, so the plugin folder contains .git, the project\'s entire history. The plugin\'s .htaccess blocks it on Apache, but nginx and some hosts ignore .htaccess. Press "Run web checks" below to test whether it can be downloaded from this site. The fix is to install a release zip, or build one as README.md describes, instead of a clone. Deleting the .git folder by hand also works and does not affect the library.', 'church-library' )
		);
	}

	private static function check_unexpected() {
		$label = __( 'Files that are not part of this version', 'church-library' );
		$scan  = self::scan();
		if ( ! empty( $scan['failed'] ) ) {
			return self::row( $label, 'info', __( 'The plugin folder could not be read, so this was not checked.', 'church-library' ) );
		}
		if ( ! $scan['unexpected'] ) {
			return self::row( $label, 'ok', __( 'The plugin folder holds exactly the files this version ships.', 'church-library' ) );
		}
		$shown = array_slice( $scan['unexpected'], 0, 25 );
		$more  = count( $scan['unexpected'] ) - count( $shown );
		if ( $more > 0 ) {
			/* translators: %d: number of further entries */
			$shown[] = sprintf( __( '...and %d more', 'church-library' ), $more );
		}
		return self::row(
			$label,
			'warning',
			sprintf(
				/* translators: %s: plugin version */
				__( 'These are in the plugin folder but are not part of Church Library %s. Usually the repository was uploaded instead of a built zip, or an older version was copied over rather than replaced. It can also mean something was put there that should not be, which is worth a look either way. The plugin never loads them; remove them over SFTP or your host\'s file manager.', 'church-library' ),
				CLIB_VERSION
			),
			$shown
		);
	}

	private static function check_guards() {
		$label = __( 'Direct-access protection files', 'church-library' );
		$scan  = self::scan();
		if ( ! empty( $scan['failed'] ) ) {
			return self::row( $label, 'info', __( 'The plugin folder could not be read, so this was not checked.', 'church-library' ) );
		}
		$problems = array_merge( $scan['missing_index'], $scan['missing_htaccess'], $scan['unguarded'], $scan['missing'] );
		if ( ! $problems ) {
			return self::row( $label, 'ok', __( 'Every folder has its index.php (and .htaccess where it should have one), and every PHP file stops unless WordPress loaded it.', 'church-library' ) );
		}
		return self::row(
			$label,
			'error',
			__( 'Some protection files are missing or changed: folders without index.php can be listed, folders without .htaccess are not blocked on Apache, and a PHP file without its ABSPATH check can be run directly. Reinstall the plugin from a release zip to restore them.', 'church-library' ),
			array_slice( $problems, 0, 40 )
		);
	}

	/* ---------------------------------------------------------------------
	 * Server and WordPress.
	 * ------------------------------------------------------------------ */

	private static function check_php() {
		$label = __( 'PHP version', 'church-library' );
		if ( version_compare( PHP_VERSION, self::MIN_PHP, '<' ) ) {
			/* translators: 1: running version, 2: minimum version */
			return self::row( $label, 'error', sprintf( __( 'PHP %1$s is older than the %2$s this plugin needs. Ask your host to upgrade.', 'church-library' ), PHP_VERSION, self::MIN_PHP ) );
		}
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			/* translators: %s: running version */
			return self::row( $label, 'warning', sprintf( __( 'PHP %s works, but it no longer receives security fixes. Ask your host for PHP 8.2 or newer.', 'church-library' ), PHP_VERSION ) );
		}
		/* translators: %s: running version */
		return self::row( $label, 'ok', sprintf( __( 'PHP %s.', 'church-library' ), PHP_VERSION ) );
	}

	private static function check_wp() {
		$label = __( 'WordPress version', 'church-library' );
		$wp    = get_bloginfo( 'version' );
		if ( version_compare( $wp, self::MIN_WP, '<' ) ) {
			/* translators: 1: running version, 2: minimum version */
			return self::row( $label, 'error', sprintf( __( 'WordPress %1$s is older than the %2$s this plugin needs.', 'church-library' ), $wp, self::MIN_WP ) );
		}
		/* translators: %s: running version */
		return self::row( $label, 'ok', sprintf( __( 'WordPress %s. Keep it updated.', 'church-library' ), $wp ) );
	}

	private static function check_db_server() {
		global $wpdb;
		$label = __( 'Database server', 'church-library' );
		$raw   = (string) $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only, no input.
		if ( '' === $raw ) {
			return self::row( $label, 'info', __( 'The database version could not be read.', 'church-library' ) );
		}
		$is_maria = false !== stripos( $raw, 'mariadb' );
		$version  = preg_match( '/^(\d+\.\d+(?:\.\d+)?)/', $raw, $m ) ? $m[1] : '0';
		$min      = $is_maria ? self::MIN_MARIA : self::MIN_MYSQL;
		$name     = $is_maria ? 'MariaDB' : 'MySQL';
		if ( version_compare( $version, $min, '<' ) ) {
			/* translators: 1: server name, 2: version, 3: minimum version */
			return self::row( $label, 'warning', sprintf( __( '%1$s %2$s is older than the %3$s this plugin is tested with.', 'church-library' ), $name, $version, $min ) );
		}
		/* translators: 1: server name, 2: version */
		return self::row( $label, 'ok', sprintf( __( '%1$s %2$s.', 'church-library' ), $name, $version ) );
	}

	private static function check_https() {
		$label = __( 'HTTPS', 'church-library' );
		$home  = wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		$admin = wp_parse_url( admin_url( '/' ), PHP_URL_SCHEME );
		if ( 'https' === $home && 'https' === $admin ) {
			return self::row( $label, 'ok', __( 'The site and the dashboard use https.', 'church-library' ) );
		}
		return self::row( $label, 'warning', __( 'The site address or dashboard does not use https, so logins, borrower details and API keys can be read in transit. Ask your host for a certificate (most offer a free one) and change both addresses under Settings > General to https.', 'church-library' ) );
	}

	private static function check_debug_display() {
		$label = __( 'PHP error display', 'church-library' );
		$debug = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$shown = $debug && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		if ( $shown ) {
			return self::row( $label, 'warning', __( 'WP_DEBUG is on and errors are shown on the page, which can reveal file paths and other details to visitors. On a live site set WP_DEBUG_DISPLAY to false in wp-config.php (or turn WP_DEBUG off).', 'church-library' ) );
		}
		return self::row( $label, 'ok', __( 'PHP errors are not shown to visitors.', 'church-library' ) );
	}

	/* ---------------------------------------------------------------------
	 * Library data.
	 * ------------------------------------------------------------------ */

	private static function check_tables() {
		global $wpdb;
		$label   = __( 'Database tables', 'church-library' );
		$missing = array();
		$engines = array();
		foreach ( array( 'items', 'loans', 'lov', 'sequences', 'audit' ) as $t ) {
			$table = CLIB_DB::table( $t ); // Fixed whitelist inside CLIB_DB::table().
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $found !== $table ) {
				$missing[] = $table;
				continue;
			}
			$engine = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( is_string( $engine ) && '' !== $engine && 'innodb' !== strtolower( $engine ) ) {
				$engines[] = $table . ' (' . $engine . ')';
			}
		}
		if ( $missing ) {
			return self::row( $label, 'error', __( 'These tables are missing. Deactivate and activate the plugin to create them; existing data is kept.', 'church-library' ), $missing );
		}
		if ( $engines ) {
			return self::row( $label, 'warning', __( 'These tables do not use InnoDB, so check-outs, merges and deletes cannot run in transactions or lock rows. Ask your host to convert them to InnoDB.', 'church-library' ), $engines );
		}
		return self::row( $label, 'ok', __( 'All five library tables exist and use InnoDB.', 'church-library' ) );
	}

	private static function check_db_version() {
		$label = __( 'Database schema version', 'church-library' );
		$have  = (string) get_option( 'clib_db_version', '' );
		if ( CLIB_DB_VERSION === $have ) {
			/* translators: %s: schema version */
			return self::row( $label, 'ok', sprintf( __( 'Version %s, current.', 'church-library' ), $have ) );
		}
		return self::row(
			$label,
			'warning',
			sprintf(
				/* translators: 1: stored version, 2: expected version */
				__( 'The database is at version %1$s but this release expects %2$s. The upgrade runs on the next page load; if this stays, deactivate and activate the plugin.', 'church-library' ),
				'' === $have ? '-' : $have,
				CLIB_DB_VERSION
			)
		);
	}

	private static function check_uploads() {
		$label   = __( 'Image upload folder', 'church-library' );
		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return self::row( $label, 'error', __( 'WordPress reports a problem with the uploads folder, so item images cannot be saved. Check Settings > Media and the folder permissions.', 'church-library' ) );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'church-library';
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_is_writable( $uploads['basedir'] ) ) {
				return self::row( $label, 'error', __( 'wp-content/uploads is not writable, so item images cannot be saved. Ask your host to fix the folder permissions.', 'church-library' ) );
			}
			return self::row( $label, 'info', __( 'uploads/church-library has not been created yet. It is created, with its protection files, when the first image is saved.', 'church-library' ) );
		}
		if ( ! wp_is_writable( $dir ) ) {
			return self::row( $label, 'error', __( 'uploads/church-library is not writable, so item images cannot be saved. Ask your host to fix the folder permissions.', 'church-library' ) );
		}
		$missing = array();
		foreach ( array( 'index.php', '.htaccess' ) as $f ) {
			if ( ! is_file( $dir . '/' . $f ) ) {
				$missing[] = 'uploads/church-library/' . $f;
			}
		}
		if ( $missing ) {
			return self::row( $label, 'warning', __( 'The upload folder is missing protection files. They are put back the next time an image is saved, or when the plugin is reactivated.', 'church-library' ), $missing );
		}
		return self::row( $label, 'ok', __( 'uploads/church-library is writable and has its index.php and no-script .htaccess.', 'church-library' ) );
	}

	private static function check_lookup() {
		$label = __( 'Title / ISBN lookup', 'church-library' );
		$s     = CLIB_Settings::all();
		if ( empty( $s['lookup_enabled'] ) ) {
			return self::row( $label, 'info', __( 'The online lookup is turned off.', 'church-library' ) );
		}
		$providers = (array) $s['lookup_providers'];
		if ( ! $providers ) {
			return self::row( $label, 'warning', __( 'The lookup is on but no source is ticked, so it can only search your own catalog.', 'church-library' ) );
		}
		$notes = array();
		$level = 'ok';
		if ( in_array( 'discogs', $providers, true ) && '' === (string) $s['discogs_token'] && ( '' === (string) $s['discogs_key'] || '' === (string) $s['discogs_secret'] ) ) {
			$notes[] = __( 'Discogs is ticked but has no personal access token or consumer key and secret, so it is skipped.', 'church-library' );
			$level   = 'warning';
		}
		if ( '' !== (string) $s['google_books_key'] && ! CLIB_Settings::valid_google_key( $s['google_books_key'] ) ) {
			$notes[] = __( 'The saved Google Books API key is not in the expected format. Enter it again.', 'church-library' );
			$level   = 'warning';
		}
		if ( ! $notes ) {
			/* translators: %d: number of sources */
			$notes[] = sprintf( _n( '%d source is ticked and configured.', '%d sources are ticked and configured.', count( $providers ), 'church-library' ), count( $providers ) );
		}
		return self::row( $label, $level, implode( ' ', $notes ) );
	}

	/* ---------------------------------------------------------------------
	 * Web checks (only when the administrator presses the button).
	 * ------------------------------------------------------------------ */

	/**
	 * Requests that leave the server. Called only from the nonce- and
	 * capability-checked admin-post handler; every URL is built here, none
	 * comes from the request.
	 *
	 * @return array list of rows.
	 */
	public static function web_checks() {
		$rows = array( self::check_direct_access() );
		if ( self::has_git() ) {
			$rows[] = self::check_git_served();
		}
		$rows[] = self::check_outbound();
		return $rows;
	}

	/** Is README.md in the plugin folder served? It should be refused by the .htaccess (or nginx rules). */
	private static function check_direct_access() {
		$label = __( 'Direct-access rules applied by the web server', 'church-library' );
		$res   = self::self_request( plugins_url( 'README.md', CLIB_FILE ) );
		if ( null === $res ) {
			return self::row( $label, 'info', __( 'This site could not be reached from the server itself (some hosts block that), so this could not be tested.', 'church-library' ) );
		}
		if ( 200 === $res['code'] && 0 === strpos( ltrim( $res['body'] ), '# Church Library' ) ) {
			return self::row( $label, 'warning', __( 'The plugin\'s README.md can be downloaded from this site, so the plugin\'s .htaccess rules are not being applied (nginx ignores .htaccess, and some Apache hosts disable it). Every PHP file still refuses to run on its own, but other files in the folder can be read. Add the nginx rules from README.md, or ask your host to allow .htaccess overrides.', 'church-library' ) );
		}
		if ( in_array( $res['code'], array( 401, 403, 404, 410 ), true ) ) {
			return self::row( $label, 'ok', __( 'Requests for non-asset files in the plugin folder are refused.', 'church-library' ) );
		}
		/* translators: %d: HTTP status code */
		return self::row( $label, 'info', sprintf( __( 'The test request got an unexpected answer (HTTP %d), so the result is unclear.', 'church-library' ), $res['code'] ) );
	}

	/** Is .git/HEAD served? Only asked when a .git folder exists. */
	private static function check_git_served() {
		$label = __( 'Repository history (.git) readable from the web', 'church-library' );
		$res   = self::self_request( plugins_url( '.git/HEAD', CLIB_FILE ) );
		if ( null === $res ) {
			return self::row( $label, 'info', __( 'This site could not be reached from the server itself, so this could not be tested. Remove the .git folder to be safe.', 'church-library' ) );
		}
		if ( 200 === $res['code'] && 0 === strpos( $res['body'], 'ref:' ) ) {
			return self::row(
				$label,
				'error',
				sprintf(
					/* translators: %s: plugin folder name */
					__( 'The .git folder can be downloaded from this site right now, which exposes the project history and anything ever committed to it. Delete the .git folder from wp-content/plugins/%s now, then install from a release zip.', 'church-library' ),
					self::folder()
				)
			);
		}
		if ( in_array( $res['code'], array( 401, 403, 404, 410 ), true ) ) {
			return self::row( $label, 'warning', __( 'A request for .git was refused, so it is not served today. A server change could undo that, so removing the .git folder is still recommended.', 'church-library' ) );
		}
		/* translators: %d: HTTP status code */
		return self::row( $label, 'info', sprintf( __( 'The test request got an unexpected answer (HTTP %d). Remove the .git folder to be safe.', 'church-library' ), $res['code'] ) );
	}

	/** Can the server make outgoing https requests (needed by the lookup)? Asks api.wordpress.org, which WordPress already contacts. */
	private static function check_outbound() {
		$label = __( 'Outgoing https requests', 'church-library' );
		$res   = wp_safe_remote_head(
			'https://api.wordpress.org/',
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'user-agent'  => 'ChurchLibrary/' . CLIB_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			CLIB_Audit::log_error( 'health', 'Outgoing https test request failed.', 'settings' );
			return self::row( $label, 'warning', __( 'The server could not make an https request to api.wordpress.org, so the online title lookup and WordPress updates will fail. Ask your host whether outgoing connections or the CA certificate bundle are blocked.', 'church-library' ) );
		}
		return self::row( $label, 'ok', __( 'The server can make outgoing https requests (tested against api.wordpress.org).', 'church-library' ) );
	}

	/**
	 * GET a URL on this site. The URL is always built by this class from
	 * plugins_url(), never taken from input. Small response, short timeout.
	 *
	 * @param string $url URL on this site.
	 * @return array|null [ code => int, body => string ] or null when unreachable.
	 */
	private static function self_request( $url ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 3, // Follow http -> https so a redirect does not look like a refusal.
				'limit_response_size' => 2048,
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ), // WordPress core's own setting for loopback requests.
				'user-agent'          => 'ChurchLibrary/' . CLIB_VERSION . ' health-check',
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		return array(
			'code' => (int) wp_remote_retrieve_response_code( $res ),
			'body' => (string) wp_remote_retrieve_body( $res ),
		);
	}

	/* ---------------------------------------------------------------------
	 * File system.
	 * ------------------------------------------------------------------ */

	private static function has_git() {
		return file_exists( CLIB_DIR . '.git' );
	}

	/** Other folders in wp-content/plugins holding this plugin's main file. */
	private static function other_copies() {
		$dir     = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : WP_CONTENT_DIR . '/plugins';
		$here    = self::folder();
		$found   = array();
		$entries = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( ! is_array( $entries ) ) {
			return $found;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry || $entry === $here ) {
				continue;
			}
			$main = $dir . '/' . $entry . '/' . self::MAIN_FILE;
			if ( is_dir( $dir . '/' . $entry ) && is_file( $main ) ) {
				$head = (string) file_get_contents( $main, false, null, 0, 2048 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, first 2 KB only.
				if ( preg_match( '/^\s*\*\s*Plugin Name:\s*Church Library\s*$/mi', $head ) ) {
					$found[] = $entry;
				}
			}
		}
		return $found;
	}

	/**
	 * Compare the plugin folder with MANIFEST. Cached for an hour against this
	 * version because the settings page and admin notice use it.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array unexpected, missing, missing_index, missing_htaccess, unguarded, failed.
	 */
	public static function scan( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::SCAN_CACHE );
			if ( is_array( $cached ) && isset( $cached['version'] ) && CLIB_VERSION === $cached['version'] ) {
				return $cached;
			}
		}
		$out  = array(
			'version'          => CLIB_VERSION,
			'unexpected'       => array(),
			'missing'          => array(),
			'missing_index'    => array(),
			'missing_htaccess' => array(),
			'unguarded'        => array(),
			'failed'           => false,
		);
		$root = realpath( untrailingslashit( CLIB_DIR ) );
		if ( false === $root || ! is_dir( $root ) ) {
			$out['failed'] = true;
			return $out;
		}
		$expected = array_flip( self::MANIFEST );
		self::walk( $root, '', $expected, $out['unexpected'], 0 );
		sort( $out['unexpected'] );

		foreach ( self::MANIFEST as $rel ) {
			$path = $root . '/' . rtrim( $rel, '/' );
			if ( '/' === substr( $rel, -1 ) ) {
				if ( ! is_dir( $path ) ) {
					/* translators: %s: folder path */
					$out['missing'][] = sprintf( __( '%s (folder missing)', 'church-library' ), $rel );
					continue;
				}
				if ( ! is_file( $path . '/index.php' ) ) {
					/* translators: %s: folder path */
					$out['missing_index'][] = sprintf( __( '%s (no index.php)', 'church-library' ), $rel );
				}
				if ( ! in_array( $rel, self::PUBLIC_DIRS, true ) && ! is_file( $path . '/.htaccess' ) ) {
					/* translators: %s: folder path */
					$out['missing_htaccess'][] = sprintf( __( '%s (no .htaccess)', 'church-library' ), $rel );
				}
				continue;
			}
			if ( ! is_file( $path ) ) {
				/* translators: %s: file path */
				$out['missing'][] = sprintf( __( '%s (file missing)', 'church-library' ), $rel );
				continue;
			}
			if ( '.php' === substr( $rel, -4 ) && 'index.php' !== basename( $rel ) && ! self::php_is_guarded( $path ) ) {
				/* translators: %s: file path */
				$out['unguarded'][] = sprintf( __( '%s (no direct-access check)', 'church-library' ), $rel );
			}
		}
		if ( ! is_file( $root . '/.htaccess' ) ) {
			array_unshift( $out['missing_htaccess'], __( '.htaccess in the plugin folder (missing)', 'church-library' ) );
		}
		set_transient( self::SCAN_CACHE, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * Collect paths not in the manifest. An unexpected folder is reported once,
	 * not walked. Links (symlinks and Windows junctions) are reported and never
	 * followed, so the scan cannot leave the plugin folder or loop.
	 */
	private static function walk( $dir, $prefix, array $expected, array &$found, $depth ) {
		if ( $depth > self::SCAN_MAX_DEPTH || count( $found ) >= self::SCAN_MAX_ENTRIES ) {
			return;
		}
		$entries = scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( count( $found ) >= self::SCAN_MAX_ENTRIES ) {
				return;
			}
			if ( '' === $prefix && '.git' === $entry ) {
				continue; // check_git() reports it, with more to say.
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			$rel  = $prefix . $entry;
			$real = realpath( $path );
			if ( is_link( $path ) || false === $real || $real !== $path ) {
				$found[] = $rel . ( is_dir( $path ) ? '/' : '' ) . ' (link)';
				continue;
			}
			if ( is_dir( $real ) ) {
				if ( ! isset( $expected[ $rel . '/' ] ) ) {
					$found[] = $rel . '/';
					continue;
				}
				self::walk( $real, $rel . '/', $expected, $found, $depth + 1 );
			} elseif ( ! isset( $expected[ $rel ] ) ) {
				$found[] = $rel;
			}
		}
	}

	/** Does a PHP file stop unless WordPress loaded it? Reads the first 4 KB only. */
	private static function php_is_guarded( $path ) {
		$head = file_get_contents( $path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		if ( ! is_string( $head ) ) {
			return false;
		}
		return (bool) preg_match( "/if\s*\(\s*!\s*defined\(\s*'(ABSPATH|WP_UNINSTALL_PLUGIN)'\s*\)\s*\)\s*\{?\s*exit/", $head );
	}

	/* ---------------------------------------------------------------------
	 * Admin notice.
	 * ------------------------------------------------------------------ */

	/**
	 * Warn administrators on the Plugins screen and library screens when the
	 * install itself is wrong (branch-named folder, second copy, .git folder),
	 * since those cause trouble at the next update.
	 */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( 'plugins' !== $id && false === strpos( $id, 'clib-' ) ) {
			return;
		}
		if ( false !== strpos( $id, 'clib-settings' ) ) {
			return; // The settings page shows the full health check.
		}
		$issues = array();
		foreach ( array( self::check_folder(), self::check_duplicates(), self::check_git() ) as $r ) {
			if ( in_array( $r['status'], array( 'warning', 'error' ), true ) ) {
				$issues[] = $r;
			}
		}
		if ( ! $issues ) {
			return;
		}
		$level = 'warning';
		foreach ( $issues as $r ) {
			if ( 'error' === $r['status'] ) {
				$level = 'error';
			}
		}
		echo '<div class="notice notice-' . esc_attr( $level ) . '"><p><strong>' . esc_html__( 'Church Library: this copy was not installed from a release zip.', 'church-library' ) . '</strong></p><ul style="list-style:disc;padding-left:20px">';
		foreach ( $issues as $r ) {
			echo '<li><strong>' . esc_html( $r['label'] ) . ':</strong> ' . esc_html( $r['detail'] ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=clib-settings#clib-health' ) ) . '">' . esc_html__( 'See the installation health check', 'church-library' ) . '</a></p></div>';
	}
}
