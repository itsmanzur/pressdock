<?php
/**
 * Applies a batch of client operations.
 *
 * @package Pressdock
 */

namespace Pressdock\Sync;

use Pressdock\Context;
use Pressdock\Contracts\EntityAdapter;
use Pressdock\Registry;
use Pressdock\Store\Ops;
use Pressdock\Store\Records;
use Pressdock\Store\Snapshots;
use Pressdock\Tracker;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per op: capability check, idempotency, optimistic concurrency, snapshot, apply.
 * One failing op never aborts the others.
 */
final class PushProcessor {

	const MAX_OPS = 100;

	/**
	 * Apply a batch.
	 *
	 * @param string $batch_id Batch id.
	 * @param array  $ops      Operations in order.
	 * @return array List of per-op results, same order.
	 */
	public static function run( $batch_id, array $ops ) {
		$results = array();
		foreach ( self::order( $ops ) as $index ) {
			$op = $ops[ $index ];
			try {
				$results[ $index ] = self::process( $batch_id, is_array( $op ) ? $op : array() );
			} catch ( \Throwable $e ) {
				$results[ $index ] = self::error( is_array( $op ) && isset( $op['op_id'] ) ? $op['op_id'] : null, 'pdock_internal_error', $e->getMessage() );
			}
		}
		ksort( $results );
		return array_values( $results );
	}

	/**
	 * Execution order: an op that references (through a relation field) the uuid of a record
	 * created in the same batch runs after that create. Everything else keeps its position.
	 * The result list is always returned in the original request order.
	 *
	 * @param array $ops Operations.
	 * @return int[] Indexes in execution order.
	 */
	private static function order( array $ops ) {
		$count   = count( $ops );
		$creates = array();
		foreach ( $ops as $i => $op ) {
			if ( is_array( $op ) && isset( $op['op'], $op['uuid'] ) && 'create' === $op['op'] && is_string( $op['uuid'] ) ) {
				$creates[ strtolower( $op['uuid'] ) ] = $i;
			}
		}

		$deps = array_fill( 0, $count, array() );
		foreach ( $ops as $i => $op ) {
			if ( ! is_array( $op ) || empty( $op['entity'] ) || ! isset( $op['data'] ) || ! is_array( $op['data'] ) || ! in_array( isset( $op['op'] ) ? $op['op'] : '', array( 'create', 'update' ), true ) ) {
				continue;
			}
			$adapter = is_string( $op['entity'] ) ? Registry::get( $op['entity'] ) : null;
			if ( ! $adapter ) {
				continue;
			}
			foreach ( $adapter->schema() as $name => $field ) {
				if ( isset( $field['type'] ) && 'relation' === $field['type'] && isset( $op['data'][ $name ] ) && is_string( $op['data'][ $name ] ) ) {
					$ref = strtolower( $op['data'][ $name ] );
					if ( isset( $creates[ $ref ] ) && $creates[ $ref ] !== $i ) {
						$deps[ $i ][] = $creates[ $ref ];
					}
				}
			}
		}

		$done   = array();
		$order  = array();
		$placed = 0;
		while ( $placed < $count ) {
			$progress = false;
			foreach ( range( 0, $count - 1 ) as $i ) {
				if ( isset( $done[ $i ] ) ) {
					continue;
				}
				if ( array() === array_diff( $deps[ $i ], array_keys( $done ) ) ) {
					$order[]    = $i;
					$done[ $i ] = true;
					$progress   = true;
					++$placed;
				}
			}
			if ( ! $progress ) { // Circular references: keep the original order for the rest.
				foreach ( range( 0, $count - 1 ) as $i ) {
					if ( ! isset( $done[ $i ] ) ) {
						$order[] = $i;
					}
				}
				break;
			}
		}
		return $order;
	}

	/**
	 * Process one op.
	 *
	 * @param string $batch_id Batch id.
	 * @param array  $op       Operation.
	 * @return array
	 */
	private static function process( $batch_id, array $op ) {
		$op_id = isset( $op['op_id'] ) && is_string( $op['op_id'] ) ? $op['op_id'] : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,36}$/', $op_id ) ) {
			return self::error( null, 'pdock_bad_op_id', 'op_id must be 8-36 characters of letters, digits and dashes.' );
		}

		$stored = Ops::find( $op_id );
		if ( null !== $stored ) {
			$stored['replayed'] = true;
			return $stored;
		}

		$type   = isset( $op['op'] ) ? $op['op'] : '';
		$entity = isset( $op['entity'] ) && is_string( $op['entity'] ) ? $op['entity'] : '';
		if ( ! in_array( $type, array( 'create', 'update', 'delete' ), true ) ) {
			return self::error( $op_id, 'pdock_bad_op', 'op must be create, update or delete.' );
		}
		$adapter = Registry::get( $entity );
		if ( ! $adapter ) {
			return self::error( $op_id, 'pdock_unknown_entity', 'Unknown or disabled entity.' );
		}
		if ( ! Registry::user_can( $adapter, $type ) ) {
			return self::error( $op_id, 'pdock_forbidden', 'You are not allowed to do this.' );
		}

		$result = Context::run(
			static function () use ( $op_id, $type, $adapter, $op, $batch_id ) {
				switch ( $type ) {
					case 'create':
						return self::do_create( $op_id, $adapter, $op, $batch_id );
					case 'update':
						return self::do_update( $op_id, $adapter, $op, $batch_id );
					default:
						return self::do_delete( $op_id, $adapter, $op, $batch_id );
				}
			}
		);

		if ( in_array( $result['status'], array( 'applied', 'conflict' ), true ) ) {
			Ops::save( $op_id, $batch_id, $entity, $type, $result );
		}
		return $result;
	}

	/**
	 * Create.
	 *
	 * @param string        $op_id    Op id.
	 * @param EntityAdapter $adapter  Adapter.
	 * @param array         $op       Operation.
	 * @param string        $batch_id Batch id.
	 * @return array
	 */
	private static function do_create( $op_id, EntityAdapter $adapter, array $op, $batch_id ) {
		$entity = $adapter->key();
		$uuid   = isset( $op['uuid'] ) && is_string( $op['uuid'] ) ? strtolower( $op['uuid'] ) : '';
		if ( ! Validator::is_uuid( $uuid ) ) {
			return self::error( $op_id, 'pdock_bad_uuid', 'create needs a valid uuid.' );
		}

		$existing = Records::find_by_uuid( $uuid );
		if ( $existing ) {
			if ( $existing['entity'] === $entity && empty( $existing['deleted'] ) ) {
				return self::applied( $op_id, (int) $existing['object_id'], (int) $existing['revision'] );
			}
			return self::error( $op_id, 'pdock_uuid_in_use', 'This uuid is already used by another or a deleted record.' );
		}

		// The uuid is visible to the adapter during validation and creation (for uniqueness checks and public ids).
		$data = isset( $op['data'] ) ? $op['data'] : null;
		try {
			$id = Context::with_uuid(
				$uuid,
				static function () use ( $adapter, $data ) {
					$prepared = self::prepare( $adapter, $data, null );
					if ( $prepared instanceof WP_Error ) {
						return $prepared;
					}
					return (int) $adapter->create( $prepared );
				}
			);
		} catch ( \Throwable $e ) {
			return self::error( $op_id, 'pdock_adapter_error', $e->getMessage() );
		}
		if ( $id instanceof WP_Error ) {
			return self::from_wp_error( $op_id, $id, $adapter );
		}
		if ( $id < 1 ) {
			return self::error( $op_id, 'pdock_adapter_error', 'The adapter did not create a record.' );
		}

		Records::ensure( $entity, $id, $uuid );
		Snapshots::add( $batch_id, $entity, $id, 'create', null );
		$revision = Tracker::touch( $entity, $id, 'upsert', 'sync' );

		return self::applied( $op_id, $id, $revision );
	}

	/**
	 * Update.
	 *
	 * @param string        $op_id    Op id.
	 * @param EntityAdapter $adapter  Adapter.
	 * @param array         $op       Operation.
	 * @param string        $batch_id Batch id.
	 * @return array
	 */
	private static function do_update( $op_id, EntityAdapter $adapter, array $op, $batch_id ) {
		$entity = $adapter->key();
		$target = self::locate( $op_id, $entity, $op );
		if ( is_array( $target ) && isset( $target['status'] ) ) {
			return $target;
		}
		$id = $target;

		$guard = self::guard( $op_id, $adapter, $id, $op, 'update' );
		if ( null !== $guard ) {
			return $guard;
		}

		$prepared = self::prepare( $adapter, isset( $op['data'] ) ? $op['data'] : null, $id );
		if ( $prepared instanceof WP_Error ) {
			return self::from_wp_error( $op_id, $prepared, $adapter );
		}

		Snapshots::add( $batch_id, $entity, $id, 'update', $adapter->get( $id ) );
		try {
			$adapter->update( $id, $prepared );
		} catch ( \Throwable $e ) {
			return self::error( $op_id, 'pdock_adapter_error', $e->getMessage() );
		}
		$revision = Tracker::touch( $entity, $id, 'upsert', 'sync' );

		return self::applied( $op_id, $id, $revision );
	}

	/**
	 * Delete.
	 *
	 * @param string        $op_id    Op id.
	 * @param EntityAdapter $adapter  Adapter.
	 * @param array         $op       Operation.
	 * @param string        $batch_id Batch id.
	 * @return array
	 */
	private static function do_delete( $op_id, EntityAdapter $adapter, array $op, $batch_id ) {
		$entity = $adapter->key();
		$target = self::locate( $op_id, $entity, $op );
		if ( is_array( $target ) && isset( $target['status'] ) ) {
			return $target;
		}
		$id = $target;

		$row = Records::find( $entity, $id );
		if ( $row && ! empty( $row['deleted'] ) && isset( $op['base_revision'] ) && (int) $op['base_revision'] === (int) $row['revision'] ) {
			return self::applied( $op_id, $id, (int) $row['revision'] );
		}

		$guard = self::guard( $op_id, $adapter, $id, $op, 'delete' );
		if ( null !== $guard ) {
			return $guard;
		}

		Snapshots::add( $batch_id, $entity, $id, 'delete', $adapter->get( $id ) );
		try {
			$adapter->delete( $id, ! empty( $op['force'] ) );
		} catch ( \Throwable $e ) {
			return self::error( $op_id, 'pdock_adapter_error', $e->getMessage() );
		}
		$revision = Tracker::touch( $entity, $id, 'delete', 'sync' );

		return self::applied( $op_id, $id, $revision );
	}

	/**
	 * Resolve remote_id or uuid to an object id.
	 *
	 * @param string $op_id  Op id.
	 * @param string $entity Entity key.
	 * @param array  $op     Operation.
	 * @return int|array Object id, or an error result array.
	 */
	private static function locate( $op_id, $entity, array $op ) {
		$id = 0;
		if ( isset( $op['remote_id'] ) && is_numeric( $op['remote_id'] ) ) {
			$id = (int) $op['remote_id'];
		} elseif ( isset( $op['uuid'] ) && is_string( $op['uuid'] ) ) {
			$row = Records::find_by_uuid( strtolower( $op['uuid'] ) );
			if ( $row && $row['entity'] === $entity ) {
				$id = (int) $row['object_id'];
			}
		}
		if ( $id < 1 ) {
			return self::error( $op_id, 'pdock_not_found', 'Record not found. Send remote_id or a known uuid.' );
		}
		return $id;
	}

	/**
	 * Permission, existence and revision checks shared by update and delete.
	 *
	 * @param string        $op_id   Op id.
	 * @param EntityAdapter $adapter Adapter.
	 * @param int           $id      Object id.
	 * @param array         $op      Operation.
	 * @param string        $action  update|delete.
	 * @return array|null Result to return, or null to continue.
	 */
	private static function guard( $op_id, EntityAdapter $adapter, $id, array $op, $action ) {
		if ( method_exists( $adapter, 'can_object' ) && ! $adapter->can_object( $action, $id ) ) {
			return self::error( $op_id, 'pdock_forbidden', 'You are not allowed to change this record.' );
		}
		if ( ! isset( $op['base_revision'] ) || ! is_numeric( $op['base_revision'] ) ) {
			return self::error( $op_id, 'pdock_base_revision_required', 'update and delete need base_revision.' );
		}

		$entity  = $adapter->key();
		$row     = Records::ensure( $entity, $id );
		$current = $row ? (int) $row['revision'] : 0;
		$exists  = null !== $adapter->get( $id );

		if ( ! $exists && ( ! $row || empty( $row['deleted'] ) ) ) {
			return self::error( $op_id, 'pdock_not_found', 'Record not found.' );
		}
		if ( (int) $op['base_revision'] !== $current || ( $row && ! empty( $row['deleted'] ) ) ) {
			return array(
				'op_id'     => $op_id,
				'status'    => 'conflict',
				'remote_id' => $id,
				'revision'  => $current,
				'server'    => Presenter::record( $adapter, $id ),
			);
		}
		return null;
	}

	/**
	 * Resolve relations, sanitize and validate.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @param mixed         $data    Raw data.
	 * @param int|null      $id      Object id for updates.
	 * @return array|WP_Error Clean data or error.
	 */
	private static function prepare( EntityAdapter $adapter, $data, $id ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'pdock_missing_data', 'data must be an object.' );
		}
		$schema = $adapter->schema();

		// Adapter hook: canonicalize aliases (for example translated labels) before validation.
		if ( method_exists( $adapter, 'normalize' ) ) {
			$data = $adapter->normalize( $data, $id );
		}

		$readonly = Validator::readonly_violations( $schema, $data, null === $id ? null : $adapter->get( $id ), null === $id );
		if ( is_wp_error( $readonly ) ) {
			return $readonly;
		}

		foreach ( $schema as $name => $field ) {
			$is_relation = isset( $field['type'] ) && 'relation' === $field['type'];
			if ( $is_relation && isset( $data[ $name ] ) && is_string( $data[ $name ] ) && ! is_numeric( $data[ $name ] ) ) {
				$target = isset( $field['target'] ) ? $field['target'] : '';
				$rid    = Validator::is_uuid( $data[ $name ] ) ? Records::object_id_for_uuid( $target, strtolower( $data[ $name ] ) ) : 0;
				if ( $rid < 1 ) {
					return new WP_Error( 'pdock_unresolved_relation', sprintf( 'Field "%s" points to an unknown record.', $name ), array( 'field' => $name ) );
				}
				$data[ $name ] = $rid;
			}
		}

		$partial = null !== $id;
		$clean   = Validator::clean( $schema, $data );
		$valid   = Validator::validate( $schema, $data, $clean, $partial );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$business = $adapter->validate( $clean, $id );
		if ( is_wp_error( $business ) ) {
			return $business;
		}
		return $clean;
	}

	/**
	 * Applied result.
	 *
	 * @param string $op_id    Op id.
	 * @param int    $remote   Object id.
	 * @param int    $revision Revision.
	 * @return array
	 */
	private static function applied( $op_id, $remote, $revision ) {
		return array(
			'op_id'     => $op_id,
			'status'    => 'applied',
			'remote_id' => (int) $remote,
			'revision'  => (int) $revision,
		);
	}

	/**
	 * Error result.
	 *
	 * @param string|null $op_id   Op id.
	 * @param string      $code    Error code.
	 * @param string      $message Message.
	 * @param array       $extra   Extra data.
	 * @return array
	 */
	private static function error( $op_id, $code, $message, array $extra = array() ) {
		return array(
			'op_id'  => $op_id,
			'status' => 'error',
			'error'  => array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
		);
	}

	/**
	 * Convert a WP_Error to an error result.
	 *
	 * @param string        $op_id   Op id.
	 * @param WP_Error      $err     Error.
	 * @param EntityAdapter $adapter Adapter (needed to describe a uniqueness conflict).
	 * @return array
	 */
	private static function from_wp_error( $op_id, WP_Error $err, $adapter = null ) {
		$extra = array();
		$data  = $err->get_error_data();
		if ( is_array( $data ) && isset( $data['field'] ) ) {
			$extra['field'] = $data['field'];
		}

		// A uniqueness violation is reported like a conflict, with the record that already holds the value.
		if ( is_array( $data ) && ! empty( $data['unique'] ) && $adapter instanceof EntityAdapter ) {
			$result = array(
				'op_id'  => $op_id,
				'status' => 'conflict',
				'reason' => 'unique',
				'field'  => isset( $data['field'] ) ? $data['field'] : null,
				'error'  => array(
					'code'    => $err->get_error_code(),
					'message' => $err->get_error_message(),
				),
			);
			if ( ! empty( $data['existing_id'] ) ) {
				$existing            = Presenter::record( $adapter, (int) $data['existing_id'] );
				$result['remote_id'] = (int) $data['existing_id'];
				$result['revision']  = $existing['revision'];
				$result['server']    = $existing;
			}
			return $result;
		}
		$messages = $err->get_error_messages();
		if ( count( $messages ) > 1 ) {
			$extra['messages'] = $messages;
		}
		return self::error( $op_id, $err->get_error_code(), $err->get_error_message(), $extra );
	}
}
