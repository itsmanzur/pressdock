<?php
/**
 * Change log ({prefix}pdock_changes). The row id is the sync cursor.
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

namespace Pressdock\Store;

use Pressdock\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only list of upserts and deletes.
 */
final class Changes {

	/**
	 * Append a change and return its cursor id.
	 *
	 * @param string $entity    Entity key.
	 * @param int    $object_id Object id.
	 * @param string $action    upsert|delete.
	 * @param int    $revision  Revision after the change.
	 * @param string $source    admin|sync|other.
	 * @return int
	 */
	public static function add( $entity, $object_id, $action, $revision, $source ) {
		global $wpdb;
		$wpdb->insert(
			Installer::table( 'changes' ),
			array(
				'entity'     => $entity,
				'object_id'  => $object_id,
				'action'     => $action,
				'revision'   => $revision,
				'changed_at' => gmdate( 'Y-m-d H:i:s' ),
				'source'     => $source,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Highest cursor currently in the log.
	 *
	 * @return int
	 */
	public static function max_id() {
		global $wpdb;
		$table = Installer::table( 'changes' );
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * Whether a record already has a change row.
	 *
	 * @param string $entity    Entity key.
	 * @param int    $object_id Object id.
	 * @return bool
	 */
	public static function has( $entity, $object_id ) {
		global $wpdb;
		$table = Installer::table( 'changes' );
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE entity = %s AND object_id = %d LIMIT 1", $entity, (int) $object_id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Rows after a cursor, oldest first. A record shows only at its latest change row, so one cursor walk never repeats it.
	 *
	 * @param int      $since    Cursor.
	 * @param string[] $entities Entity keys to include.
	 * @param int      $limit    Max rows.
	 * @return array
	 */
	public static function since( $since, array $entities, $limit ) {
		global $wpdb;
		if ( array() === $entities ) {
			return array();
		}
		$table        = Installer::table( 'changes' );
		$placeholders = implode( ',', array_fill( 0, count( $entities ), '%s' ) );
		$args         = array_merge( array( (int) $since ), array_values( $entities ), array( (int) $limit ) );
		$sql          = "SELECT c.* FROM {$table} c WHERE c.id > %d AND c.entity IN ({$placeholders}) AND NOT EXISTS ( SELECT 1 FROM {$table} n WHERE n.entity = c.entity AND n.object_id = c.object_id AND n.id > c.id ) ORDER BY c.id ASC LIMIT %d";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return is_array( $rows ) ? $rows : array();
	}
}
