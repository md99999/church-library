<?php
/**
 * Admin menus, screens and form handlers.
 *
 * Every state-changing request goes through admin-post.php and is checked
 * for (1) a valid nonce bound to the action and (2) the matching capability
 * before any input is read. Views are loaded from a fixed whitelist.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Admin {

	/** Page slug => [ menu label, capability, view file ]. */
	public static function pages() {
		return array(
			'clib-catalog'     => array( __( 'Catalog', 'church-library' ), 'clib_view', 'catalog.php' ),
			'clib-item'        => array( __( 'Add Item', 'church-library' ), 'clib_manage_items', 'item-edit.php' ),
			'clib-circulation' => array( __( 'Check Out / In', 'church-library' ), 'clib_circulate', 'circulation.php' ),
			'clib-reports'     => array( __( 'Reports', 'church-library' ), 'clib_reports', 'reports.php' ),
			'clib-lists'       => array( __( 'List of Values', 'church-library' ), 'clib_manage_lists', 'lists.php' ),
			'clib-import'      => array( __( 'Import / Export', 'church-library' ), 'clib_import', 'import.php' ),
			'clib-settings'    => array( __( 'Settings', 'church-library' ), 'manage_options', 'settings.php' ),
			'clib-audit'       => array( __( 'Audit Log', 'church-library' ), 'manage_options', 'audit.php' ),
		);
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( 'CLIB_Perms', 'ensure_admin_caps' ) ); // Administrators always hold every library capability, including ones added in updates.

		$actions = array(
			'clib_save_item',
			'clib_delete_item',
			'clib_checkout',
			'clib_checkin',
			'clib_save_lov',
			'clib_delete_lov',
			'clib_save_settings',
			'clib_setup_pages',
			'clib_bulk_prepare',
			'clib_bulk_apply',
			'clib_bulk_cancel',
			'clib_export',
			'clib_import',
			'clib_export_catalog',
			'clib_music_template',
		);
		foreach ( $actions as $a ) {
			add_action( 'admin_post_' . $a, array( __CLASS__, 'handle_' . substr( $a, 5 ) ) );
		}
	}

	public static function menu() {
		$pages = self::pages();
		add_menu_page(
			'Library - Catalog',
			__( 'Library', 'church-library' ),
			'clib_view',
			'clib-catalog',
			array( __CLASS__, 'render' ),
			'dashicons-book-alt',
			26
		);
		foreach ( $pages as $slug => $p ) {
			add_submenu_page( 'clib-catalog', 'Library - ' . $p[0], $p[0], $p[1], $slug, array( __CLASS__, 'render' ) );
		}
	}

	public static function assets( $hook ) {
		$page = sanitize_key( CLIB_Util::get( 'page' ) );
		if ( ! array_key_exists( $page, self::pages() ) ) {
			return;
		}
		wp_enqueue_style( 'clib-admin', CLIB_URL . 'assets/admin.css', array(), CLIB_VERSION );
		wp_enqueue_script( 'clib-admin', CLIB_URL . 'assets/admin.js', array(), CLIB_VERSION, true );
		wp_localize_script(
			'clib-admin',
			'CLIB',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'lookupNonce'   => wp_create_nonce( 'clib_lookup' ),
				'lookupEnabled' => (bool) CLIB_Settings::get( 'lookup_enabled' ),
				'i18n'          => array(
					'confirmDelete' => __( 'Delete this permanently? This cannot be undone.', 'church-library' ),
					'searching'     => __( 'Searching...', 'church-library' ),
					'noResults'     => __( 'No matches found. Please enter the details manually.', 'church-library' ),
					'use'           => __( 'Use this', 'church-library' ),
					'inCatalog'     => __( 'Already in your catalog:', 'church-library' ),
					'error'         => __( 'Lookup failed.', 'church-library' ),
					'coverChosen'   => __( 'Cover image will be imported when you save.', 'church-library' ),
				),
			)
		);
	}

	/** Render the current page from the whitelist. */
	public static function render() {
		$page  = sanitize_key( CLIB_Util::get( 'page' ) );
		$pages = self::pages();
		if ( ! isset( $pages[ $page ] ) || ! current_user_can( $pages[ $page ][1] ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'church-library' ), 403 );
		}
		echo '<div class="wrap clib-wrap">';
		self::print_notices();
		include CLIB_DIR . 'admin/views/' . $pages[ $page ][2]; // Fixed whitelist: no user input reaches the path.
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Notices (stored per user, never reflected from the URL).
	 * ------------------------------------------------------------------ */

	public static function notice( $message, $type = 'success' ) {
		CLIB_Form::notice( $message, $type );
	}

	public static function notice_error( WP_Error $err ) {
		CLIB_Form::notice_error( $err );
	}

	private static function print_notices() {
		foreach ( CLIB_Form::take_notices() as $n ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $n['type'] ), esc_html( $n['msg'] ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers.
	 * ------------------------------------------------------------------ */

	public static function url( $page, array $args = array() ) {
		if ( ! array_key_exists( $page, self::pages() ) ) {
			$page = 'clib-catalog';
		}
		$args = array_map( 'rawurlencode', array_map( 'strval', array_merge( array( 'page' => $page ), $args ) ) );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function go( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/** Verify nonce + capability or die. */
	private static function guard( $nonce_action, $cap ) {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			wp_die( esc_html__( 'Invalid request.', 'church-library' ), 405 );
		}
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'church-library' ), 403 );
		}
	}

	/** Where to return after a circulation action (whitelisted). */
	private static function back_after_circulation( $item_id ) {
		$return = CLIB_Util::enum( CLIB_Util::post( 'return' ), array( 'view', 'circulation', 'catalog' ), 'circulation' );
		if ( 'view' === $return && $item_id ) {
			self::go( 'clib-catalog', array( 'view' => (int) $item_id ) );
		}
		self::go( 'catalog' === $return ? 'clib-catalog' : 'clib-circulation' );
	}

	/** <option> list for a LOV (see CLIB_Form). */
	public static function lov_options( $type, $selected, $placeholder = '', $active_only = true ) {
		return CLIB_Form::lov_options( $type, $selected, $placeholder, $active_only );
	}

	/** <option> list for a fixed label map (see CLIB_Form). */
	public static function map_options( array $map, $selected, $placeholder = '' ) {
		return CLIB_Form::map_options( $map, $selected, $placeholder );
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------ */

	public static function handle_save_item() {
		self::guard( 'clib_save_item', 'clib_manage_items' );
		$id  = CLIB_Util::int( CLIB_Util::post( 'id' ) );
		$res = CLIB_Items::save_from_request( $_POST, $id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() verified; every field is sanitized inside.
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
			self::go( 'clib-item', $id ? array( 'id' => $id ) : array( 'retry' => 1 ) );
		}
		foreach ( $res['notices'] as $n ) {
			self::notice( $n[1], $n[0] );
		}
		if ( ! $id && CLIB_Util::post( 'add_another' ) ) {
			self::go( 'clib-item' );
		}
		self::go( 'clib-catalog', array( 'view' => $res['id'] ) );
	}

	public static function handle_delete_item() {
		$id = CLIB_Util::int( CLIB_Util::post( 'id' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce is bound to this id below.
		self::guard( 'clib_delete_item_' . $id, 'clib_delete_items' );
		$res = CLIB_Items::delete( $id );
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
			self::go( 'clib-catalog', array( 'view' => $id ) );
		}
		self::notice( __( 'Item deleted.', 'church-library' ) );
		self::go( 'clib-catalog' );
	}

	public static function handle_checkout() {
		self::guard( 'clib_checkout', 'clib_circulate' );
		$item_id = CLIB_Util::int( CLIB_Util::post( 'item_id' ) );
		if ( ! $item_id ) {
			$found   = CLIB_Items::find_by_code( CLIB_Util::post( 'item_code' ) );
			$item_id = $found ? (int) $found->id : 0;
		}
		if ( ! $item_id ) {
			self::notice( __( 'No item matches that ID or barcode.', 'church-library' ), 'error' );
			self::back_after_circulation( 0 );
		}
		$res = CLIB_Loans::checkout( $item_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() verified; checkout() sanitizes each field.
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
		} else {
			$item = CLIB_Items::get( $item_id );
			/* translators: 1: item id 2: title */
			self::notice( sprintf( __( 'Checked out %1$s (%2$s).', 'church-library' ), $item->item_code, $item->title ) );
		}
		self::back_after_circulation( $item_id );
	}

	public static function handle_checkin() {
		self::guard( 'clib_checkin', 'clib_circulate' );
		$loan_id = CLIB_Util::int( CLIB_Util::post( 'loan_id' ) );
		$item_id = 0;
		if ( ! $loan_id ) {
			$found = CLIB_Items::find_by_code( CLIB_Util::post( 'item_code' ) );
			if ( ! $found ) {
				self::notice( __( 'No item matches that ID or barcode.', 'church-library' ), 'error' );
				self::back_after_circulation( 0 );
			}
			$item_id = (int) $found->id;
			$active  = CLIB_Loans::active_for_item( $item_id );
			if ( 1 === count( $active ) ) {
				$loan_id = (int) $active[0]->id;
			} elseif ( ! $active ) {
				self::notice( __( 'That item is not checked out.', 'church-library' ), 'error' );
				self::back_after_circulation( $item_id );
			} else {
				self::notice( __( 'Several copies are out; choose which loan to check in below.', 'church-library' ), 'warning' );
				self::go( 'clib-catalog', array( 'view' => $item_id ) );
			}
		}
		$loan    = CLIB_Loans::get( $loan_id );
		$item_id = $loan ? (int) $loan->item_id : $item_id;
		$res     = CLIB_Loans::checkin( $loan_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() verified; checkin() sanitizes each field.
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
		} else {
			/* translators: %s: borrower */
			self::notice( sprintf( __( 'Checked in from %s.', 'church-library' ), $loan->borrower_name ) );
		}
		self::back_after_circulation( $item_id );
	}

	public static function handle_save_lov() {
		self::guard( 'clib_save_lov', 'clib_manage_lists' );
		$id   = CLIB_Util::int( CLIB_Util::post( 'id' ) );
		$type = sanitize_key( CLIB_Util::post( 'list_type' ) );
		if ( $id ) {
			$row = CLIB_LOV::get( $id );
			if ( ! $row ) {
				wp_die( esc_html__( 'Entry not found.', 'church-library' ), 404 );
			}
			$type = $row->list_type; // The stored type wins over anything posted.
		}
		if ( ! CLIB_LOV::valid_type( $type ) ) {
			wp_die( esc_html__( 'Invalid list.', 'church-library' ), 400 );
		}
		$raw              = array(
			'list_type'  => $type,
			'label'      => CLIB_Util::post( 'label' ),
			'code'       => CLIB_Util::post( 'code' ),
			'parent_id'  => CLIB_Util::post( 'parent_id' ),
			'is_music'   => CLIB_Util::post( 'is_music' ),
			'sort_order' => CLIB_Util::post( 'sort_order' ),
			'is_active'  => $id ? CLIB_Util::post( 'is_active' ) : 1,
		);
		$data             = CLIB_LOV::validate( $raw, $id );
		if ( is_wp_error( $data ) ) {
			self::notice_error( $data );
		} elseif ( $id ) {
			CLIB_LOV::update( $id, $data );
			CLIB_Audit::log( 'list_update', 'lov', $id, $type . ': ' . $data['label'] );
			self::notice( __( 'Saved.', 'church-library' ) );
		} else {
			$new = CLIB_LOV::insert( $data );
			CLIB_Audit::log( 'list_add', 'lov', $new, $type . ': ' . $data['label'] );
			self::notice( __( 'Added.', 'church-library' ) );
		}
		self::go( 'clib-lists', array( 'list' => $type ) );
	}

	public static function handle_delete_lov() {
		$id = CLIB_Util::int( CLIB_Util::post( 'id' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- nonce is bound to this id below.
		self::guard( 'clib_delete_lov_' . $id, 'clib_manage_lists' );
		$row = CLIB_LOV::get( $id );
		if ( ! $row ) {
			self::go( 'clib-lists' );
		}
		if ( CLIB_LOV::usage( $id, $row->list_type ) > 0 ) {
			self::notice( __( 'This entry is in use, so it was not deleted. Untick "Active" to hide it instead.', 'church-library' ), 'error' );
		} else {
			CLIB_LOV::delete( $id );
			CLIB_Audit::log( 'list_delete', 'lov', $id, $row->list_type . ': ' . $row->label );
			self::notice( __( 'Deleted.', 'church-library' ) );
		}
		self::go( 'clib-lists', array( 'list' => $row->list_type ) );
	}

	public static function handle_save_settings() {
		self::guard( 'clib_save_settings', 'manage_options' );
		$raw      = CLIB_Util::post( 'clib', array() );
		$settings = CLIB_Settings::sanitize( is_array( $raw ) ? $raw : array() );

		// Turning on "delete all data on uninstall" needs the warning box ticked
		// and the confirmation word typed exactly. Enforced here, not only in the browser.
		$was_on = (int) CLIB_Settings::get( 'uninstall_delete' );
		if ( $settings['uninstall_delete'] && ! $was_on ) {
			$typed = CLIB_Util::text( CLIB_Util::post( 'clib_confirm_text' ), 20 );
			if ( ! CLIB_Util::post( 'clib_confirm_warning' ) || ! hash_equals( CLIB_Settings::DELETE_PHRASE, $typed ) ) {
				$settings['uninstall_delete'] = 0;
				/* translators: %s: confirmation word */
				self::notice( sprintf( __( 'Delete-on-uninstall was NOT turned on: tick the warning box and type %s to confirm. Your other settings were saved.', 'church-library' ), CLIB_Settings::DELETE_PHRASE ), 'error' );
			} else {
				CLIB_Audit::log( 'uninstall_delete_on', 'settings', 0, 'Delete all data on uninstall enabled' );
				self::notice( __( 'Delete-on-uninstall is ON. Deleting the plugin will now permanently erase all library data.', 'church-library' ), 'warning' );
			}
		} elseif ( ! $settings['uninstall_delete'] && $was_on ) {
			CLIB_Audit::log( 'uninstall_delete_off', 'settings', 0, 'Delete all data on uninstall disabled' );
		}

		CLIB_Settings::save( $settings );
		if ( CLIB_Settings::$rejected ) {
			/* translators: %s: list of setting names */
			self::notice( sprintf( __( 'Not saved (only letters and digits are allowed; the previous value was kept): %s.', 'church-library' ), implode( ', ', CLIB_Settings::$rejected ) ), 'error' );
		}
		CLIB_Layout::apply_templates();
		$matrix = CLIB_Util::post( 'perm', array() );
		CLIB_Perms::save_matrix( is_array( $matrix ) ? $matrix : array() );
		CLIB_Audit::log( 'settings', 'settings', 0, 'public_access=' . $settings['public_access'] . ' uninstall_delete=' . $settings['uninstall_delete'] );
		self::notice( __( 'Settings saved.', 'church-library' ) );
		self::go( 'clib-settings' );
	}

	public static function handle_setup_pages() {
		self::guard( 'clib_setup_pages', 'manage_options' );
		list( $created, $renamed ) = CLIB_Pages::setup();
		CLIB_Audit::log( 'pages_setup', 'settings', 0, sprintf( '%d created, %d renamed', $created, $renamed ) );
		/* translators: 1: created 2: renamed */
		self::notice( sprintf( __( 'Library pages checked: %1$d created, %2$d renamed. Existing page content was not changed.', 'church-library' ), $created, $renamed ) );
		self::go( 'clib-settings' );
	}

	public static function handle_bulk_prepare() {
		self::guard( 'clib_bulk_prepare', 'clib_bulk_edit' );
		$token = CLIB_Bulk::prepare( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() verified; validate() sanitizes every field.
		if ( is_wp_error( $token ) ) {
			self::notice_error( $token );
			self::go( 'clib-catalog' );
		}
		self::go( 'clib-catalog', array( 'bulk' => $token ) );
	}

	public static function handle_bulk_apply() {
		self::guard( 'clib_bulk_apply', 'clib_bulk_edit' );
		$res = CLIB_Bulk::apply( CLIB_Util::post( 'token' ) );
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
		} else {
			/* translators: %d: number of items */
			self::notice( sprintf( _n( '%d item updated.', '%d items updated.', $res, 'church-library' ), $res ) );
		}
		self::go( 'clib-catalog' );
	}

	public static function handle_bulk_cancel() {
		self::guard( 'clib_bulk_cancel', 'clib_bulk_edit' );
		CLIB_Bulk::discard( CLIB_Util::post( 'token' ) );
		self::notice( __( 'Bulk edit cancelled. Nothing was changed.', 'church-library' ), 'info' );
		self::go( 'clib-catalog' );
	}

	public static function handle_export() {
		self::guard( 'clib_export', 'clib_reports' );
		$key = CLIB_Util::enum( CLIB_Util::post( 'report' ), array_keys( CLIB_Reports::reports() ), '' );
		if ( '' === $key ) {
			wp_die( esc_html__( 'Unknown report.', 'church-library' ), 400 );
		}
		$args = CLIB_Reports::args( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- guard() verified; args() sanitizes.
		CLIB_Audit::log( 'export', 'report', 0, $key );
		CLIB_Reports::send_csv( $key, CLIB_Reports::run( $key, $args ) );
	}

	public static function handle_export_catalog() {
		self::guard( 'clib_export_catalog', 'clib_import' );
		CLIB_Export::send( CLIB_Export::filters( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified; filters() sanitizes.
	}

	public static function handle_music_template() {
		self::guard( 'clib_music_template', 'clib_import' );
		CLIB_Export::send_music_template();
	}

	public static function handle_import() {
		self::guard( 'clib_import', 'clib_import' );
		$defaults = array(
			'media_type_id' => CLIB_Util::int( CLIB_Util::post( 'media_type_id' ) ),
			'church_id'     => CLIB_Util::int( CLIB_Util::post( 'church_id' ) ),
			'building_id'   => CLIB_Util::int( CLIB_Util::post( 'building_id' ) ),
			'skip_dupes'    => (bool) CLIB_Util::post( 'skip_dupes' ),
			'add_classes'   => (bool) CLIB_Util::post( 'add_classes' ),
			'dry_run'       => (bool) CLIB_Util::post( 'dry_run' ),
			'update_existing' => (bool) CLIB_Util::post( 'update_existing' ),
		);
		if ( ! CLIB_LOV::in_list( $defaults['media_type_id'], 'media_type' ) ) {
			self::notice( __( 'Choose a default media type.', 'church-library' ), 'error' );
			self::go( 'clib-import' );
		}
		$res = CLIB_Import::run( 'clib_csv', $defaults );
		if ( is_wp_error( $res ) ) {
			self::notice_error( $res );
		} else {
			set_transient( 'clib_import_' . get_current_user_id(), $res, 10 * MINUTE_IN_SECONDS );
		}
		self::go( 'clib-import' );
	}
}
