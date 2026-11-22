<?php
/**
 * Entity registry.
 *
 * @package Pressdock
 */

namespace Pressdock;

use Pressdock\Contracts\EntityAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects adapters through the pdock_register_entities filter.
 */
final class Registry {

	/**
	 * Cache for the request.
	 *
	 * @var EntityAdapter[]|null
	 */
	private static $cache = null;

	/**
	 * All valid adapters keyed by entity key.
	 *
	 * @param bool $include_disabled Include entities an admin switched off.
	 * @return EntityAdapter[]
	 */
	public static function all( $include_disabled = false ) {
		if ( null === self::$cache ) {
			/**
			 * Register entities. Add EntityAdapter instances to the array.
			 *
			 * @param EntityAdapter[] $adapters Registered adapters.
			 */
			$list  = apply_filters( 'pdock_register_entities', array() );
			$clean = array();
			foreach ( (array) $list as $adapter ) {
				if ( $adapter instanceof EntityAdapter ) {
					$key = $adapter->key();
					if ( is_string( $key ) && preg_match( '/^[a-z0-9_]{1,64}$/', $key ) ) {
						$clean[ $key ] = $adapter;
					}
				}
			}
			self::$cache = $clean;
		}

		if ( $include_disabled ) {
			return self::$cache;
		}

		$disabled = (array) Settings::get( 'disabled_entities' );
		return array_diff_key( self::$cache, array_flip( $disabled ) );
	}

	/**
	 * One enabled adapter.
	 *
	 * @param string $key Entity key.
	 * @return EntityAdapter|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Drop the cache (tests, or after plugins register late).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cache = null;
	}

	/**
	 * Which of read/create/update/delete the current user may do on an entity.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @return array
	 */
	public static function allowed_actions( EntityAdapter $adapter ) {
		$out = array();
		foreach ( array( 'read', 'create', 'update', 'delete' ) as $action ) {
			$out[ $action ] = self::user_can( $adapter, $action );
		}
		return $out;
	}

	/**
	 * Whether the current user may do an action on an entity.
	 *
	 * An adapter can implement can( $action ) when its plugin has its own permission
	 * model (for example roles granted through a filter). Otherwise the WordPress
	 * capability returned by capability() is used.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @param string        $action  read|create|update|delete.
	 * @return bool
	 */
	public static function user_can( EntityAdapter $adapter, $action ) {
		if ( method_exists( $adapter, 'can' ) ) {
			return (bool) $adapter->can( $action );
		}
		return current_user_can( $adapter->capability( $action ) );
	}
}
