<?php
/**
 * UUID, revision and tombstone store ({prefix}pdock_records).
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Pressdock\Store;

use Pressdock\Installer;
use Pressdock\Registry;
use Pressdock\Sync\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps client UUIDs to WordPress ids and holds the per-record revision.
 */
final class Records {

	/**
	 * Row by entity and object id.
	 *
	 * @param string $entity    Entity key.
	 * @param int    $object_id Object id.
	 * @return array|null
	 */
	public static function find( $entity, $object_id ) {
		global $wpdb;
		$table = Installer::table( 'records' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entity = %s AND object_id = %d", $entity, $object_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Row by UUID.
	 *
	 * @param string $uuid UUID.
	 * @return array|null
	 */
	public static function find_by_uuid( $uuid ) {
		global $wpdb;
		$table = Installer::table( 'records' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Current revision (0 when the record is unknown).
	 *
	 * @param string $entity    Entity key.
	 * @param int    $object_id Object id.
	 * @return int
	 */
	public static function revision( $entity, $object_id ) {
		$row = self::find( $entity, $object_id );
		return $row ? (int) $row['revision'] : 0;
	}

	/**
	 * Make sure a record row exists. A UUID is generated unless one is given.
	 *
	 * @param string      $entity    Entity key.
	 * @param int         $object_id Object id.
	 * @param string|null $uuid      Client UUID to bind.
	 * @return array|null Row, or null when the UUID is already bound to another object.
	 */
	public static function ensure( $entity, $object_id, $uuid = null ) {
		global $wpdb;

		$row = self::find( $entity, $object_id );
		if ( $row ) {
			return $row;
		}

		if ( ! $uuid ) {
			// An adapter whose records already carry a uuid (optional preferred_uuid()) keeps it.
			$adapters = Registry::all( true );
			if ( isset( $adapters[ $entity ] ) && method_exists( $adapters[ $entity ], 'preferred_uuid' ) ) {
				$preferred = $adapters[ $entity ]->preferred_uuid( $object_id );
				if ( is_string( $preferred ) && Validator::is_uuid( $preferred ) && ! self::find_by_uuid( strtolower( $preferred ) ) ) {
					$uuid = strtolower( $preferred );
				}
			}
		}
		$uuid = $uuid ? $uuid : wp_generate_uuid4();
		if ( self::find_by_uuid( $uuid ) ) {
			return null;
		}

		$table = Installer::table( 'records' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (entity, object_id, uuid, revision, deleted, updated_at) VALUES (%s, %d, %s, 0, 0, %s)",
				$entity,
				$object_id,
				$uuid,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return self::find( $entity, $object_id );
	}

	/**
	 * Increment the revision and set the tombstone flag. Returns the new revision.
	 *
	 * @param string $entity    Entity key.
	 * @param int    $object_id Object id.
	 * @param bool   $deleted   Tombstone flag.
	 * @return int
	 */
	public static function bump( $entity, $object_id, $deleted ) {
		global $wpdb;

		self::ensure( $entity, $object_id );
		$table = Installer::table( 'records' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revision = revision + 1, deleted = %d, updated_at = %s WHERE entity = %s AND object_id = %d",
				$deleted ? 1 : 0,
				gmdate( 'Y-m-d H:i:s' ),
				$entity,
				$object_id
			)
		);
		return self::revision( $entity, $object_id );
	}

	/**
	 * Point an existing UUID at a new object id (undo of a delete that had to re-create).
	 *
	 * @param string $entity    Entity key.
	 * @param string $uuid      UUID.
	 * @param int    $object_id New object id.
	 * @return void
	 */
	public static function rebind( $entity, $uuid, $object_id ) {
		global $wpdb;
		$table = Installer::table( 'records' );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET object_id = %d, deleted = 0 WHERE uuid = %s AND entity = %s",
				$object_id,
				$uuid,
				$entity
			)
		);
	}

	/**
	 * Object ids of an entity that are not tombstoned.
	 *
	 * @param string $entity Entity key.
	 * @return int[]
	 */
	public static function live_ids( $entity ) {
		global $wpdb;
		$table = Installer::table( 'records' );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$table} WHERE entity = %s AND deleted = 0", $entity ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Resolve a UUID to an object id for an entity.
	 *
	 * @param string $entity Entity key.
	 * @param string $uuid   UUID.
	 * @return int 0 when unknown or deleted.
	 */
	public static function object_id_for_uuid( $entity, $uuid ) {
		$row = self::find_by_uuid( $uuid );
		if ( ! $row || $row['entity'] !== $entity || ! empty( $row['deleted'] ) ) {
			return 0;
		}
		return (int) $row['object_id'];
	}
}
