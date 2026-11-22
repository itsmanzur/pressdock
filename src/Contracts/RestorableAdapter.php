<?php
/**
 * Optional contract: adapters that can bring a deleted record back (undo).
 *
 * @package Pressdock
 */

namespace Pressdock\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Without it, undo of a delete re-creates the record under a new id.
 */
interface RestorableAdapter {

	/**
	 * Restore a deleted or trashed record to the given state.
	 *
	 * @param int   $id     Original object id.
	 * @param array $before Snapshot data.
	 * @return bool True when restored in place.
	 */
	public function restore( $id, array $before );
}
