<?php
/**
 * Front-end page registry and setup.
 *
 * Each library task has its own WordPress page, titled "Library - <name>",
 * holding one shortcode. Pages are created on activation and can be
 * re-created or repaired from Library - Settings.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Pages {

	/**
	 * key => [ title, slug, shortcode, nav label, access ]
	 * access: 'view' (catalog view rule), 'login' (any logged-in user) or a capability.
	 */
	const PAGES = array(
		'catalog'  => array( 'Library - Catalog', 'library-catalog', 'church_library', 'Catalog', 'view' ),
		'checkout' => array( 'Library - Check Out', 'library-check-out', 'clib_checkout', 'Check Out', 'clib_circulate' ),
		'checkin'  => array( 'Library - Check In', 'library-check-in', 'clib_checkin', 'Check In', 'clib_circulate' ),
		'manage'   => array( 'Library - Add or Edit Item', 'library-manage-item', 'clib_manage', 'Add Item', 'clib_manage_items' ),
		'myloans'  => array( 'Library - My Loans', 'library-my-loans', 'clib_my_loans', 'My Loans', 'login' ),
		'reports'  => array( 'Library - Reports', 'library-reports', 'clib_reports', 'Reports', 'clib_reports' ),
		'howto'    => array( 'Library - How To', 'library-how-to', 'clib_howto', 'How To', 'view' ),
	);

	const OPTION = 'clib_page_ids';

	public static function valid( $key ) {
		return is_string( $key ) && isset( self::PAGES[ $key ] );
	}

	/** Can the current visitor open page $key? */
	public static function can_access( $key ) {
		if ( ! self::valid( $key ) ) {
			return false;
		}
		$access = self::PAGES[ $key ][4];
		if ( 'view' === $access ) {
			return CLIB_Perms::can_view();
		}
		if ( 'login' === $access ) {
			return is_user_logged_in() && current_user_can( 'clib_view' );
		}
		return is_user_logged_in() && current_user_can( $access );
	}

	public static function url( $key, array $args = array() ) {
		static $cache = array();
		if ( ! self::valid( $key ) ) {
			return home_url( '/' );
		}
		if ( ! isset( $cache[ $key ] ) ) {
			$ids = get_option( self::OPTION, array() );
			$url = '';
			if ( ! empty( $ids[ $key ] ) && 'publish' === get_post_status( (int) $ids[ $key ] ) ) {
				$url = get_permalink( (int) $ids[ $key ] );
			}
			if ( ! $url ) {
				$page = get_page_by_path( self::PAGES[ $key ][1] );
				$url  = $page ? get_permalink( $page ) : home_url( '/' . self::PAGES[ $key ][1] . '/' );
			}
			$cache[ $key ] = $url;
		}
		if ( ! $args ) {
			return $cache[ $key ];
		}
		$args = array_map( 'rawurlencode', array_map( 'strval', $args ) );
		return add_query_arg( $args, $cache[ $key ] );
	}

	/**
	 * Create missing pages and fix titles. Existing pages are reused (matched by
	 * stored id, then slug) and their content is never overwritten.
	 *
	 * @return array [ created, renamed ]
	 */
	public static function setup() {
		$ids     = get_option( self::OPTION, array() );
		$ids     = is_array( $ids ) ? $ids : array();
		$created = 0;
		$renamed = 0;

		// Version 1.0 stored the catalog page separately.
		$legacy = (int) CLIB_Settings::get( 'catalog_page_id' );
		if ( empty( $ids['catalog'] ) && $legacy ) {
			$ids['catalog'] = $legacy;
		}

		foreach ( self::PAGES as $key => $def ) {
			list( $title, $slug, $shortcode ) = $def;
			$existing = null;
			if ( ! empty( $ids[ $key ] ) ) {
				$existing = get_post( (int) $ids[ $key ] );
				if ( ! $existing || 'page' !== $existing->post_type || 'trash' === $existing->post_status ) {
					$existing = null;
				}
			}
			if ( ! $existing ) {
				$by_slug = get_page_by_path( $slug );
				if ( $by_slug && 'trash' !== $by_slug->post_status ) {
					$existing = $by_slug;
				}
			}
			if ( $existing ) {
				$ids[ $key ] = (int) $existing->ID;
				if ( $existing->post_title !== $title ) {
					wp_update_post(
						array(
							'ID'         => $existing->ID,
							'post_title' => $title,
						)
					);
					++$renamed;
				}
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_title'     => $title,
					'post_name'      => $slug,
					'post_content'   => '<!-- wp:shortcode -->[' . $shortcode . ']<!-- /wp:shortcode -->',
					'post_status'    => 'publish',
					'post_type'      => 'page',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
				),
				true
			);
			if ( ! is_wp_error( $new ) ) {
				$ids[ $key ] = (int) $new;
				++$created;
			}
		}
		update_option( self::OPTION, $ids, false );
		if ( ! empty( $ids['catalog'] ) ) {
			CLIB_Settings::set( 'catalog_page_id', (int) $ids['catalog'] );
		}
		return array( $created, $renamed );
	}
}
