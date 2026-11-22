<?php
/**
 * Hejazer Kafela Pro payments (entity "kafela_payment").
 *
 * Storage: table {prefix}pothik_payments. Object id: the table's auto-increment id.
 *
 * Pro treats a payment as an append-only money record: it is never edited or deleted, only voided with a
 * reason. The adapter keeps that rule:
 *  - create goes through PothikPro\Payments::add(), which allocates the receipt number on the server
 *    (ReceiptNo::next()), recalculates the ledger, evaluates commission and writes the activity log;
 *  - update can only void (voided = true + void_reason); every other field is create_only;
 *  - delete is not offered. Undo of a create therefore fails with a clear message instead of destroying a receipt.
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
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Money rules are Pro's; this class only translates.
 */
final class PaymentAdapter implements EntityAdapter, EnumerableAdapter {

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
			'kafela_payment',
			array(
				'label'   => __( 'Payments & receipts', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela Pro', 'pressdock' ),
				'enabled' => true,
			)
		);
		$this->scanner = new TableScanner( 'kafela_payment', 'pothik_payments', 'id', null );
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
		return 'kafela_payment';
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
	 * Pro's access matrix (area "ledger"); delete is never offered.
	 *
	 * @param string $action read|create|update|delete.
	 * @return bool
	 */
	public function can( $action ) {
		if ( 'delete' === $action ) {
			return false;
		}
		return Support::can_pro( 'ledger', $action, $this->args );
	}

	/**
	 * Per-record rule for the only update there is: a void. Same test as Payments::void().
	 *
	 * @param string $action update|delete.
	 * @param int    $id     Object id.
	 * @return bool
	 */
	public function can_object( $action, $id ) {
		if ( 'update' !== $action ) {
			return false;
		}
		if ( isset( $this->args['can_void'] ) && is_callable( $this->args['can_void'] ) ) {
			return (bool) call_user_func( $this->args['can_void'], (int) $id );
		}
		if ( ! \PothikPro\Payments::can_void() ) {
			return false;
		}
		$row = \PothikPro\Payments::find( (int) $id );
		return ! ( is_array( $row ) && ! empty( $row['is_opening'] ) && ! current_user_can( 'manage_options' ) );
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
			'ledger_id'        => array(
				'type'        => 'relation',
				'target'      => 'kafela_ledger',
				'label'       => __( 'Ledger row', 'pressdock' ),
				'required'    => true,
				'create_only' => true,
			),
			'departure_id'     => array_merge( $ro( 'relation', __( 'Group (from the ledger row)', 'pressdock' ) ), array( 'target' => 'kafela_departure' ) ),
			'pilgrim_id'       => array_merge( $ro( 'relation', __( 'Pilgrim (from the ledger row)', 'pressdock' ) ), array( 'target' => 'kafela_pilgrim' ) ),
			'paid_on'          => array(
				'type'        => 'date',
				'label'       => __( 'Date', 'pressdock' ),
				'create_only' => true,
				'description' => 'Defaults to today. Not more than one day ahead.',
			),
			'amount'           => array(
				'type'        => 'int',
				'label'       => __( 'Amount (BDT)', 'pressdock' ),
				'required'    => true,
				'create_only' => true,
				'min'         => 1,
			),
			'channel'          => array(
				'type'        => 'enum',
				'label'       => __( 'Payment channel', 'pressdock' ),
				'required'    => true,
				'create_only' => true,
				'options'     => array( 'bkash', 'nagad', 'rocket', 'bank', 'cash' ),
			),
			'trx'              => array(
				'type'        => 'string',
				'label'       => __( 'Transaction ID', 'pressdock' ),
				'create_only' => true,
				'max_length'  => 40,
				'description' => 'Upper-cased by Pro.',
			),
			'note'             => array(
				'type'        => 'string',
				'label'       => __( 'Note', 'pressdock' ),
				'create_only' => true,
				'max_length'  => 255,
			),
			'allow_overpay'    => array(
				'type'        => 'bool',
				'label'       => __( 'Accept more than the balance', 'pressdock' ),
				'create_only' => true,
				'description' => 'Create-time switch (Pro\'s "confirm"). Not stored, always false when read.',
			),
			'receipt_no'       => array_merge( $ro( 'string', __( 'Receipt number', 'pressdock' ) ), array( 'description' => 'Allocated by the server (PREFIX-YEAR-0001). Never sent by a client.' ) ),
			'is_opening'       => $ro( 'bool', __( 'Opening balance', 'pressdock' ) ),
			'received_by'      => $ro( 'int', __( 'Received by (user id)', 'pressdock' ) ),
			'received_by_name' => $ro( 'string', __( 'Received by', 'pressdock' ) ),
			'created_at'       => $ro( 'string', __( 'Created (GMT)', 'pressdock' ) ),
			'voided'           => array(
				'type'        => 'bool',
				'label'       => __( 'Voided', 'pressdock' ),
				'description' => 'Send true on update (with void_reason) to void. A void cannot be undone.',
			),
			'void_reason'      => array(
				'type'        => 'string',
				'label'       => __( 'Void reason', 'pressdock' ),
				'max_length'  => 255,
				'description' => 'At least 5 characters. Only together with voided = true.',
			),
			'voided_at'        => $ro( 'string', __( 'Voided at (GMT)', 'pressdock' ) ),
			'voided_by'        => $ro( 'int', __( 'Voided by (user id)', 'pressdock' ) ),
		);
	}

	/**
	 * Pro's shaped payment to a record.
	 *
	 * @param array $payment PothikPro\Payments::find() result.
	 * @param int   $ledger  Integer ledger id.
	 * @return array
	 */
	private function present( array $payment, $ledger ) {
		return array(
			'id'               => (int) $payment['id'],
			'ledger_id'        => (int) $ledger,
			'departure_id'     => (int) $payment['departure_id'],
			'pilgrim_id'       => (int) $payment['pilgrim_id'],
			'paid_on'          => (string) $payment['paid_on'],
			'amount'           => (int) $payment['amount'],
			'channel'          => (string) $payment['channel'],
			'trx'              => (string) $payment['trx'],
			'note'             => (string) $payment['note'],
			'allow_overpay'    => false,
			'receipt_no'       => (string) $payment['receipt_no'],
			'is_opening'       => ! empty( $payment['is_opening'] ),
			'received_by'      => (int) $payment['received_by'],
			'received_by_name' => (string) $payment['received_by_name'],
			'created_at'       => (string) $payment['created_at'],
			'voided'           => '' !== (string) $payment['voided_at'],
			'void_reason'      => (string) $payment['void_reason'],
			'voided_at'        => (string) $payment['voided_at'],
			'voided_by'        => (int) $payment['voided_by'],
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		$payment = \PothikPro\Payments::find( (int) $id );
		return null === $payment ? null : $this->present( $payment, LedgerIds::id_for( $payment['ledger_id'] ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table = $wpdb->prefix . 'pothik_payments';
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ), // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
			ARRAY_A
		);
		$map   = LedgerIds::map()->bulk( array_map( 'strval', wp_list_pluck( $rows, 'ledger_id' ) ) );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['id'] ] = $this->present(
				array_merge(
					$row,
					array(
						'paid_on'    => substr( (string) $row['paid_on'], 0, 10 ),
						'voided_at'  => (string) $row['voided_at'],
						'void_reason' => (string) $row['void_reason'],
					)
				),
				isset( $map[ $row['ledger_id'] ] ) ? $map[ $row['ledger_id'] ] : 0
			);
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pothik_payments';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ) ) );
	}

	/**
	 * Pro fires no hooks for payments made in its own screens: detect them before a pull.
	 *
	 * @return void
	 */
	public function refresh() {
		$this->scanner->refresh();
	}

	/**
	 * Business rules, in the order Payments::add() checks them, with proper error codes and fields.
	 *
	 * @param array    $data Clean data.
	 * @param int|null $id   Object id for updates.
	 * @return WP_Error|true
	 */
	public function validate( array $data, $id = null ) {
		if ( null !== $id ) {
			return $this->validate_void( $data, (int) $id );
		}

		if ( ! empty( $data['voided'] ) || ( isset( $data['void_reason'] ) && '' !== $data['void_reason'] ) ) {
			return new WP_Error( 'pdock_invalid_field', 'A payment cannot be created voided. Create it, then void it.', array( 'field' => 'voided' ) );
		}
		$slug   = LedgerIds::slug_for( isset( $data['ledger_id'] ) ? $data['ledger_id'] : 0 );
		$ledger = '' === $slug ? null : \PothikPro\Repo\Ledger::find( $slug );
		if ( null === $ledger ) {
			return new WP_Error( 'pdock_unresolved_relation', 'Field "ledger_id" points to a ledger row that does not exist.', array( 'field' => 'ledger_id' ) );
		}
		if ( isset( $data['amount'] ) && $data['amount'] < 1 ) {
			return new WP_Error( 'pdock_invalid_field', 'Enter an amount of at least 1.', array( 'field' => 'amount' ) );
		}
		if ( ! empty( $data['paid_on'] ) ) {
			$limit = ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d' );
			if ( $data['paid_on'] > $limit ) {
				return new WP_Error( 'pdock_invalid_field', 'The date cannot be more than a day ahead.', array( 'field' => 'paid_on' ) );
			}
		}
		$balance = max( 0, (int) $ledger['due'] - (int) $ledger['paid'] );
		if ( isset( $data['amount'] ) && $data['amount'] > $balance && empty( $data['allow_overpay'] ) ) {
			return new WP_Error(
				'pdock_over_balance',
				sprintf( 'This is %d more than the balance (%d). Send allow_overpay = true to accept it.', $data['amount'] - $balance, $balance ),
				array( 'field' => 'amount' )
			);
		}
		return true;
	}

	/**
	 * Rules for an update: it can only be a void.
	 *
	 * @param array $data Clean data.
	 * @param int   $id   Object id.
	 * @return WP_Error|true
	 */
	private function validate_void( array $data, $id ) {
		$current = \PothikPro\Payments::find( $id );
		$voided  = null !== $current && '' !== (string) $current['voided_at'];

		if ( array_key_exists( 'voided', $data ) && false === $data['voided'] && $voided ) {
			return new WP_Error( 'pdock_invalid_field', 'A voided payment cannot be restored. Record a new payment instead.', array( 'field' => 'voided' ) );
		}
		if ( ! empty( $data['voided'] ) && ! $voided ) {
			if ( mb_strlen( trim( isset( $data['void_reason'] ) ? $data['void_reason'] : '' ) ) < 5 ) {
				return new WP_Error( 'pdock_invalid_field', 'A void needs a reason of at least 5 characters.', array( 'field' => 'void_reason' ) );
			}
		}
		if ( isset( $data['void_reason'] ) && '' !== $data['void_reason'] && empty( $data['voided'] ) && ! $voided ) {
			return new WP_Error( 'pdock_invalid_field', 'void_reason is only accepted together with voided = true.', array( 'field' => 'void_reason' ) );
		}
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When Pro refuses the payment.
	 */
	public function create( array $data ) {
		$result = \PothikPro\Payments::add(
			array(
				'ledger_id' => LedgerIds::slug_for( $data['ledger_id'] ),
				'amount'    => $data['amount'],
				'paid_on'   => isset( $data['paid_on'] ) ? $data['paid_on'] : '',
				'channel'   => $data['channel'],
				'trx'       => isset( $data['trx'] ) ? $data['trx'] : '',
				'note'      => isset( $data['note'] ) ? $data['note'] : '',
				'confirm'   => ! empty( $data['allow_overpay'] ),
			)
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$id = (int) $result['id'];
		$this->scanner->mark( $id );
		return $id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When Pro refuses the void.
	 */
	public function update( $id, array $data ) {
		if ( empty( $data['voided'] ) ) {
			return;
		}
		$current = \PothikPro\Payments::find( (int) $id );
		if ( null !== $current && '' !== (string) $current['voided_at'] ) {
			return; // Already voided: same end state, nothing to do.
		}
		if ( ! \PothikPro\Payments::void( (int) $id, isset( $data['void_reason'] ) ? $data['void_reason'] : '' ) ) {
			throw new \RuntimeException( 'Pro refused to void this payment.' );
		}
		$this->scanner->mark( (int) $id );
	}

	/**
	 * A receipt is never removed. Reached only through undo of a create.
	 *
	 * @param int  $id    Object id.
	 * @param bool $force Unused.
	 * @return void
	 * @throws \RuntimeException Always.
	 */
	public function delete( $id, $force ) {
		unset( $id, $force );
		throw new \RuntimeException( 'A payment and its receipt number are never deleted. Void it instead.' );
	}
}
