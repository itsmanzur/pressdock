<?php
/**
 * Hejazer Kafela Pro payment and ledger adapters.
 *
 * @package Pressdock
 */

/**
 * Entities "kafela_payment" and "kafela_ledger": tables pothik_payments and pothik_ledger.
 */
class Pdock_Kafela_Pro_Payment_Test extends Pdock_Kafela_Test_Case {

	/**
	 * Ledger row id (Pro string) created by the fixture.
	 *
	 * @var string
	 */
	private $ledger_code = '';

	/**
	 * Group id of the fixture.
	 *
	 * @var int
	 */
	private $departure = 0;

	/**
	 * Set up: Pro must be loaded, then one group, one pilgrim and one ledger row owing 100000.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! defined( 'POTHIK_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Hejazer Kafela Pro is not active.' );
		}
		parent::set_up();
		global $wpdb;
		$p = $wpdb->prefix;

		$wpdb->insert( "{$p}pothik_departures", array( 'name' => 'Test group', 'leave_date' => '2027-02-10', 'return_date' => '2027-02-20', 'bn' => '{}' ) );
		$this->departure = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}pothik_pilgrims", array( 'departure_id' => $this->departure, 'name' => 'Karim', 'passport' => 'ZP1234567', 'gender' => 'male', 'bn' => '{}' ) );
		$pilgrim           = (int) $wpdb->insert_id;
		$this->ledger_code = PothikPro\Repo\Ledger::create( $this->departure, array( 'pilgrim_id' => $pilgrim, 'name' => 'Karim', 'due' => 100000, 'paid' => 0 ) );
		$this->assertNotSame( '', $this->ledger_code );

		delete_option( 'pdock_kafela_ledger_ids' );
		delete_option( 'pdock_kafela_fp_kafela_payment' );
		delete_option( 'pdock_kafela_fp_kafela_ledger' );
		delete_transient( 'pdock_kafela_scan_kafela_payment' );
		delete_transient( 'pdock_kafela_scan_kafela_ledger' );
	}

	/**
	 * Pull ledger records and return the fixture's item (remote_id, uuid, data).
	 *
	 * @return array
	 */
	private function ledger_item() {
		delete_transient( 'pdock_kafela_scan_kafela_ledger' );
		foreach ( $this->pull_entity( 'kafela_ledger' ) as $item ) {
			if ( $item['data'] && $item['data']['ledger_code'] === $this->ledger_code ) {
				return $item;
			}
		}
		$this->fail( 'Ledger row missing from the pull.' );
	}

	/**
	 * A valid payment payload.
	 *
	 * @param mixed $ledger Ledger remote id or uuid.
	 * @param array $over   Overrides.
	 * @return array
	 */
	private function payment_data( $ledger, array $over = array() ) {
		return array_merge(
			array(
				'ledger_id' => $ledger,
				'amount'    => 30000,
				'paid_on'   => wp_date( 'Y-m-d' ),
				'channel'   => 'bkash',
				'trx'       => 'bkg8abc',
				'note'      => 'First instalment',
			),
			$over
		);
	}

	/**
	 * Raw payment row.
	 *
	 * @param int $id Payment id.
	 * @return array|null
	 */
	private function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pothik_payments WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Create through /push: Pro allocates the receipt number, recalculates the ledger and the pull shows it.
	 */
	public function test_create_pull_and_server_side_receipt_number() {
		$ledger = $this->ledger_item();
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_payment', $uuid, $this->payment_data( $ledger['remote_id'] ) ) );
		$this->assertSame( 'applied', $create['status'], wp_json_encode( $create ) );

		$row = $this->row( $create['remote_id'] );
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]{2,6}-[A-Z0-9]+-\d{4}$/', $row['receipt_no'], 'The receipt number comes from ReceiptNo::next().' );
		$this->assertSame( '30000', $row['amount'] );
		$this->assertSame( 'BKG8ABC', $row['trx'], 'Pro upper-cases the transaction id.' );
		$this->assertSame( (string) $this->departure, $row['departure_id'], 'The group comes from the ledger row.' );
		$this->assertSame( (string) get_current_user_id(), $row['received_by'] );
		$this->assertSame( 30000, PothikPro\Repo\Ledger::find( $this->ledger_code )['paid'], 'The ledger was recalculated.' );

		$items = $this->pull_entity( 'kafela_payment' );
		$this->assertSame( $uuid, $items[ $create['remote_id'] ]['uuid'] );
		$data = $items[ $create['remote_id'] ]['data'];
		$this->assertSame( $row['receipt_no'], $data['receipt_no'] );
		$this->assertSame( $ledger['remote_id'], $data['ledger_id'] );
		$this->assertSame( 30000, $data['amount'] );
		$this->assertFalse( $data['voided'] );

		// The ledger row now shows the new paid total (detected by the scan, Pro fires no hook).
		$after = $this->ledger_item();
		$this->assertSame( 30000, $after['data']['paid'] );
	}

	/**
	 * The ledger can be referred to by its uuid, and receipt numbers never repeat.
	 */
	public function test_relation_by_uuid_and_sequential_receipts() {
		$ledger = $this->ledger_item();
		$a      = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['uuid'] ) ) );
		$b      = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['uuid'], array( 'amount' => 20000 ) ) ) );
		$this->assertSame( 'applied', $a['status'], wp_json_encode( $a ) );
		$this->assertSame( 'applied', $b['status'] );
		$this->assertNotSame( $this->row( $a['remote_id'] )['receipt_no'], $this->row( $b['remote_id'] )['receipt_no'] );
		$this->assertSame( 50000, PothikPro\Repo\Ledger::find( $this->ledger_code )['paid'] );
	}

	/**
	 * Computed and system fields cannot be written: a client can never choose a receipt number.
	 */
	public function test_readonly_fields_are_rejected() {
		$ledger = $this->ledger_item();
		$fields = array(
			'receipt_no'       => 'PTK-2027-0001',
			'is_opening'       => true,
			'departure_id'     => 999,
			'pilgrim_id'       => 5,
			'received_by'      => 1,
			'received_by_name' => 'Someone',
			'voided_at'        => '2027-01-01 00:00:00',
			'voided_by'        => 1,
			'created_at'       => '2020-01-01 00:00:00',
		);
		foreach ( $fields as $field => $value ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'], array( $field => $value ) ) ) );
			$this->assertSame( 'error', $result['status'], $field );
			$this->assertSame( 'pdock_readonly_field', $result['error']['code'], $field );
			$this->assertSame( $field, $result['error']['field'] );
		}
		$this->assertSame( 0, PothikPro\Repo\Ledger::find( $this->ledger_code )['paid'] );
	}

	/**
	 * Pro's own money rules are enforced with clear errors.
	 */
	public function test_money_rules() {
		$ledger = $this->ledger_item();
		$cases  = array(
			'amount'    => array( array( 'amount' => 0 ), 'pdock_invalid_field' ),
			'channel'   => array( array( 'channel' => 'paypal' ), 'pdock_invalid_field' ),
			'future'    => array( array( 'paid_on' => wp_date( 'Y-m-d', time() + 5 * DAY_IN_SECONDS ) ), 'pdock_invalid_field' ),
			'overbal'   => array( array( 'amount' => 100001 ), 'pdock_over_balance' ),
			'ledger'    => array( array( 'ledger_id' => 99999 ), 'pdock_unresolved_relation' ),
			'void-make' => array( array( 'voided' => true, 'void_reason' => 'created voided' ), 'pdock_invalid_field' ),
		);
		foreach ( $cases as $name => $case ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'], $case[0] ) ) );
			$this->assertSame( 'error', $result['status'], $name );
			$this->assertSame( $case[1], $result['error']['code'], $name );
		}
		$this->assertSame( 0, (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->prefix}pothik_payments" ), 'Nothing was recorded (and no receipt number burned by a refused op).' );

		$over = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'], array( 'amount' => 100001, 'allow_overpay' => true ) ) ) );
		$this->assertSame( 'applied', $over['status'], 'allow_overpay is Pro\'s "confirm".' );
	}

	/**
	 * A payment is never edited: every writable-at-create field is create_only.
	 */
	public function test_payment_cannot_be_edited() {
		$ledger = $this->ledger_item();
		$create = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'] ) ) );
		$this->assertSame( 'applied', $create['status'] );

		foreach ( array( 'amount' => 1, 'note' => 'edited', 'channel' => 'cash', 'paid_on' => '2020-01-01' ) as $field => $value ) {
			$result = $this->push_one( $this->op( 'update', 'kafela_payment', null, array( $field => $value ), array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) ) );
			$this->assertSame( 'error', $result['status'], $field );
			$this->assertSame( 'pdock_readonly_field', $result['error']['code'], $field );
		}
		$this->assertSame( '30000', $this->row( $create['remote_id'] )['amount'] );
	}

	/**
	 * A void: needs a reason, goes through Pro, recalculates the ledger, cannot be reverted, keeps the receipt number.
	 */
	public function test_void() {
		$ledger  = $this->ledger_item();
		$create  = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'] ) ) );
		$receipt = $this->row( $create['remote_id'] )['receipt_no'];
		$extra   = array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] );

		$short = $this->push_one( $this->op( 'update', 'kafela_payment', null, array( 'voided' => true, 'void_reason' => 'no' ), $extra ) );
		$this->assertSame( 'error', $short['status'] );
		$this->assertSame( 'void_reason', $short['error']['field'] );

		$void = $this->push_one( $this->op( 'update', 'kafela_payment', null, array( 'voided' => true, 'void_reason' => 'Entered twice by mistake' ), $extra ) );
		$this->assertSame( 'applied', $void['status'], wp_json_encode( $void ) );
		$row = $this->row( $create['remote_id'] );
		$this->assertNotNull( $row['voided_at'] );
		$this->assertSame( 'Entered twice by mistake', $row['void_reason'] );
		$this->assertSame( $receipt, $row['receipt_no'], 'The receipt number stays on the voided payment.' );
		$this->assertSame( 0, PothikPro\Repo\Ledger::find( $this->ledger_code )['paid'], 'A void takes the money out of the ledger.' );

		$items = $this->pull_entity( 'kafela_payment' );
		$this->assertTrue( $items[ $create['remote_id'] ]['data']['voided'] );

		$undo = $this->push_one( $this->op( 'update', 'kafela_payment', null, array( 'voided' => false ), array( 'remote_id' => $create['remote_id'], 'base_revision' => $void['revision'] ) ) );
		$this->assertSame( 'error', $undo['status'], 'A void cannot be taken back.' );
	}

	/**
	 * Deleting is not offered, and undoing a create refuses instead of destroying a receipt.
	 */
	public function test_delete_and_undo_create_are_refused() {
		$ledger = $this->ledger_item();
		$batch  = $this->uuid();
		$create = $this->push( array( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'] ) ) ), $batch )[0];
		$this->assertSame( 'applied', $create['status'] );

		$delete = $this->push_one( $this->op( 'delete', 'kafela_payment', null, null, array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) ) );
		$this->assertSame( 'error', $delete['status'] );
		$this->assertSame( 'pdock_forbidden', $delete['error']['code'] );

		$response = $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertNotEmpty( $response->get_data()['errors'] );
		$this->assertNotNull( $this->row( $create['remote_id'] ), 'The payment is still there.' );
	}

	/**
	 * A payment made in Pro's own screen reaches the next pull.
	 */
	public function test_payment_made_in_pro_is_picked_up() {
		$this->pull_entity( 'kafela_payment' ); // First scan: fingerprints only.
		$cursor = Pressdock\Store\Changes::max_id();

		$added = PothikPro\Payments::add( array( 'ledger_id' => $this->ledger_code, 'amount' => 10000, 'paid_on' => wp_date( 'Y-m-d' ), 'channel' => 'cash' ) );
		$this->assertIsArray( $added );

		delete_transient( 'pdock_kafela_scan_kafela_payment' );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_payment' ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( $added['receipt_no'], $data['items'][0]['data']['receipt_no'] );
	}

	/**
	 * A payment created by sync is not reported a second time as an outside change.
	 */
	public function test_sync_write_is_not_echoed_by_the_scan() {
		$ledger = $this->ledger_item();
		$this->pull_entity( 'kafela_payment' );
		$create = $this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'] ) ) );
		$cursor = Pressdock\Store\Changes::max_id();

		delete_transient( 'pdock_kafela_scan_kafela_payment' );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_payment' ) );
		$this->assertSame( array(), $data['items'], 'No second revision for the write sync already made.' );
		$this->assertSame( 1, Pressdock\Store\Records::revision( 'kafela_payment', $create['remote_id'] ) );
	}

	/**
	 * The ledger is read only.
	 */
	public function test_ledger_is_read_only() {
		$ledger = $this->ledger_item();
		$this->assertSame( 100000, $ledger['data']['due'] );
		$this->assertSame( $this->departure, $ledger['data']['departure_id'] );

		$create = $this->push_one( $this->op( 'create', 'kafela_ledger', $this->uuid(), array( 'name' => 'X' ) ) );
		$this->assertSame( 'pdock_forbidden', $create['error']['code'] );
		$update = $this->push_one( $this->op( 'update', 'kafela_ledger', null, array( 'due' => 1 ), array( 'remote_id' => $ledger['remote_id'], 'base_revision' => $ledger['revision'] ) ) );
		$this->assertSame( 'pdock_forbidden', $update['error']['code'] );
		$delete = $this->push_one( $this->op( 'delete', 'kafela_ledger', null, null, array( 'remote_id' => $ledger['remote_id'], 'base_revision' => $ledger['revision'] ) ) );
		$this->assertSame( 'pdock_forbidden', $delete['error']['code'] );
		$this->assertSame( 100000, PothikPro\Repo\Ledger::find( $this->ledger_code )['due'] );
	}

	/**
	 * Staff limited to some groups are refused: a pull cannot be filtered per group.
	 */
	public function test_group_limited_staff_are_refused() {
		$user = self::factory()->user->create( array( 'role' => 'pothik_accounts' ) );
		wp_set_current_user( $user );
		update_user_meta( $user, PothikPro\Access::META, wp_json_encode( array( $this->departure ) ) );

		$response = $this->call( 'GET', '/pull', null, array( 'since' => 0, 'entity' => 'kafela_payment' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	/**
	 * No mail is attempted while payments are written.
	 */
	public function test_no_email_during_sync() {
		$ledger = $this->ledger_item();
		$this->push_one( $this->op( 'create', 'kafela_payment', $this->uuid(), $this->payment_data( $ledger['remote_id'] ) ) );
		$this->assertNoMailAttempted();
	}
}
