<?php
/**
 * Change tracker. Every touch bumps the record revision and appends a cursor row.
 *
 * @package Pressdock
 */

namespace Pressdock;

use Pressdock\Store\Changes;
use Pressdock\Store\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Entry point for adapters and the push processor.
 */
final class Tracker {

	/**
	 * Record that an object changed.
	 *
	 * @param string      $entity Entity key.
	 * @param int         $id     Object id.
	 * @param string      $action upsert|delete.
	 * @param string|null $source admin|sync|other. Detected when null.
	 * @return int New revision (0 when the arguments were invalid).
	 */
	public static function touch( $entity, $id, $action = 'upsert', $source = null ) {
		$id = (int) $id;
		if ( $id < 1 || ! is_string( $entity ) || '' === $entity ) {
			return 0;
		}
		$action = 'delete' === $action ? 'delete' : 'upsert';

		if ( null === $source ) {
			if ( Context::is_syncing() ) {
				$source = 'sync';
			} elseif ( is_admin() ) {
				$source = 'admin';
			} else {
				$source = 'other';
			}
		}
		if ( ! in_array( $source, array( 'admin', 'sync', 'other' ), true ) ) {
			$source = 'other';
		}

		// Records::ensure() honours an adapter's optional preferred_uuid() when it creates the row.
		$revision = Records::bump( $entity, $id, 'delete' === $action );
		Changes::add( $entity, $id, $action, $revision, $source );

		/**
		 * Fires after a change is recorded.
		 *
		 * @param string $entity   Entity key.
		 * @param int    $id       Object id.
		 * @param string $action   upsert|delete.
		 * @param int    $revision New revision.
		 * @param string $source   admin|sync|other.
		 */
		do_action( 'pdock_tracked', $entity, $id, $action, $revision, $source );

		return $revision;
	}
}
