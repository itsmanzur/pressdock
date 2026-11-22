<?php
/**
 * Pre-change snapshots for undo ({prefix}pdock_snapshots).
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Pressdock\Store;

use Pressdock\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores the state of a record before a sync op changed it.
 */
final class Snapshots {

	/**
	 * Save a snapshot.
	 *
	 * @param string     $batch_id  Batch id.
	 * @param string     $entity    Entity key.
	 * @param int        $object_id Object id.
	 * @param string     $op        create|update|delete.
	 * @param array|null $before    State before the change (null for creates).
	 * @return void
	 */
	public static function add( $batch_id, $entity, $object_id, $op, $before ) {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'snapshots' ),
			array(
				'batch_id'    => $batch_id,
				'entity'      => $entity,
				'object_id'   => $object_id,
				'op'          => $op,
				'before_json' => wp_json_encode( $before ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Snapshots of a batch, newest first (the order undo must run in).
	 *
	 * @param string $batch_id Batch id.
	 * @return array
	 */
	public static function for_batch( $batch_id ) {
		global $wpdb;
		$table = Installer::table( 'snapshots' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s ORDER BY id DESC", $batch_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove one snapshot.
	 *
	 * @param int $id Snapshot id.
	 * @return void
	 */
	public static function remove( $id ) {
		global $wpdb;
		$wpdb->delete( Installer::table( 'snapshots' ), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete snapshots older than N days.
	 *
	 * @param int $days Age in days.
	 * @return void
	 */
	public static function prune( $days ) {
		global $wpdb;
		$table = Installer::table( 'snapshots' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) )
			)
		);
	}
}
