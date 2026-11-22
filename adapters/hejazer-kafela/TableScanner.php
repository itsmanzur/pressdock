<?php
/**
 * Change detection for tables whose owning plugin fires no hooks.
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Pressdock\Adapters\Kafela;

use Pressdock\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a fingerprint (hash of the whole row) per record. refresh() compares it with the table and
 * touches records that were added, changed or removed since, so edits made in the owning plugin's
 * screens still reach the change log. Runs before a pull, at most once per $throttle seconds.
 *
 * The first run only records fingerprints: records that already exist reach the change log through the
 * baseline walk, not through this scan.
 */
final class TableScanner {

	/**
	 * Entity key.
	 *
	 * @var string
	 */
	private $entity;

	/**
	 * Table name without the prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Primary key column.
	 *
	 * @var string
	 */
	private $pk;

	/**
	 * Map for string primary keys, or null for integer keys.
	 *
	 * @var StringIdMap|null
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param string           $entity Entity key.
	 * @param string           $table  Table without prefix.
	 * @param string           $pk     Primary key column.
	 * @param StringIdMap|null $map    Id map when the key is a string.
	 */
	public function __construct( $entity, $table, $pk = 'id', $map = null ) {
		$this->entity = $entity;
		$this->table  = $table;
		$this->pk     = $pk;
		$this->map    = $map;
	}

	/**
	 * Option holding the fingerprints.
	 *
	 * @return string
	 */
	private function option() {
		return 'pdock_kafela_fp_' . $this->entity;
	}

	/**
	 * Fingerprints of the rows (all, or only some object ids).
	 *
	 * @param int[]|null $only Object ids, or null for every row.
	 * @return array object id => hash
	 */
	private function fingerprints( $only = null ) {
		global $wpdb;
		$table = $wpdb->prefix . $this->table;
		$pk    = $this->pk;
		$where = '';

		if ( null !== $only ) {
			if ( array() === $only ) {
				return array();
			}
			if ( null !== $this->map ) {
				$slugs = array_filter( array_map( array( $this->map, 'slug_for' ), $only ), 'strlen' );
				if ( array() === $slugs ) {
					return array();
				}
				$where = $wpdb->prepare( ' WHERE `' . $pk . '` IN (' . implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) . ')', array_values( $slugs ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			} else {
				$where = $wpdb->prepare( ' WHERE `' . $pk . '` IN (' . implode( ',', array_fill( 0, count( $only ), '%d' ) ) . ')', array_map( 'intval', $only ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			}
		}

		$rows = (array) $wpdb->get_results( "SELECT * FROM `{$table}`{$where}", ARRAY_A );
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[] = (string) $row[ $pk ];
		}
		$ids = null === $this->map ? array_map( 'intval', $keys ) : $this->map->bulk( $keys );

		$out = array();
		foreach ( $rows as $row ) {
			$key = (string) $row[ $pk ];
			$id  = null === $this->map ? (int) $key : (int) $ids[ $key ];
			if ( $id > 0 ) {
				$out[ $id ] = md5( wp_json_encode( $row ) );
			}
		}
		return $out;
	}

	/**
	 * Remember the current state of one record (call after a write made through the sync API,
	 * so the next scan does not report that write as an outside change).
	 *
	 * @param int $id Object id.
	 * @return void
	 */
	public function mark( $id ) {
		$stored = get_option( $this->option(), null );
		if ( ! is_array( $stored ) ) {
			return;
		}
		$now = $this->fingerprints( array( (int) $id ) );
		if ( isset( $now[ (int) $id ] ) ) {
			$stored[ (int) $id ] = $now[ (int) $id ];
		} else {
			unset( $stored[ (int) $id ] );
		}
		update_option( $this->option(), $stored, false );
	}

	/**
	 * Report outside changes to the change log.
	 *
	 * @param int  $throttle Minimum seconds between scans.
	 * @param bool $force    Ignore the throttle (tests).
	 * @return int Number of records touched.
	 */
	public function refresh( $throttle = 10, $force = false ) {
		$lock = 'pdock_kafela_scan_' . $this->entity;
		if ( ! $force && get_transient( $lock ) ) {
			return 0;
		}
		set_transient( $lock, 1, max( 1, (int) $throttle ) );

		$now    = $this->fingerprints();
		$stored = get_option( $this->option(), null );
		update_option( $this->option(), $now, false );
		if ( ! is_array( $stored ) ) {
			return 0;
		}

		$touched = 0;
		foreach ( $now as $id => $hash ) {
			if ( ! isset( $stored[ $id ] ) || $stored[ $id ] !== $hash ) {
				Tracker::touch( $this->entity, $id, 'upsert', 'other' );
				++$touched;
			}
		}
		foreach ( array_diff_key( $stored, $now ) as $id => $hash ) {
			unset( $hash );
			Tracker::touch( $this->entity, (int) $id, 'delete', 'other' );
			++$touched;
		}
		return $touched;
	}
}
