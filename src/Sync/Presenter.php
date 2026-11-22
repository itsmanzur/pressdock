<?php
/**
 * Builds the JSON shapes returned by the REST API.
 *
 * @package Pressdock
 */

namespace Pressdock\Sync;

use Pressdock\Contracts\EntityAdapter;
use Pressdock\Store\Records;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared by pull, push (conflict payloads) and entities.
 */
final class Presenter {

	/**
	 * Schema for the client: internal keys (storage, column) are removed.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @return array
	 */
	public static function schema( EntityAdapter $adapter ) {
		$out = array();
		foreach ( $adapter->schema() as $name => $field ) {
			unset( $field['storage'], $field['column'] );
			$out[ $name ] = array_merge(
				array(
					'type'     => 'string',
					'label'    => $name,
					'required' => false,
					'readonly' => false,
				),
				$field
			);
		}
		return $out;
	}

	/**
	 * One record as the client sees it.
	 *
	 * @param EntityAdapter $adapter Adapter.
	 * @param int           $id      Object id.
	 * @param array|null    $data    Record data (fetched when omitted).
	 * @return array
	 */
	public static function record( EntityAdapter $adapter, $id, $data = null ) {
		$entity = $adapter->key();
		$row    = Records::ensure( $entity, (int) $id );
		if ( null === $data ) {
			$data = $adapter->get( (int) $id );
		}
		$deleted = null === $data || ( $row && ! empty( $row['deleted'] ) );
		if ( is_array( $data ) ) {
			unset( $data['id'] );
		}

		return array(
			'entity'    => $entity,
			'remote_id' => (int) $id,
			'uuid'      => $row ? $row['uuid'] : null,
			'revision'  => $row ? (int) $row['revision'] : 0,
			'deleted'   => (bool) $deleted,
			'data'      => $deleted ? null : $data,
		);
	}
}
