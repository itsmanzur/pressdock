<?php
/**
 * Idempotency log ({prefix}pdock_ops).
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
 * Remembers the result of each applied op so a retried batch never applies twice.
 */
final class Ops {

	/**
	 * Stored result for an op id, or null.
	 *
	 * @param string $op_id Op id.
	 * @return array|null
	 */
	public static function find( $op_id ) {
		global $wpdb;
		$table = Installer::table( 'ops' );
		$json  = $wpdb->get_var( $wpdb->prepare( "SELECT result FROM {$table} WHERE op_id = %s", $op_id ) );
		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Store a result.
	 *
	 * @param string $op_id    Op id.
	 * @param string $batch_id Batch id.
	 * @param string $entity   Entity key.
	 * @param string $op       create|update|delete.
	 * @param array  $result   Result array returned to the client.
	 * @return void
	 */
	public static function save( $op_id, $batch_id, $entity, $op, array $result ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}pdock_ops (op_id, batch_id, entity, op, status, user_id, result, created_at) VALUES (%s, %s, %s, %s, %s, %d, %s, %s)",
				$op_id,
				$batch_id,
				$entity,
				$op,
				isset( $result['status'] ) ? (string) $result['status'] : '',
				get_current_user_id(),
				wp_json_encode( $result ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	/**
	 * Recent batches for the admin activity table.
	 *
	 * @param int $limit Max batches.
	 * @return array
	 */
	public static function recent_batches( $limit = 20 ) {
		global $wpdb;
		$ops  = Installer::table( 'ops' );
		$snap = Installer::table( 'snapshots' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.batch_id, MIN(o.created_at) AS created_at, MAX(o.user_id) AS user_id, COUNT(*) AS total,
					SUM(o.status = 'applied') AS applied, SUM(o.status = 'conflict') AS conflicts,
					(SELECT COUNT(*) FROM {$snap} s WHERE s.batch_id = o.batch_id) AS undoable
				FROM {$ops} o WHERE o.batch_id <> '' GROUP BY o.batch_id ORDER BY MIN(o.created_at) DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete ops older than N days.
	 *
	 * @param int $days Age in days.
	 * @return void
	 */
	public static function prune( $days ) {
		global $wpdb;
		$table = Installer::table( 'ops' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( (int) $days * DAY_IN_SECONDS ) )
			)
		);
	}
}
