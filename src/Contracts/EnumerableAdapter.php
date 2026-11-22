<?php
/**
 * Optional contract: adapters that can list every existing id (used for the first sync).
 *
 * @package Pressdock
 */

namespace Pressdock\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records that existed before the connector was installed have no change-log row.
 * The baseline walks these ids once so an initial pull (since=0) returns them too.
 * Both bundled base classes implement this.
 */
interface EnumerableAdapter {

	/**
	 * Object ids greater than $after, ascending.
	 *
	 * @param int $after Last id already seen (0 to start).
	 * @param int $limit Max ids.
	 * @return int[]
	 */
	public function ids_after( $after, $limit );
}
