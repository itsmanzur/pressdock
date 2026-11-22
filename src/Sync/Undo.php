<?php
/**
 * Restores the snapshots of a batch.
 *
 * @package Pressdock
 */

namespace Pressdock\Sync;

use Pressdock\Context;
use Pressdock\Contracts\RestorableAdapter;
use Pressdock\Registry;
use Pressdock\Store\Records;
use Pressdock\Store\Snapshots;
use Pressdock\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs newest snapshot first so chained changes unwind in order.
 */
final class Undo {

	/**
	 * Undo a batch.
	 *
	 * @param string $batch_id Batch id.
	 * @return array array( 'restored' => int, 'errors' => array )
	 */
	public static function run( $batch_id ) {
		$restored = 0;
		$errors   = array();

		foreach ( Snapshots::for_batch( $batch_id ) as $snap ) {
			$adapter = Registry::all( true );
			$adapter = isset( $adapter[ $snap['entity'] ] ) ? $adapter[ $snap['entity'] ] : null;
			if ( ! $adapter ) {
				$errors[] = array(
					'snapshot' => (int) $snap['id'],
					'message'  => 'Entity is no longer registered.',
				);
				continue;
			}

			try {
				Context::run(
					static function () use ( $adapter, $snap ) {
						self::restore_one( $adapter, $snap );
					}
				);
				Snapshots::remove( (int) $snap['id'] );
				++$restored;
			} catch ( \Throwable $e ) {
				$errors[] = array(
					'snapshot' => (int) $snap['id'],
					'message'  => $e->getMessage(),
				);
			}
		}

		return array(
			'restored' => $restored,
			'errors'   => $errors,
		);
	}

	/**
	 * Restore a single snapshot.
	 *
	 * @param \Pressdock\Contracts\EntityAdapter $adapter Adapter.
	 * @param array                              $snap    Snapshot row.
	 * @return void
	 * @throws \RuntimeException When the record cannot be restored.
	 */
	private static function restore_one( $adapter, array $snap ) {
		$entity = $snap['entity'];
		$id     = (int) $snap['object_id'];
		$before = json_decode( $snap['before_json'], true );
		$schema = $adapter->schema();

		if ( 'create' === $snap['op'] ) {
			$adapter->delete( $id, true );
			Tracker::touch( $entity, $id, 'delete', 'sync' );
			return;
		}

		if ( ! is_array( $before ) ) {
			throw new \RuntimeException( 'Snapshot is empty.' );
		}
		$data = Validator::clean( $schema, $before );

		if ( 'update' === $snap['op'] ) {
			$adapter->update( $id, $data );
			Tracker::touch( $entity, $id, 'upsert', 'sync' );
			return;
		}

		// A delete: bring the record back, in place when the adapter can.
		if ( $adapter instanceof RestorableAdapter && $adapter->restore( $id, $before ) ) {
			Tracker::touch( $entity, $id, 'upsert', 'sync' );
			return;
		}

		$old = Records::find( $entity, $id );
		$new = (int) $adapter->create( $data );
		if ( $new < 1 ) {
			throw new \RuntimeException( 'Could not re-create the record.' );
		}
		if ( $old ) {
			Records::rebind( $entity, $old['uuid'], $new );
		}
		Tracker::touch( $entity, $new, 'upsert', 'sync' );
	}
}
