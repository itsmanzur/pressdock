<?php
/**
 * Integer ids for Kafela Pro ledger rows (their primary key is a string like "l-12-ab3dEf").
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Same idea as PackageIds, own option.
 */
final class LedgerIds {

	const OPTION = 'pdock_kafela_ledger_ids';

	/**
	 * Map.
	 *
	 * @return StringIdMap
	 */
	public static function map() {
		return new StringIdMap( self::OPTION );
	}

	/**
	 * Integer id of a ledger row id.
	 *
	 * @param string $slug   Ledger id.
	 * @param bool   $create Allocate when new.
	 * @return int
	 */
	public static function id_for( $slug, $create = true ) {
		return self::map()->id_for( $slug, $create );
	}

	/**
	 * Ledger row id of an integer.
	 *
	 * @param int $id Integer id.
	 * @return string
	 */
	public static function slug_for( $id ) {
		return self::map()->slug_for( $id );
	}
}
