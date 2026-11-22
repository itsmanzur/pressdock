<?php
/**
 * Integer ids for Kafela packages.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kafela identifies a package by an 8 character string, Pressdock needs an integer object id.
 */
final class PackageIds {

	const OPTION = 'pdock_kafela_package_ids';

	/**
	 * The shared map implementation.
	 *
	 * @return StringIdMap
	 */
	private static function map() {
		return new StringIdMap( self::OPTION );
	}

	/**
	 * Integer id of a package slug.
	 *
	 * @param string $slug   Kafela package id.
	 * @param bool   $create Allocate a number when the slug is new.
	 * @return int 0 when unknown and not created.
	 */
	public static function id_for( $slug, $create = true ) {
		return self::map()->id_for( $slug, $create );
	}

	/**
	 * Package slug of an integer id.
	 *
	 * @param int $id Integer id.
	 * @return string Empty when unknown.
	 */
	public static function slug_for( $id ) {
		return self::map()->slug_for( $id );
	}
}
