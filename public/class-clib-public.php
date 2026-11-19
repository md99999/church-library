<?php
/**
 * Front-end pages: one shortcode per "Library - <name>" page (see CLIB_Pages),
 * a shared navigation bar, and a POST action handler.
 *
 * Every page checks access on the server (public-view setting and the role
 * permission matrix) before rendering anything. Every form carries an
 * action-bound nonce, and the handler re-checks the capability before acting.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Public {

	/** Front-end POST actions: action => required capability. */
	const ACTIONS = array(
		'checkout'  => 'clib_circulate',
		'checkin'   => 'clib_circulate',
		'save_item' => 'clib_manage_items',
		'export'    => 'clib_reports',
	);

	public static function init() {
		foreach ( CLIB_Pages::PAGES as $key => $def ) {
			add_shortcode(
				$def[2],
				function () use ( $key ) {
					return CLIB_Public::render( $key );
				}
			);
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle' ) );
	}

	/** Activation hook: create the library pages. */
	public static function ensure_page() {
		CLIB_Pages::setup();
	}

	/* ---------------------------------------------------------------------
	 * Rendering.
	 * ------------------------------------------------------------------ */

	public static function render( $key ) {
		if ( ! CLIB_Pages::valid( $key ) ) {
			return '';
		}
		// Block editor previews / REST: keep it cheap and reveal nothing.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '<p>[' . esc_html( CLIB_Pages::PAGES[ $key ][0] ) . ']</p>';
		}

		wp_enqueue_style( 'clib-public', CLIB_URL . 'assets/public.css', array(), CLIB_VERSION );

		ob_start();
		echo '<div class="clib clib-page-' . esc_attr( $key ) . '">';
		if ( ! CLIB_Pages::can_access( $key ) ) {
			self::denied();
			echo '</div>';
			return ob_get_clean();
		}

		if ( in_array( $key, array( 'manage', 'checkout', 'checkin', 'catalog', 'reports' ), true ) ) {
			self::enqueue_form_script();
		}

		self::nav( $key );
		foreach ( CLIB_Form::take_notices() as $n ) {
			echo '<div class="clib-notice clib-notice-' . esc_attr( $n['type'] ) . '" role="status">' . esc_html( $n['msg'] ) . '</div>';
		}
		$views = array(
			'catalog'  => 'catalog.php',
			'checkout' => 'checkout.php',
			'checkin'  => 'checkin.php',
			'manage'   => 'manage.php',
			'myloans'  => 'myloans.php',
			'reports'  => 'reports.php',
			'howto'    => 'howto.php',
		);
		include CLIB_DIR . 'public/views/' . $views[ $key ]; // Fixed map: no user input reaches the path.
		echo '</div>';
		return ob_get_clean();
	}

	private static function denied() {
		if ( ! is_user_logged_in() ) {
			echo '<div class="clib-locked"><p>' . esc_html__( 'Please log in to use this library page.', 'church-library' ) . '</p>';
			echo '<p><a class="clib-btn" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'church-library' ) . '</a></p></div>';
			return;
		}
		echo '<div class="clib-locked"><p>' . esc_html__( 'Your account does not have permission to use this library page.', 'church-library' ) . '</p>';
		if ( CLIB_Pages::can_access( 'catalog' ) ) {
			echo '<p><a href="' . esc_url( CLIB_Pages::url( 'catalog' ) ) . '">' . esc_html__( 'Go to the catalog', 'church-library' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Navigation bar: only pages this visitor may open. */
	private static function nav( $current ) {
		$links = array();
		foreach ( CLIB_Pages::PAGES as $key => $def ) {
			if ( ! CLIB_Pages::can_access( $key ) ) {
				continue;
			}
			$links[] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( CLIB_Pages::url( $key ) ),
				$key === $current ? ' class="is-current" aria-current="page"' : '',
				esc_html( $def[3] )
			);
		}
		if ( current_user_can( 'clib_view' ) && current_user_can( 'edit_posts' ) ) {
			$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=clib-catalog' ) ) . '">' . esc_html__( 'Admin', 'church-library' ) . '</a>';
		}
		if ( count( $links ) > 1 ) {
			echo '<nav class="clib-nav" aria-label="' . esc_attr__( 'Library', 'church-library' ) . '">' . implode( ' ', $links ) . '</nav>'; // phpcs:ignore WordPress.Security.EscapeOutput -- each link escaped above.
		}
	}

	private static function enqueue_form_script() {
		wp_enqueue_script( 'clib-forms', CLIB_URL . 'assets/admin.js', array(), CLIB_VERSION, true );
		wp_localize_script(
			'clib-forms',
			'CLIB',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'lookupNonce'   => current_user_can( 'clib_manage_items' ) ? wp_create_nonce( 'clib_lookup' ) : '',
				'lookupEnabled' => (bool) CLIB_Settings::get( 'lookup_enabled' ),
				'i18n'          => array(
					'confirmDelete' => __( 'Are you sure?', 'church-library' ),
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

	/** Opening tag + nonce for a front-end action form that posts back to the current page. */
	public static function form_open( $action, $extra_attrs = '' ) {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return '';
		}
		return '<form method="post" action="' . esc_url( get_permalink() ) . '" ' . $extra_attrs . '>'
			. '<input type="hidden" name="clib_front_action" value="' . esc_attr( $action ) . '">'
			. wp_nonce_field( 'clib_front_' . $action, '_clib_nonce', false, false );
	}

	/* ---------------------------------------------------------------------
	 * Actions (POST back to a library page, handled before output).
	 * ------------------------------------------------------------------ */

	public static function handle() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) || ! isset( $_POST['clib_front_action'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing
			return;
		}
		$action = CLIB_Util::enum( CLIB_Util::post( 'clib_front_action' ), array_keys( self::ACTIONS ), '' );
		$nonce  = CLIB_Util::post( '_clib_nonce' );
		if ( '' === $action || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'clib_front_' . $action ) ) {
			wp_die( esc_html__( 'This form has expired. Please go back, reload the page and try again.', 'church-library' ), 403 );
		}
		if ( ! is_user_logged_in() || ! current_user_can( self::ACTIONS[ $action ] ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'church-library' ), 403 );
		}
		call_user_func( array( __CLASS__, 'do_' . $action ) );
	}

	private static function go( $page, array $args = array() ) {
		wp_safe_redirect( CLIB_Pages::url( $page, $args ) );
		exit;
	}

	private static function back( $default_page, $item_code = '' ) {
		$return = CLIB_Util::enum( CLIB_Util::post( 'return' ), array( 'item', 'checkout', 'checkin' ), $default_page );
		if ( 'item' === $return && '' !== $item_code ) {
			self::go( 'catalog', array( 'clib_item' => $item_code ) );
		}
		self::go( 'item' === $return ? 'catalog' : $return );
	}

	private static function do_checkout() {
		$item = CLIB_Items::find_by_code( CLIB_Util::post( 'item_code' ) );
		if ( ! $item ) {
			CLIB_Form::notice( __( 'No item matches that Item ID or barcode.', 'church-library' ), 'error' );
			self::back( 'checkout' );
		}
		$res = CLIB_Loans::checkout( (int) $item->id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in handle(); fields sanitized in checkout().
		if ( is_wp_error( $res ) ) {
			CLIB_Form::notice_error( $res );
		} else {
			$loan = CLIB_Loans::get( $res );
			/* translators: 1: item id 2: title 3: borrower 4: due date */
			CLIB_Form::notice( sprintf( __( 'Checked out %1$s (%2$s) to %3$s, due %4$s.', 'church-library' ), $item->item_code, $item->title, $loan->borrower_name, CLIB_Util::fmt_date( $loan->due_date ) ) );
		}
		self::back( 'checkout', $item->item_code );
	}

	private static function do_checkin() {
		$loan_id = CLIB_Util::int( CLIB_Util::post( 'loan_id' ) );
		$code    = '';
		if ( ! $loan_id ) {
			$item = CLIB_Items::find_by_code( CLIB_Util::post( 'item_code' ) );
			if ( ! $item ) {
				CLIB_Form::notice( __( 'No item matches that Item ID or barcode.', 'church-library' ), 'error' );
				self::back( 'checkin' );
			}
			$code   = $item->item_code;
			$active = CLIB_Loans::active_for_item( $item->id );
			if ( ! $active ) {
				CLIB_Form::notice( __( 'That item is not checked out.', 'church-library' ), 'error' );
				self::back( 'checkin', $code );
			}
			if ( count( $active ) > 1 ) {
				CLIB_Form::notice( __( 'Several copies are out. Choose which one to check in from the list below.', 'church-library' ), 'warning' );
				self::go( 'checkin', array( 'item' => $code ) );
			}
			$loan_id = (int) $active[0]->id;
		}
		$loan = CLIB_Loans::get( $loan_id );
		$res  = CLIB_Loans::checkin( $loan_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in handle(); fields sanitized in checkin().
		if ( is_wp_error( $res ) ) {
			CLIB_Form::notice_error( $res );
		} else {
			$item = CLIB_Items::get( $loan->item_id );
			$code = $item ? $item->item_code : $code;
			/* translators: 1: item id 2: borrower */
			CLIB_Form::notice( sprintf( __( 'Checked in %1$s from %2$s.', 'church-library' ), $code, $loan->borrower_name ) );
		}
		self::back( 'checkin', $code );
	}

	private static function do_save_item() {
		$id  = CLIB_Util::int( CLIB_Util::post( 'id' ) );
		$res = CLIB_Items::save_from_request( $_POST, $id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- verified in handle(); sanitized inside.
		if ( is_wp_error( $res ) ) {
			CLIB_Form::notice_error( $res );
			self::go( 'manage', $id ? array( 'id' => $id ) : array( 'retry' => 1 ) );
		}
		foreach ( $res['notices'] as $n ) {
			CLIB_Form::notice( $n[1], $n[0] );
		}
		if ( ! $id && CLIB_Util::post( 'add_another' ) ) {
			self::go( 'manage' );
		}
		self::go( 'catalog', array( 'clib_item' => CLIB_Items::get( $res['id'] )->item_code ) );
	}

	private static function do_export() {
		$key = CLIB_Util::enum( CLIB_Util::post( 'report' ), array_keys( CLIB_Reports::reports() ), '' );
		if ( '' === $key ) {
			wp_die( esc_html__( 'Unknown report.', 'church-library' ), 400 );
		}
		CLIB_Audit::log( 'export', 'report', 0, $key );
		CLIB_Reports::send_csv( $key, CLIB_Reports::run( $key, CLIB_Reports::args( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle(); args() sanitizes.
	}
}
