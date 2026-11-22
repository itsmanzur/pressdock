<?php
/**
 * One-time baseline: records that predate the connector get a change-log row,
 * so an initial pull (since=0) returns them.
 *
 * @package Pressdock
 */

namespace Pressdock\Sync;

use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;
use Pressdock\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chunked and resumable. Progress lives in the pdock_baseline option (autoload off).
 */
final class Baseline {

	const OPTION = 'pdock_baseline';
	const CHUNK  = 200;

	/**
	 * Progress for every entity: array( entity => array( 'last' => int, 'done' => bool ) ).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Whether the baseline of an entity is finished (or not needed).
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @return bool
	 */
	public static function is_complete( EntityAdapter $adapter ) {
		if ( ! $adapter instanceof EnumerableAdapter ) {
			return true;
		}
		$state = self::state();
		return ! empty( $state[ $adapter->key() ]['done'] );
	}

	/**
	 * Advance the baseline for an entity within a time budget.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @param int           $seconds Time budget.
	 * @return bool True when the baseline is complete.
	 */
	public static function advance( EntityAdapter $adapter, $seconds = 10 ) {
		if ( self::is_complete( $adapter ) ) {
			return true;
		}
		$key  = $adapter->key();
		$lock = 'pdock_bl_' . $key;
		if ( get_transient( $lock ) ) {
			return false;
		}
		set_transient( $lock, 1, MINUTE_IN_SECONDS );

		$state = self::state();
		$last  = isset( $state[ $key ]['last'] ) ? (int) $state[ $key ]['last'] : 0;
		$end   = microtime( true ) + $seconds;
		$done  = false;

		while ( microtime( true ) < $end ) {
			$ids = $adapter->ids_after( $last, self::CHUNK );
			if ( array() === $ids ) {
				$done = true;
				break;
			}
			foreach ( $ids as $id ) {
				Tracker::touch( $key, $id, 'upsert', 'other' );
				$last = $id;
			}
		}

		$state[ $key ] = array(
			'last' => $last,
			'done' => $done,
		);
		update_option( self::OPTION, $state, false );
		delete_transient( $lock );

		return $done;
	}
}
