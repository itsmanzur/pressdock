<?php
/**
 * Hejazer Kafela inquiry adapter.
 *
 * @package Pressdock
 */

/**
 * Entity "kafela_inquiry": table {prefix}pothik_inquiries.
 */
class Pdock_Kafela_Inquiry_Test extends Pdock_Kafela_Test_Case {

	/**
	 * Fetch a raw table row by uuid.
	 *
	 * @param string $uuid Inquiry uuid.
	 * @return array|null
	 */
	private function row( $uuid ) {
		global $wpdb;
		$table = $wpdb->prefix . 'pothik_inquiries';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Create, update and delete through /push with the real table and Kafela's own reader checked.
	 */
	public function test_create_update_delete_and_pull() {
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data() ) );
		$this->assertSame( 'applied', $create['status'] );
		$id = $create['remote_id'];

		$row = $this->row( $uuid );
		$this->assertNotNull( $row, 'The row carries the client uuid.' );
		$this->assertSame( (string) $id, $row['id'] );
		$this->assertSame( 'Rahim Uddin', $row['name'] );
		$this->assertSame( 'double', $row['share'] );
		$this->assertSame( '2', $row['adults'] );
		$this->assertSame( 'new', $row['status'] );
		$this->assertSame( '0', $row['replied'] );
		$this->assertSame( '0', $row['demo'] );
		$this->assertNotSame( '', $row['created_gmt'] );

		// Kafela itself can read what sync wrote.
		$found = Pothik\InquiryRepository::find( $uuid );
		$this->assertSame( 'Dhaka', $found['city'] );

		$items = $this->pull_entity( 'kafela_inquiry' );
		$this->assertSame( $uuid, $items[ $id ]['uuid'] );
		$this->assertSame( 'Rahim Uddin', $items[ $id ]['data']['name'] );
		$this->assertSame( 2, $items[ $id ]['data']['adults'] );
		$this->assertFalse( $items[ $id ]['data']['replied'] );
		$this->assertSame( 'new', $items[ $id ]['data']['status'] );

		// Update: content fields and the replied flag (status mirrors it like Kafela's set_replied()).
		$update = $this->push_one(
			$this->op( 'update', 'kafela_inquiry', null, array( 'city' => 'Sylhet', 'replied' => true ), array( 'remote_id' => $id, 'base_revision' => $create['revision'] ) )
		);
		$this->assertSame( 'applied', $update['status'] );
		$row = $this->row( $uuid );
		$this->assertSame( 'Sylhet', $row['city'] );
		$this->assertSame( '1', $row['replied'] );
		$this->assertSame( 'replied', $row['status'] );
		$this->assertSame( 'Rahim Uddin', $row['name'], 'Untouched fields stay.' );

		$delete = $this->push_one( $this->op( 'delete', 'kafela_inquiry', null, null, array( 'remote_id' => $id, 'base_revision' => $update['revision'] ) ) );
		$this->assertSame( 'applied', $delete['status'] );
		$this->assertNull( $this->row( $uuid ) );
		$items = $this->pull_entity( 'kafela_inquiry' );
		$this->assertTrue( $items[ $id ]['deleted'] );
	}

	/**
	 * System and computed columns cannot be written.
	 */
	public function test_readonly_fields_are_rejected() {
		foreach ( array( 'uuid' => 'x', 'status' => 'replied', 'demo' => true, 'package_title' => 'Forged', 'package_slug' => 'abc' ) as $field => $value ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data( array( $field => $value ) ) ) );
			$this->assertSame( 'error', $result['status'], $field );
			$this->assertSame( 'pdock_readonly_field', $result['error']['code'], $field );
			$this->assertSame( $field, $result['error']['field'] );
		}
		$this->assertSame( 0, Pothik\InquiryRepository::count_all() );

		// created_gmt may be set at creation (an offline desktop entry keeps its time) but never changed.
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data( array( 'created_gmt' => '2026-10-01 08:30:00' ) ) ) );
		$this->assertSame( 'applied', $create['status'] );
		$this->assertSame( '2026-10-01 08:30:00', $this->row( $uuid )['created_gmt'] );
		$update = $this->push_one(
			$this->op( 'update', 'kafela_inquiry', null, array( 'created_gmt' => '2027-01-01 00:00:00' ), array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) )
		);
		$this->assertSame( 'error', $update['status'] );
		$this->assertSame( 'pdock_readonly_field', $update['error']['code'] );
	}

	/**
	 * Required fields, enums and ranges are enforced on the server.
	 */
	public function test_schema_validation() {
		$bad = array(
			array( 'name' => '' ),
			array( 'phone' => '' ),
			array( 'share' => 'penthouse' ),
			array( 'trip' => 'cruise' ),
			array( 'adults' => 0 ),
			array( 'adults' => 31 ),
			array( 'children' => 21 ),
			array( 'infants' => 11 ),
			array( 'ref' => 'x' ),
		);
		foreach ( $bad as $case ) {
			$result = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data( $case ) ) );
			$this->assertSame( 'error', $result['status'], wp_json_encode( $case ) );
		}
		$this->assertSame( 0, Pothik\InquiryRepository::count_all() );
	}

	/**
	 * Bangla labels are accepted for share and stored as the canonical English key.
	 */
	public function test_bangla_share_is_canonicalized() {
		$aliases = ( new ReflectionClassConstant( 'Pothik\I18n', 'ALIASES' ) )->getValue();
		$label   = array_search( 'quad', isset( $aliases['share'] ) ? $aliases['share'] : array(), true );
		if ( false === $label ) {
			$this->markTestSkipped( 'This Kafela version has no Bangla alias for "quad".' );
		}

		$uuid   = $this->uuid();
		$result = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data( array( 'share' => (string) $label, 'ref' => 'ab12' ) ) ) );
		$this->assertSame( 'applied', $result['status'], wp_json_encode( $result ) );
		$row = $this->row( $uuid );
		$this->assertSame( 'quad', $row['share'] );
		$this->assertSame( 'AB12', $row['ref'], 'Referral codes are upper-cased like Referral::sanitize().' );
	}

	/**
	 * A package given by uuid in the same batch resolves to the Kafela package id, even when the inquiry is listed first.
	 */
	public function test_relation_by_uuid_in_same_batch() {
		$package_uuid = $this->uuid();
		$inquiry_uuid = $this->uuid();
		$results      = $this->push(
			array(
				$this->op( 'create', 'kafela_inquiry', $inquiry_uuid, $this->inquiry_data( array( 'package_id' => $package_uuid ) ) ),
				$this->op( 'create', 'kafela_package', $package_uuid, $this->package_data( array( 'title' => 'March Umrah', 'package_id' => 'march01' ) ) ),
			)
		);

		// Results come back in request order, execution was dependency order.
		$this->assertSame( 'applied', $results[0]['status'], wp_json_encode( $results[0] ) );
		$this->assertSame( 'applied', $results[1]['status'] );

		$row = $this->row( $inquiry_uuid );
		$this->assertSame( 'march01', $row['package_id'] );
		$this->assertSame( 'March Umrah', $row['package_title'], 'The title snapshot is taken from the package.' );

		$items = $this->pull_entity( 'kafela_inquiry' );
		$this->assertSame( $results[1]['remote_id'], $items[ $results[0]['remote_id'] ]['data']['package_id'], 'Pull shows the package object id.' );
		$this->assertSame( 'march01', $items[ $results[0]['remote_id'] ]['data']['package_slug'] );
	}

	/**
	 * A package given by its numeric remote id also works, and an unknown one is refused.
	 */
	public function test_relation_by_remote_id() {
		$package = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'pk7' ) ) ) );

		$ok = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data( array( 'package_id' => $package['remote_id'] ) ) ) );
		$this->assertSame( 'applied', $ok['status'] );

		$missing = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data( array( 'package_id' => 99999 ) ) ) );
		$this->assertSame( 'error', $missing['status'] );

		$unknown_uuid = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data( array( 'package_id' => $this->uuid() ) ) ) );
		$this->assertSame( 'error', $unknown_uuid['status'] );
		$this->assertSame( 'pdock_unresolved_relation', $unknown_uuid['error']['code'] );
	}

	/**
	 * Deleting a package leaves its inquiries untouched (Kafela's soft reference), and they still pull.
	 */
	public function test_package_delete_keeps_inquiries() {
		$package = $this->push_one( $this->op( 'create', 'kafela_package', $this->uuid(), $this->package_data( array( 'package_id' => 'gone1' ) ) ) );
		$uuid    = $this->uuid();
		$this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data( array( 'package_id' => $package['remote_id'] ) ) ) );
		$this->push_one( $this->op( 'delete', 'kafela_package', null, null, array( 'remote_id' => $package['remote_id'], 'base_revision' => $package['revision'] ) ) );

		$this->assertSame( 'gone1', $this->row( $uuid )['package_id'] );
		$items = array_values( $this->pull_entity( 'kafela_inquiry' ) );
		$this->assertSame( 'gone1', $items[0]['data']['package_slug'] );
	}

	/**
	 * Uuids are unique on the server although Kafela's table only has a plain index.
	 */
	public function test_duplicate_uuid_conflicts() {
		$uuid = $this->uuid();
		Pothik\InquiryRepository::insert( array_merge( $this->inquiry_data(), array( 'uuid' => $uuid ) ) ); // Made by Kafela, unknown to sync.

		$result = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data( array( 'name' => 'Second' ) ) ) );
		$this->assertSame( 'conflict', $result['status'] );
		$this->assertSame( 'unique', $result['reason'] );
		$this->assertSame( 'uuid', $result['field'] );
		$this->assertSame( 'Rahim Uddin', $result['server']['data']['name'] );
		$this->assertSame( 1, Pothik\InquiryRepository::count_all() );
	}

	/**
	 * Inquiries Kafela already holds (public form, demo) appear in the first pull with their own uuid.
	 */
	public function test_existing_rows_are_enumerated() {
		$uuid = Pothik\InquiryRepository::insert( array_merge( $this->inquiry_data(), array( 'uuid' => $this->uuid() ) ) );
		$items = array_values( $this->pull_entity( 'kafela_inquiry' ) );
		$this->assertCount( 1, $items );
		$this->assertSame( $uuid, $items[0]['uuid'] );
	}

	/**
	 * No e-mail is attempted by any write, even when an add-on mails from the created hook.
	 */
	public function test_no_email_during_sync() {
		// An add-on that mails the agency on "inquiry created", with that event switched on.
		add_action(
			'pothik_inquiry_created',
			static function () {
				wp_mail( 'agency@example.test', 'New inquiry', 'Someone asked about a package.' );
			}
		);
		add_filter( 'pdock_kafela_fire_hooks', '__return_true' );

		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data() ) );
		$this->assertSame( 'applied', $create['status'] );
		$this->push_one( $this->op( 'update', 'kafela_inquiry', null, array( 'replied' => true ), array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) ) );

		$this->assertNoMailAttempted();

		// The guard is specific to sync: Kafela's own notification filter is untouched outside it.
		$args = array( 'to' => 'a@example.test' );
		$this->assertSame( $args, apply_filters( 'pothik_notify_email', $args ) );
		Pressdock\Context::run(
			static function () use ( $args ) {
				\PHPUnit\Framework\Assert::assertFalse( apply_filters( 'pothik_notify_email', $args ) );
			}
		);
		wp_mail( 'agency@example.test', 'Outside sync', 'Delivered.' );
		$this->assertCount( 1, $this->mail_attempts, 'Outside sync mail still goes out.' );
	}

	/**
	 * Kafela's own deleted/reply hooks fire for sync writes so Pro's lead cleanup keeps working.
	 */
	public function test_kafela_state_hooks_fire() {
		$deleted = 0;
		$toggled = 0;
		add_action(
			'pothik_inquiry_deleted',
			static function () use ( &$deleted ) {
				++$deleted;
			}
		);
		add_action(
			'pothik_inquiry_reply_toggled',
			static function () use ( &$toggled ) {
				++$toggled;
			}
		);

		$create = $this->push_one( $this->op( 'create', 'kafela_inquiry', $this->uuid(), $this->inquiry_data() ) );
		$update = $this->push_one( $this->op( 'update', 'kafela_inquiry', null, array( 'replied' => true ), array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) ) );
		$this->push_one( $this->op( 'delete', 'kafela_inquiry', null, null, array( 'remote_id' => $create['remote_id'], 'base_revision' => $update['revision'] ) ) );

		$this->assertSame( 1, $toggled );
		$this->assertSame( 1, $deleted );
	}

	/**
	 * A change made in Kafela's admin (reply toggle, delete) reaches the change log.
	 */
	public function test_admin_changes_are_tracked() {
		$uuid = Pothik\InquiryRepository::insert( array_merge( $this->inquiry_data(), array( 'uuid' => $this->uuid() ) ) );
		$this->pull_entity( 'kafela_inquiry' ); // Baseline: Kafela's own rows get a change-log entry.

		$cursor = Pressdock\Store\Changes::max_id();
		Pothik\InquiryRepository::set_replied( $uuid, true );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_inquiry' ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertTrue( $data['items'][0]['data']['replied'] );

		$cursor = $data['next_cursor'];
		Pothik\InquiryRepository::delete_by_id( $uuid );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_inquiry' ) );
		$this->assertTrue( $data['items'][0]['deleted'] );
	}

	/**
	 * Undo of a delete brings the row back with the same id and uuid.
	 */
	public function test_undo_delete_restores_row() {
		$uuid   = $this->uuid();
		$create = $this->push_one( $this->op( 'create', 'kafela_inquiry', $uuid, $this->inquiry_data() ) );
		$batch  = $this->uuid();
		$this->push( array( $this->op( 'delete', 'kafela_inquiry', null, null, array( 'remote_id' => $create['remote_id'], 'base_revision' => $create['revision'] ) ) ), $batch );
		$this->assertNull( $this->row( $uuid ) );

		$this->call( 'POST', '/undo', array( 'batch_id' => $batch ) );
		$row = $this->row( $uuid );
		$this->assertNotNull( $row );
		$this->assertSame( (string) $create['remote_id'], $row['id'] );
		$this->assertSame( 'Rahim Uddin', $row['name'] );
	}
}
