<?php
/**
 * Full-width layout for the "Library - ..." pages (no sidebar).
 *
 * WordPress has no universal "no sidebar" switch: each theme does it its own
 * way. This uses three layers, so it works on most themes without editing them:
 *
 * 1. Page template: if the active theme offers a full-width / no-sidebar page
 *    template, the library pages are set to it (only pages still on the
 *    default template, or on one this plugin set earlier; a template chosen by
 *    hand in the page editor is never changed).
 * 2. Sidebar widget areas are reported empty on library pages, so themes that
 *    only draw a sidebar when it has widgets leave it out. Footer and header
 *    widget areas are left alone.
 * 3. Body classes (clib-full-width, no-sidebar) and a small CSS fallback that
 *    hides common sidebar containers and widens the content column.
 *
 * Controlled by "Full-width library pages" in Library - Settings (on by default).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Layout {

	/** Page ids whose template this plugin set (so it may change them again). */
	const OPTION = 'clib_layout_templates';

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'maybe_apply' ) );
		add_action( 'after_switch_theme', array( __CLASS__, 'apply_templates' ) );
	}

	public static function enabled() {
		return (bool) CLIB_Settings::get( 'full_width' );
	}

	/** Is the current request one of the library pages? */
	public static function is_library_page() {
		if ( ! is_page() ) {
			return false;
		}
		$ids = get_option( CLIB_Pages::OPTION, array() );
		return is_array( $ids ) && in_array( (int) get_queried_object_id(), array_map( 'intval', $ids ), true );
	}

	public static function maybe_apply() {
		if ( is_admin() || ! self::enabled() || ! self::is_library_page() ) {
			return;
		}
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 99 );
		add_filter( 'sidebars_widgets', array( __CLASS__, 'hide_sidebars' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 99 );
	}

	public static function body_class( $classes ) {
		$drop = array( 'has-sidebar', 'sidebar-right', 'sidebar-left', 'right-sidebar', 'left-sidebar', 'sidebar-primary', 'two-column', 'has-right-sidebar', 'has-left-sidebar' );
		$classes = array_values( array_diff( (array) $classes, $drop ) );
		$classes[] = 'clib-full-width';
		$classes[] = 'no-sidebar';
		$classes[] = 'full-width';
		return array_unique( $classes );
	}

	/** True for widget areas that look like sidebars rather than footer/header areas. */
	public static function is_sidebar_area( $id ) {
		global $wp_registered_sidebars;
		$name = isset( $wp_registered_sidebars[ $id ]['name'] ) ? (string) $wp_registered_sidebars[ $id ]['name'] : '';
		$text = strtolower( $id . ' ' . $name );
		if ( preg_match( '/footer|header|top-bar|topbar|menu|offcanvas|off-canvas|mobile/', $text ) ) {
			return false;
		}
		return (bool) preg_match( '/sidebar|side-bar|aside|primary|secondary|left|right|blog/', $text );
	}

	public static function hide_sidebars( $widgets ) {
		if ( ! is_array( $widgets ) ) {
			return $widgets;
		}
		foreach ( array_keys( $widgets ) as $id ) {
			if ( 'wp_inactive_widgets' !== $id && 'array_version' !== $id && self::is_sidebar_area( $id ) ) {
				$widgets[ $id ] = array();
			}
		}
		return $widgets;
	}

	public static function enqueue() {
		wp_register_style( 'clib-layout', false, array(), CLIB_VERSION );
		wp_enqueue_style( 'clib-layout' );
		wp_add_inline_style(
			'clib-layout',
			'body.clib-full-width #secondary, body.clib-full-width #sidebar, body.clib-full-width aside.sidebar, body.clib-full-width .sidebar-container, body.clib-full-width .widget-area.sidebar { display: none !important; }'
			. 'body.clib-full-width #primary, body.clib-full-width .content-area, body.clib-full-width #content .site-main, body.clib-full-width main.site-main { width: 100% !important; max-width: 100% !important; float: none !important; flex: 1 1 100% !important; }'
		);
	}

	/** The active theme's full-width page template file, or ''. */
	public static function theme_template() {
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		$best      = '';
		$best_rank = 0;
		foreach ( $templates as $file => $label ) {
			$text = strtolower( $file . ' ' . $label );
			// Templates that drop the site header/footer are not what we want.
			if ( preg_match( '/canvas|blank|landing|front|home|empty|no-header|without-header/', $text ) ) {
				continue;
			}
			$rank = 0;
			if ( preg_match( '/no[\s_-]*sidebar|without[\s_-]*sidebar|sidebar[\s_-]*less/', $text ) ) {
				$rank = 3;
			} elseif ( preg_match( '/full[\s_-]*width|fullwidth/', $text ) ) {
				$rank = 2;
			} elseif ( preg_match( '/\bwide\b|one[\s_-]*column|single[\s_-]*column|1[\s_-]*column/', $text ) ) {
				$rank = 1;
			}
			if ( $rank > $best_rank ) {
				$best      = (string) $file;
				$best_rank = $rank;
			}
		}
		return $best;
	}

	/**
	 * Set (or, when switched off, undo) the full-width template on the
	 * library pages. Pages with a hand-picked template are left alone.
	 */
	public static function apply_templates() {
		$ids  = get_option( CLIB_Pages::OPTION, array() );
		$ours = get_option( self::OPTION, array() );
		$ours = is_array( $ours ) ? array_map( 'intval', $ours ) : array();
		if ( ! is_array( $ids ) ) {
			return;
		}
		$tpl  = self::enabled() ? self::theme_template() : '';
		$keep = array();
		foreach ( array_map( 'intval', $ids ) as $id ) {
			if ( ! $id || 'page' !== get_post_type( $id ) ) {
				continue;
			}
			$current = (string) get_post_meta( $id, '_wp_page_template', true );
			$is_ours = in_array( $id, $ours, true );
			if ( '' !== $current && 'default' !== $current && ! $is_ours ) {
				continue; // Chosen by hand: never touch.
			}
			if ( '' !== $tpl ) {
				update_post_meta( $id, '_wp_page_template', $tpl );
				$keep[] = $id;
			} elseif ( $is_ours ) {
				delete_post_meta( $id, '_wp_page_template' );
			}
		}
		update_option( self::OPTION, $keep, false );
	}
}
