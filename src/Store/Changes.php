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
	 * Rows after a cursor, oldest first.
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
		$sql          = "SELECT * FROM {$table} WHERE id > %d AND entity IN ({$placeholders}) ORDER BY id ASC LIMIT %d";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return is_array( $rows ) ? $rows : array();
	}
}
