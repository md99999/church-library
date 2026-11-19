<?php
/**
 * Admin-managed lists of values (media types, music sub-categories,
 * churches, buildings, classifications, liturgical uses).
 *
 * @package ChurchLibrary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLIB_LOV {

	public static function types() {
		return array(
			'media_type'        => __( 'Media Types', 'church-library' ),
			'music_subcategory' => __( 'Music Sub-categories', 'church-library' ),
			'church'            => __( 'Churches', 'church-library' ),
			'building'          => __( 'Buildings', 'church-library' ),
			'classification'    => __( 'Classifications', 'church-library' ),
			'liturgical_use'    => __( 'Liturgical Uses', 'church-library' ),
		);
	}

	public static function valid_type( $type ) {
		return array_key_exists( $type, self::types() );
	}

	public static function count( $type ) {
		global $wpdb;
		$t = CLIB_DB::table( 'lov' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE list_type = %s", $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** All entries of a list, keyed by id. */
	public static function all( $type, $active_only = false ) {
		global $wpdb;
		static $cache = array();
		$key = $type . ( $active_only ? '1' : '0' );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$t   = CLIB_DB::table( 'lov' );
		$sql = "SELECT * FROM $t WHERE list_type = %s" . ( $active_only ? ' AND is_active = 1' : '' ) . ' ORDER BY sort_order, label';
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql, $type ) ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ (int) $row->id ] = $row;
		}
		$cache[ $key ] = $out;
		return $out;
	}

	public static function get( $id ) {
		global $wpdb;
		$t = CLIB_DB::table( 'lov' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Does $id exist in list $type (optionally active only)? */
	public static function in_list( $id, $type, $active_only = false ) {
		$row = $id ? self::get( $id ) : null;
		return $row && $row->list_type === $type && ( ! $active_only || (int) $row->is_active === 1 );
	}

	public static function label( $id ) {
		if ( ! $id ) {
			return '';
		}
		static $labels = array();
		if ( ! isset( $labels[ $id ] ) ) {
			$row           = self::get( $id );
			$labels[ $id ] = $row ? $row->label : '';
		}
		return $labels[ $id ];
	}

	public static function find_by_label( $type, $label ) {
		global $wpdb;
		$t = CLIB_DB::table( 'lov' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE list_type = %s AND label = %s LIMIT 1", $type, $label ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function is_music_type( $media_type_id ) {
		$row = $media_type_id ? self::get( $media_type_id ) : null;
		return $row && 'media_type' === $row->list_type && (int) $row->is_music === 1;
	}

	/**
	 * Validate + normalise a list entry.
	 *
	 * @return array|WP_Error
	 */
	public static function validate( array $raw, $id = 0 ) {
		$type = isset( $raw['list_type'] ) ? $raw['list_type'] : '';
		if ( ! self::valid_type( $type ) ) {
			return new WP_Error( 'clib_lov', __( 'Invalid list.', 'church-library' ) );
		}
		$data = array(
			'list_type'  => $type,
			'label'      => CLIB_Util::text( isset( $raw['label'] ) ? $raw['label'] : '', 100 ),
			'code'       => '',
			'parent_id'  => null,
			'is_music'   => 0,
			'sort_order' => CLIB_Util::int( isset( $raw['sort_order'] ) ? $raw['sort_order'] : 0, 0, 100000 ),
			'is_active'  => isset( $raw['is_active'] ) ? ( empty( $raw['is_active'] ) ? 0 : 1 ) : 1,
		);
		if ( '' === $data['label'] ) {
			return new WP_Error( 'clib_lov', __( 'A label is required.', 'church-library' ) );
		}
		if ( 'media_type' === $type ) {
			$data['code']     = CLIB_Util::code( isset( $raw['code'] ) ? $raw['code'] : '', 10 );
			$data['is_music'] = empty( $raw['is_music'] ) ? 0 : 1;
			if ( strlen( $data['code'] ) < 2 ) {
				return new WP_Error( 'clib_lov', __( 'Media types need an ID prefix code of 2-10 letters or digits.', 'church-library' ) );
			}
			foreach ( self::all( 'media_type' ) as $other ) {
				if ( (int) $other->id !== (int) $id && $other->code === $data['code'] ) {
					return new WP_Error( 'clib_lov', __( 'That ID prefix code is already used by another media type.', 'church-library' ) );
				}
			}
		}
		if ( 'building' === $type && ! empty( $raw['parent_id'] ) ) {
			$parent = CLIB_Util::id_or_null( $raw['parent_id'] );
			if ( $parent && ! self::in_list( $parent, 'church' ) ) {
				return new WP_Error( 'clib_lov', __( 'Invalid church for building.', 'church-library' ) );
			}
			$data['parent_id'] = $parent;
		}
		foreach ( self::all( $type ) as $other ) {
			if ( (int) $other->id !== (int) $id && 0 === strcasecmp( $other->label, $data['label'] ) && (int) $other->parent_id === (int) $data['parent_id'] ) {
				return new WP_Error( 'clib_lov', __( 'An entry with that label already exists.', 'church-library' ) );
			}
		}
		return $data;
	}

	public static function insert( array $data ) {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'code'       => '',
				'parent_id'  => null,
				'is_music'   => 0,
				'sort_order' => 0,
				'is_active'  => 1,
			)
		);
		$wpdb->insert(
			CLIB_DB::table( 'lov' ),
			array(
				'list_type'  => $data['list_type'],
				'code'       => $data['code'],
				'label'      => $data['label'],
				'parent_id'  => $data['parent_id'],
				'is_music'   => (int) $data['is_music'],
				'sort_order' => (int) $data['sort_order'],
				'is_active'  => (int) $data['is_active'],
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, array $data ) {
		global $wpdb;
		unset( $data['list_type'] ); // A row never changes list.
		return false !== $wpdb->update(
			CLIB_DB::table( 'lov' ),
			array(
				'code'       => $data['code'],
				'label'      => $data['label'],
				'parent_id'  => $data['parent_id'],
				'is_music'   => (int) $data['is_music'],
				'sort_order' => (int) $data['sort_order'],
				'is_active'  => (int) $data['is_active'],
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d' ),
			array( '%d' )
		);
	}

	/** Number of items/rows referencing a list entry. */
	public static function usage( $id, $type ) {
		global $wpdb;
		$items = CLIB_DB::table( 'items' );
		$lov   = CLIB_DB::table( 'lov' );
		$map   = array(
			'media_type'        => 'media_type_id',
			'music_subcategory' => 'subcategory_id',
			'church'            => 'church_id',
			'building'          => 'building_id',
			'classification'    => 'classification_id',
		);
		$count = 0;
		if ( isset( $map[ $type ] ) ) {
			$col    = $map[ $type ]; // Whitelisted column name.
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $items WHERE $col = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'church' === $type ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $lov WHERE list_type = 'building' AND parent_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $count;
	}

	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( CLIB_DB::table( 'lov' ), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
