<?php
/**
 * Hejazer Kafela receipt adapter.
 *
 * @package Pressdock
 */

/**
 * Entity "kafela_receipt": option "pothik_receipt" (a single record, object id 1).
 */
class Pdock_Kafela_Receipt_Test extends Pdock_Kafela_Test_Case {

	/**
	 * A valid receipt payload.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function receipt_data( array $over = array() ) {
		return array_merge(
			array(
				'name'    => 'Mehjabin Chowdhury',
				'amount'  => '40000',
				'channel' => 'bkash',
				'trx'     => 'BKG8TEST01',
				'date'    => '2026-10-20',
				'note'    => 'Deposit',
			),
			$over
		);
	}

	/**
	 * Create, update and delete through /push, checking the real option.
	 */
	public function test_create_update_delete_and_pull() {
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_receipt', $uuid, $this->receipt_data() ) );
		$this->assertSame( 'applied', $create['status'] );
		$this->assertSame( 1, $create['remote_id'] );

		$stored = get_option( 'pothik_receipt' );
		$this->assertSame( 'Mehjabin Chowdhury', $stored['name'] );
		$this->assertSame( '40000', $stored['amount'], 'The amount is stored as a digit string, as Kafela does.' );
		$this->assertSame( 'bkash', $stored['channel'] );
		$this->assertFalse( $stored['demo'] );
		$this->assertSame( $stored, array_intersect_key( Pothik\ReceiptStore::get(), $stored ), 'Kafela reads back the same record.' );

		$items = $this->pull_entity( 'kafela_receipt' );
		$this->assertSame( $uuid, $items[1]['uuid'] );
		$this->assertSame( '40000', $items[1]['data']['amount'] );
		$this->assertSame( 'bkash', $items[1]['data']['channel'] );

		$update = $this->push_one( $this->op( 'update', 'kafela_receipt', null, array( 'amount' => 55000, 'channel' => 'cash' ), array( 'remote_id' => 1, 'base_revision' => $create['revision'] ) ) );
		$this->assertSame( 'applied', $update['status'] );
		$stored = get_option( 'pothik_receipt' );
		$this->assertSame( '55000', $stored['amount'], 'A number is stored as its digit string.' );
		$this->assertSame( 'cash', $stored['channel'] );
		$this->assertSame( 'Mehjabin Chowdhury', $stored['name'], 'Untouched fields stay.' );

		$delete = $this->push_one( $this->op( 'delete', 'kafela_receipt', null, null, array( 'remote_id' => 1, 'base_revision' => $update['revision'] ) ) );
		$this->assertSame( 'applied', $delete['status'] );
		$this->assertFalse( get_option( 'pothik_receipt', false ) );
		$this->assertTrue( $this->pull_entity( 'kafela_receipt' )[1]['deleted'] );
	}

	/**
	 * The demo flag is system-owned; it is also forced off on every write.
	 */
	public function test_readonly_demo_is_rejected() {
		$result = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data( array( 'demo' => true ) ) ) );
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'pdock_readonly_field', $result['error']['code'] );
		$this->assertSame( 'demo', $result['error']['field'] );
		$this->assertFalse( get_option( 'pothik_receipt', false ) );

		// A demo receipt loaded by Kafela is turned into a real one by the first sync update.
		Pothik\ReceiptStore::load_demo();
		$items = $this->pull_entity( 'kafela_receipt' );
		$this->assertTrue( $items[1]['data']['demo'] );
		$update = $this->push_one( $this->op( 'update', 'kafela_receipt', null, array( 'note' => 'Real now' ), array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] ) ) );
		$this->assertSame( 'applied', $update['status'] );
		$this->assertFalse( get_option( 'pothik_receipt' )['demo'] );
	}

	/**
	 * A thousands separator would be turned into a wrong amount by absint(); the adapter refuses it instead.
	 */
	public function test_amount_must_be_plain_digits() {
		foreach ( array( '40,000', '-5', '4e3', 'forty' ) as $amount ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data( array( 'amount' => $amount ) ) ) );
			$this->assertSame( 'error', $result['status'], $amount );
			$this->assertSame( 'amount', $result['error']['field'], $amount );
		}
		$bad_channel = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data( array( 'channel' => 'paypal' ) ) ) );
		$this->assertSame( 'error', $bad_channel['status'] );
		$this->assertFalse( get_option( 'pothik_receipt', false ) );
	}

	/**
	 * There is only one receipt: a second create is a uniqueness conflict that carries the existing one.
	 */
	public function test_second_create_conflicts() {
		$first = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data() ) );
		$this->assertSame( 'applied', $first['status'] );

		$second = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data( array( 'name' => 'Someone else' ) ) ) );
		$this->assertSame( 'conflict', $second['status'] );
		$this->assertSame( 'unique', $second['reason'] );
		$this->assertSame( 1, $second['remote_id'] );
		$this->assertSame( 'Mehjabin Chowdhury', $second['server']['data']['name'] );
		$this->assertSame( 'Mehjabin Chowdhury', get_option( 'pothik_receipt' )['name'] );
	}

	/**
	 * Kafela's own save hook fires for sync writes (Pro's activity log listens to it), without any e-mail.
	 */
	public function test_saved_hook_fires_without_email() {
		$fired = 0;
		add_action(
			'pothik_receipt_saved',
			static function () use ( &$fired ) {
				++$fired;
				wp_mail( 'agency@example.test', 'Receipt saved', 'Body' ); // A careless add-on.
			}
		);
		$this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data() ) );
		$this->assertSame( 1, $fired );
		$this->assertNoMailAttempted();
	}

	/**
	 * Undo of a delete puts the receipt back.
	 */
	public function test_undo_delete_restores_receipt() {
		$create = $this->push_one( $this->op( 'create', 'kafela_receipt', $this->uuid(), $this->receipt_data() ) );
		$batch  = $this->uuid();
		$this->push( array( $this->op( 'delete', 'kafela_receipt', null, null, array( 'remote_id' => 1, 'base_revision' => $create['revision'] ) ) ), $batch );
		$this->assertFalse( get_option( 'pothik_receipt', false ) );

		$this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$this->assertSame( '40000', get_option( 'pothik_receipt' )['amount'] );
	}
}
