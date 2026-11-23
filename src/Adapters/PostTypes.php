<?php
/**
 * Which post types can be synced without code, and which meta keys they use.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters;

use Pressdock\Registry;
use Pressdock\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for the generic post type adapter.
 */
final class PostTypes {

	/**
	 * Core post types that never make sense to sync.
	 */
	const EXCLUDED = array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );

	/**
	 * Saved configuration: post type => array( 'on' => 1, 'meta' => key => type ).
	 *
	 * @return array
	 */
	public static function configured() {
		$saved = Settings::get( 'post_types' );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Post types an administrator can add: shown in the admin UI, not excluded,
	 * and not already handled by a dedicated adapter.
	 *
	 * @return \WP_Post_Type[] Keyed by slug.
	 */
	public static function eligible() {
		$taken = array();
		foreach ( Registry::all( true ) as $adapter ) {
			if ( $adapter instanceof AbstractPostAdapter && ! ( $adapter instanceof GenericPostAdapter ) ) {
				$taken[] = $adapter->post_type();
			}
		}
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $slug => $object ) {
			if ( in_array( $slug, self::EXCLUDED, true ) || in_array( $slug, $taken, true ) ) {
				continue;
			}
			$out[ $slug ] = $object;
		}
		return $out;
	}

	/**
	 * Adapters for the configured post types. Called inside the registration filter.
	 *
	 * @param array $existing Adapters registered so far (a dedicated adapter wins).
	 * @return GenericPostAdapter[]
	 */
	public static function adapters( array $existing ) {
		$taken = array();
		foreach ( $existing as $adapter ) {
			if ( $adapter instanceof AbstractPostAdapter ) {
				$taken[] = $adapter->post_type();
			}
		}
		$out = array();
		foreach ( self::configured() as $slug => $config ) {
			$slug = (string) $slug;
			if ( empty( $config['on'] ) || ! post_type_exists( $slug ) || in_array( $slug, $taken, true ) || in_array( $slug, self::EXCLUDED, true ) ) {
				continue;
			}
			$meta  = isset( $config['meta'] ) && is_array( $config['meta'] ) ? $config['meta'] : array();
			$out[] = new GenericPostAdapter( $slug, $meta );
		}
		return $out;
	}

	/**
	 * Whether a meta key may be offered and synced: a plain, non-protected key.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function allowed_meta_key( $key ) {
		$key = (string) $key;
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_\-:.]{0,190}$/', $key ) && ! is_protected_meta( $key, 'post' );
	}

	/**
	 * Meta keys used by a post type, with a suggested field type.
	 *
	 * Registered meta comes first (its type is known). Other keys are found in the database;
	 * keys that hold serialized arrays are skipped, because editing them as text would destroy them.
	 *
	 * @param string $post_type Post type slug.
	 * @return array Key => suggested type.
	 */
	public static function meta_candidates( $post_type ) {
		$out  = array();
		$map  = array(
			'integer' => 'int',
			'number'  => 'float',
			'boolean' => 'bool',
			'string'  => 'string',
		);
		$regd = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) );
		foreach ( $regd as $key => $args ) {
			$type = isset( $args['type'] ) ? $args['type'] : 'string';
			if ( isset( $map[ $type ] ) && self::allowed_meta_key( $key ) ) {
				$out[ $key ] = $map[ $type ];
			}
		}

		global $wpdb;
		$cache_key = 'pdock_meta_keys_' . md5( $post_type );
		$rows      = wp_cache_get( $cache_key, 'pressdock' );
		if ( false === $rows ) {
			// Distinct meta keys of one post type, with a flag for serialized values. Values are prepared.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"SELECT pm.meta_key, MAX( pm.meta_value LIKE %s OR pm.meta_value LIKE %s ) AS serialized
					FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s
					GROUP BY pm.meta_key ORDER BY pm.meta_key ASC LIMIT 100",
					'a:%',
					'O:%',
					$post_type,
					$wpdb->esc_like( '_' ) . '%'
				),
				ARRAY_A
			);
			wp_cache_set( $cache_key, $rows, 'pressdock', 5 * MINUTE_IN_SECONDS );
		}
		foreach ( (array) $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( ! isset( $out[ $key ] ) && empty( $row['serialized'] ) && self::allowed_meta_key( $key ) ) {
				$out[ $key ] = 'string';
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Sanitize the post type configuration posted from the admin screen.
	 *
	 * @param mixed $raw Raw input: post type => array( 'on' => 1, 'meta' => key => type|'' ).
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $slug => $config ) {
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || ! is_array( $config ) || empty( $config['on'] ) || in_array( $slug, self::EXCLUDED, true ) || ! post_type_exists( $slug ) ) {
				continue;
			}
			$meta = array();
			if ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) {
				foreach ( $config['meta'] as $key => $type ) {
					$type = is_string( $type ) ? sanitize_key( $type ) : '';
					if ( '' === $type || ! self::allowed_meta_key( $key ) ) {
						continue; // Not selected, or not allowed.
					}
					$meta[ (string) $key ] = in_array( $type, GenericPostAdapter::META_TYPES, true ) ? $type : 'string';
				}
			}
			$out[ $slug ] = array(
				'on'   => 1,
				'meta' => $meta,
			);
		}
		return $out;
	}
}
