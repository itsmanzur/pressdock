<?php
/**
 * Sync context flag. Adapters read this to suppress side effects (emails, hooks).
 *
 * @package Pressdock
 */

namespace Pressdock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks whether the current code path is applying a sync operation.
 */
final class Context {

	/**
	 * Nesting depth.
	 *
	 * @var int
	 */
	private static $depth = 0;

	/**
	 * Whether a sync operation is being applied right now.
	 *
	 * @return bool
	 */
	public static function is_syncing() {
		return self::$depth > 0;
	}

	/**
	 * UUID of the record being created by the current op.
	 *
	 * @var string|null
	 */
	private static $uuid = null;

	/**
	 * Run a callable while exposing the client UUID of the record being created.
	 * Adapters may reuse it as their own public identifier.
	 *
	 * @param string   $uuid     Client UUID.
	 * @param callable $callback Work to do.
	 * @return mixed
	 * @throws \Throwable Re-thrown after the UUID is cleared.
	 */
	public static function with_uuid( $uuid, $callback ) {
		$previous   = self::$uuid;
		self::$uuid = $uuid;
		try {
			return call_user_func( $callback );
		} finally {
			self::$uuid = $previous;
		}
	}

	/**
	 * UUID of the record being created right now, or null.
	 *
	 * @return string|null
	 */
	public static function current_uuid() {
		return self::$uuid;
	}

	/**
	 * Run a callable inside the sync context. The flag is always cleared afterwards.
	 *
	 * @param callable $callback Work to do.
	 * @return mixed
	 * @throws \Throwable Re-thrown after the flag is cleared.
	 */
	public static function run( $callback ) {
		++self::$depth;
		if ( 1 === self::$depth ) {
			/**
			 * Fires when a sync operation starts. Add side-effect suppression here.
			 */
			do_action( 'pdock_sync_started' );
		}
		try {
			return call_user_func( $callback );
		} finally {
			--self::$depth;
			if ( 0 === self::$depth ) {
				/**
				 * Fires when the sync operation has finished.
				 */
				do_action( 'pdock_sync_finished' );
			}
		}
	}
}
