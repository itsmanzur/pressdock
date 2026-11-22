<?php
/**
 * Hejazer Kafela Pro ledger rows (entity "kafela_ledger"), read only.
 *
 * Storage: table {prefix}pothik_ledger, primary key a string ("l-12-ab3dEf"). Object id: integer from LedgerIds.
 * One row = what a pilgrim owes in a group. `paid` is derived from payments by Pro itself, and rows are created
 * by Pro's group screens, so sync only reads them. Payments point at them through a relation.
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Pressdock\Adapters\Kafela\Pro;

use Pressdock\Adapters\Kafela\LedgerIds;
use Pressdock\Adapters\Kafela\Support;
use Pressdock\Adapters\Kafela\TableScanner;
use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read only. Create, update and delete are not offered (can() is false), so /push refuses them.
 */
final class LedgerAdapter implements EntityAdapter, EnumerableAdapter {

	/**
	 * Arguments (label, group, enabled, can).
	 *
	 * @var array
	 */
	private $args;

	/**
	 * Change detection (Pro fires no hooks).
	 *
	 * @var TableScanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->args    = Support::args(
			'kafela_ledger',
			array(
				'label'   => __( 'Pilgrim ledger (read only)', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela Pro', 'pressdock' ),
				'enabled' => true,
			)
		);
		$this->scanner = new TableScanner( 'kafela_ledger', 'pothik_ledger', 'id', LedgerIds::map() );
	}

	/**
	 * Whether this adapter should be registered.
	 *
	 * @return bool
	 */
	public function enabled() {
		return ! empty( $this->args['enabled'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_ledger';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return (string) $this->args['label'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function group() {
		return (string) $this->args['group'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function capability( $action ) {
		unset( $action );
		return 'manage_options';
	}

	/**
	 * Pro's own access matrix (area "ledger"). Only staff with access to every group may sync:
	 * a pull has no per-group filter.
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		if ( 'read' !== $action ) {
			return false;
		}
		return Support::can_pro( 'ledger', 'read', $this->args );
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema() {
		$ro = static function ( $type, $label ) {
			return array(
				'type'     => $type,
				'label'    => $label,
				'readonly' => true,
			);
		};
		return array(
			'ledger_code'  => $ro( 'string', __( 'Ledger row id', 'pressdock' ) ),
			'departure_id' => array_merge( $ro( 'relation', __( 'Group', 'pressdock' ) ), array( 'target' => 'kafela_departure' ) ),
			'pilgrim_id'   => array_merge( $ro( 'relation', __( 'Pilgrim', 'pressdock' ) ), array( 'target' => 'kafela_pilgrim' ) ),
			'name'         => $ro( 'string', __( 'Name', 'pressdock' ) ),
			'due'          => $ro( 'int', __( 'Due (BDT)', 'pressdock' ) ),
			'paid'         => $ro( 'int', __( 'Paid (BDT, from payments)', 'pressdock' ) ),
			'channel'      => $ro( 'string', __( 'Last channel', 'pressdock' ) ),
			'trx'          => $ro( 'string', __( 'Last transaction id', 'pressdock' ) ),
			'note'         => $ro( 'string', __( 'Note', 'pressdock' ) ),
			'usd_note'     => $ro( 'string', __( 'USD note', 'pressdock' ) ),
			'sar_note'     => $ro( 'string', __( 'SAR note', 'pressdock' ) ),
			'sort_order'   => $ro( 'int', __( 'Sort order', 'pressdock' ) ),
		);
	}

	/**
	 * Row to record.
	 *
	 * @param array $row   DB row.
	 * @param int   $id    Object id.
	 * @return array
	 */
	private function present( array $row, $id ) {
		return array(
			'id'           => (int) $id,
			'ledger_code'  => (string) $row['id'],
			'departure_id' => (int) $row['departure_id'],
			'pilgrim_id'   => (int) $row['pilgrim_id'],
			'name'         => (string) $row['name'],
			'due'          => (int) $row['due'],
			'paid'         => (int) $row['paid'],
			'channel'      => (string) $row['channel'],
			'trx'          => (string) $row['trx'],
			'note'         => (string) $row['note'],
			'usd_note'     => (string) $row['usd_note'],
			'sar_note'     => (string) $row['sar_note'],
			'sort_order'   => (int) $row['sort_order'],
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		$list = $this->list( array( $id ) );
		return isset( $list[ (int) $id ] ) ? $list[ (int) $id ] : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		global $wpdb;
		$slugs = array();
		foreach ( $ids as $id ) {
			$slug = LedgerIds::slug_for( $id );
			if ( '' !== $slug ) {
				$slugs[ $slug ] = (int) $id;
			}
		}
		if ( array() === $slugs ) {
			return array();
		}
		$table = $wpdb->prefix . 'pothik_ledger';
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id IN (" . implode( ',', array_fill( 0, count( $slugs ), '%s' ) ) . ')', array_keys( $slugs ) ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);
		$out   = array();
		foreach ( $rows as $row ) {
			$id         = $slugs[ (string) $row['id'] ];
			$out[ $id ] = $this->present( $row, $id );
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pothik_ledger';
		$slugs = array_map( 'strval', (array) $wpdb->get_col( "SELECT id FROM `{$table}`" ) );
		$ids   = array();
		foreach ( LedgerIds::map()->bulk( $slugs ) as $id ) {
			if ( $id > (int) $after ) {
				$ids[] = $id;
			}
		}
		sort( $ids );
		return array_slice( $ids, 0, (int) $limit );
	}

	/**
	 * Pro fires no hooks for ledger edits: detect them before a pull.
	 *
	 * @return void
	 */
	public function refresh() {
		$this->scanner->refresh();
	}

	/**
	 * Never reached: can() refuses writes.
	 *
	 * @param array $data Data.
	 * @return int
	 * @throws \RuntimeException Always.
	 */
	public function create( array $data ) {
		unset( $data );
		throw new \RuntimeException( 'Ledger rows are read only.' );
	}

	/**
	 * Never reached: can() refuses writes.
	 *
	 * @param int   $id   Id.
	 * @param array $data Data.
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function update( $id, array $data ) {
		unset( $id, $data );
		throw new \RuntimeException( 'Ledger rows are read only.' );
	}

	/**
	 * Never reached: can() refuses writes.
	 *
	 * @param int  $id    Id.
	 * @param bool $force Force.
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function delete( $id, $force ) {
		unset( $id, $force );
		throw new \RuntimeException( 'Ledger rows are read only.' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( array $data, $id = null ) {
		unset( $data, $id );
		return true;
	}
}
