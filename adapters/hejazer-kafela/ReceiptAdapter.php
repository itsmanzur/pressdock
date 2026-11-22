<?php
/**
 * Hejazer Kafela receipt slip (entity "kafela_receipt").
 *
 * Storage: wp_options "pothik_receipt" (one array, autoload off). Object id is always 1.
 * The free plugin has no receipt number and no counter; nothing here generates one.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters\Kafela;

use Pressdock\Contracts\RestorableAdapter;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors ReceiptStore::handle_save(): same sanitizers, same limits, demo forced off.
 */
final class ReceiptAdapter extends SingletonAdapter implements RestorableAdapter {

	const OPTION = 'pothik_receipt';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'label'   => __( 'Receipt', 'pressdock' ),
				'group'   => __( 'Hejazer Kafela', 'pressdock' ),
				'enabled' => true,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return 'kafela_receipt';
	}

	/**
	 * {@inheritdoc}
	 */
	protected function area() {
		return 'receipt';
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema() {
		return array(
			'name'    => array(
				'type'       => 'string',
				'label'      => __( 'Paid by', 'pressdock' ),
				'max_length' => 80,
			),
			'amount'  => array(
				'type'        => 'string',
				'label'       => __( 'Amount received', 'pressdock' ),
				'max_length'  => 15,
				'description' => 'Plain digits (BDT), no thousands separators. Stored as a string like Kafela does.',
			),
			'channel' => array(
				'type'    => 'enum',
				'label'   => __( 'Payment channel', 'pressdock' ),
				'options' => array( 'bkash', 'nagad', 'rocket', 'bank', 'cash' ),
			),
			'trx'     => array(
				'type'       => 'string',
				'label'      => __( 'Transaction ID', 'pressdock' ),
				'max_length' => 40,
			),
			'date'    => array(
				'type'        => 'string',
				'label'       => __( 'Date', 'pressdock' ),
				'max_length'  => 20,
				'description' => 'Kafela does not check the format; the admin screen writes Y-m-d.',
			),
			'note'    => array(
				'type'       => 'text',
				'label'      => __( 'Note', 'pressdock' ),
				'max_length' => 240,
			),
			'demo'    => array(
				'type'     => 'bool',
				'label'    => __( 'Demo', 'pressdock' ),
				'readonly' => true,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	protected function read() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}
		$receipt           = \Pothik\ReceiptStore::get();
		$receipt['amount'] = (string) $receipt['amount'];
		$receipt['demo']   = ! empty( $receipt['demo'] );
		return $receipt;
	}

	/**
	 * Canonical values: Bangla channel labels, and a number becomes its digit string.
	 *
	 * @param array    $data Raw data.
	 * @param int|null $id   Object id for updates.
	 * @return array
	 */
	public function normalize( array $data, $id = null ) {
		unset( $id );
		if ( isset( $data['channel'] ) && is_string( $data['channel'] ) ) {
			$data['channel'] = \Pothik\I18n::canonical( 'channel', sanitize_text_field( $data['channel'] ) );
		}
		if ( isset( $data['amount'] ) && is_int( $data['amount'] ) ) {
			$data['amount'] = (string) $data['amount'];
		}
		return $data;
	}

	/**
	 * Business rules.
	 *
	 * @param array    $data Clean data.
	 * @param int|null $id   Object id for updates.
	 * @return WP_Error|true
	 */
	public function validate( array $data, $id = null ) {
		if ( null === $id ) {
			$exists = $this->exists_error( 'receipt' );
			if ( $exists ) {
				return $exists;
			}
		}
		// Kafela runs absint() on the amount: "40,000" would silently become 40. Refuse instead.
		if ( isset( $data['amount'] ) && 1 !== preg_match( '/^\d{1,15}$/', $data['amount'] ) ) {
			return new WP_Error( 'pdock_invalid_field', 'Field "amount" must be plain digits without commas or signs.', array( 'field' => 'amount' ) );
		}
		return true;
	}

	/**
	 * Write the receipt the way handle_save() does.
	 *
	 * @param array $record Full clean record.
	 * @return void
	 */
	private function write( array $record ) {
		$before = get_option( self::OPTION, array() );
		$before = is_array( $before ) ? $before : array();
		$after  = array(
			'name'    => (string) $record['name'],
			'amount'  => (string) absint( $record['amount'] ),
			'channel' => (string) $record['channel'],
			'trx'     => (string) $record['trx'],
			'date'    => (string) $record['date'],
			'note'    => (string) $record['note'],
			'demo'    => false,
		);
		update_option( self::OPTION, $after, false );
		Support::fire( 'kafela_receipt', 'saved', $before, $after );
	}

	/**
	 * {@inheritdoc}
	 */
	public function create( array $data ) {
		$this->write( array_merge( \Pothik\ReceiptStore::empty(), $data ) );
		return self::ID;
	}

	/**
	 * {@inheritdoc}
	 */
	public function update( $id, array $data ) {
		unset( $id );
		$this->write( array_merge( \Pothik\ReceiptStore::get(), $data ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $id, $force ) {
		unset( $id, $force );
		delete_option( self::OPTION ); // Kafela itself only does this when removing demo data (no hook).
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore( $id, array $before ) {
		unset( $id );
		if ( null !== $this->read() ) {
			return true;
		}
		$record = array_merge( \Pothik\ReceiptStore::empty(), array_intersect_key( $before, \Pothik\ReceiptStore::empty() ) );
		update_option(
			self::OPTION,
			array(
				'name'    => (string) $record['name'],
				'amount'  => (string) absint( $record['amount'] ),
				'channel' => (string) $record['channel'],
				'trx'     => (string) $record['trx'],
				'date'    => (string) $record['date'],
				'note'    => (string) $record['note'],
				'demo'    => ! empty( $before['demo'] ),
			),
			false
		);
		return true;
	}

	/**
	 * Hook Kafela's save handler and demo data so they reach the change log.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action(
			'pothik_receipt_saved',
			static function () {
				Support::track( 'kafela_receipt', self::ID );
			}
		);
		add_action(
			'pothik_demo_data',
			static function ( $state ) {
				if ( 'removed' === $state ) {
					Support::reconcile( 'kafela_receipt' );
				} else {
					Support::track( 'kafela_receipt', self::ID );
				}
			}
		);
	}
}
