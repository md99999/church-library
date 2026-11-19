<?php
/**
 * Role / capability matrix.
 *
 * Permissions are stored as real WordPress capabilities on each role, so the
 * standard current_user_can() check is used everywhere. Administrators always
 * hold every capability and cannot be edited in the matrix. Plugin settings,
 * the permission matrix and the audit log always require manage_options.
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_Perms {

	public static function caps() {
		return array(
			'clib_view'         => __( 'View catalog', 'church-library' ),
			'clib_manage_items' => __( 'Add & edit items', 'church-library' ),
			'clib_delete_items' => __( 'Delete items', 'church-library' ),
			'clib_circulate'    => __( 'Check out / check in', 'church-library' ),
			'clib_reports'      => __( 'View reports', 'church-library' ),
			'clib_import'       => __( 'Import / export CSV', 'church-library' ),
			'clib_manage_lists' => __( 'Manage lists of values', 'church-library' ),
			'clib_bulk_edit'    => __( 'Bulk edit locations', 'church-library' ),
		);
	}

	public static function default_matrix() {
		$staff = array( 'clib_view', 'clib_manage_items', 'clib_circulate', 'clib_reports' );
		return array(
			'editor'      => array_merge( $staff, array( 'clib_delete_items', 'clib_import', 'clib_manage_lists' ) ),
			'author'      => $staff,
			'contributor' => $staff,
			'subscriber'  => array( 'clib_view' ),
		);
	}

	/** On first activation: apply the default matrix. */
	public static function install_defaults() {
		self::ensure_admin_caps();
		if ( get_option( 'clib_caps_installed' ) ) {
			return;
		}
		foreach ( self::default_matrix() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
		update_option( 'clib_caps_installed', 1, false );
	}

	public static function ensure_admin_caps() {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}
		foreach ( array_keys( self::caps() ) as $cap ) {
			if ( ! $admin->has_cap( $cap ) ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/** Current matrix: role => [cap => bool]. */
	public static function matrix() {
		$out = array();
		foreach ( wp_roles()->role_objects as $name => $role ) {
			foreach ( array_keys( self::caps() ) as $cap ) {
				$out[ $name ][ $cap ] = ( 'administrator' === $name ) ? true : $role->has_cap( $cap );
			}
		}
		return $out;
	}

	/**
	 * Apply a submitted matrix. Only known roles and known caps are honoured;
	 * administrator is never changed.
	 *
	 * @param array $raw role => [cap => '1'].
	 */
	public static function save_matrix( $raw ) {
		$raw  = is_array( $raw ) ? $raw : array();
		$caps = array_keys( self::caps() );
		foreach ( wp_roles()->role_objects as $name => $role ) {
			if ( 'administrator' === $name ) {
				continue;
			}
			$granted = ( isset( $raw[ $name ] ) && is_array( $raw[ $name ] ) ) ? $raw[ $name ] : array();
			foreach ( $caps as $cap ) {
				if ( ! empty( $granted[ $cap ] ) ) {
					$role->add_cap( $cap );
				} elseif ( $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
		self::ensure_admin_caps();
	}

	public static function remove_all() {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( self::caps() ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		delete_option( 'clib_caps_installed' );
	}

	/** Can the current visitor view the catalog (front end)? */
	public static function can_view() {
		if ( CLIB_Settings::get( 'public_access' ) ) {
			return true;
		}
		return is_user_logged_in() && current_user_can( 'clib_view' );
	}

	/** Can the current visitor see borrower names? */
	public static function can_see_borrowers() {
		return current_user_can( 'clib_circulate' ) || ( CLIB_Settings::get( 'show_borrowers' ) && is_user_logged_in() && current_user_can( 'clib_view' ) );
	}
}
