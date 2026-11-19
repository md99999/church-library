<?php
/**
 * Form helpers shared by admin screens and front-end pages: option lists
 * and per-user flash notices (stored server-side, never read from the URL).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Form {

	/** <option> list for a LOV. */
	public static function lov_options( $type, $selected, $placeholder = '', $active_only = true ) {
		$html = '';
		if ( '' !== $placeholder ) {
			$html .= '<option value="">' . esc_html( $placeholder ) . '</option>';
		}
		foreach ( CLIB_LOV::all( $type ) as $id => $row ) {
			if ( $active_only && ! $row->is_active && (int) $selected !== (int) $id ) {
				continue;
			}
			$attrs = '';
			if ( 'media_type' === $type ) {
				$attrs .= ' data-music="' . ( $row->is_music ? '1' : '0' ) . '"';
			}
			if ( 'building' === $type ) {
				$attrs .= ' data-church="' . esc_attr( (int) $row->parent_id ) . '"';
			}
			$html .= '<option value="' . esc_attr( $id ) . '"' . $attrs . selected( (int) $selected, (int) $id, false ) . '>' . esc_html( $row->label ) . '</option>';
		}
		return $html;
	}

	/** <option> list for a fixed label map. */
	public static function map_options( array $map, $selected, $placeholder = '' ) {
		$html = '' !== $placeholder ? '<option value="">' . esc_html( $placeholder ) . '</option>' : '';
		foreach ( $map as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $selected, (string) $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	public static function notice( $message, $type = 'success' ) {
		$key    = 'clib_notice_' . get_current_user_id();
		$list   = get_transient( $key );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'type' => in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info',
			'msg'  => (string) $message,
		);
		set_transient( $key, $list, 5 * MINUTE_IN_SECONDS );
	}

	public static function notice_error( WP_Error $err ) {
		foreach ( $err->get_error_messages() as $m ) {
			self::notice( $m, 'error' );
		}
	}

	/** Pull and clear this user's notices. */
	public static function take_notices() {
		$key  = 'clib_notice_' . get_current_user_id();
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			return array();
		}
		delete_transient( $key );
		return $list;
	}
}
