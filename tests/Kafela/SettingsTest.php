<?php
/**
 * Hejazer Kafela settings adapter.
 *
 * @package Pressdock
 */

/**
 * Entity "kafela_settings": option "pothik_settings" (a single record, object id 1).
 */
class Pdock_Kafela_Settings_Test extends Pdock_Kafela_Test_Case {

	/**
	 * Settings always exist, so there is no create: the adapter reports a uniqueness conflict.
	 * Update changes the real option, delete is not offered.
	 */
	public function test_create_update_delete_and_pull() {
		// Pull: defaults are the record even before anything was saved.
		$items = $this->pull_entity( 'kafela_settings' );
		$this->assertSame( 'Hejazer Kafela', $items[1]['data']['agency'] );
		$this->assertSame( 5, $items[1]['data']['rate_limit'] );
		$this->assertFalse( $items[1]['data']['delete_on_uninstall'] );

		// Create: conflict that carries the existing record.
		$create = $this->push_one( $this->op( 'create', 'kafela_settings', $this->uuid(), array( 'agency' => 'Al Safa Tours' ) ) );
		$this->assertSame( 'conflict', $create['status'] );
		$this->assertSame( 'unique', $create['reason'] );
		$this->assertSame( 1, $create['remote_id'] );

		// Update.
		$update = $this->push_one(
			$this->op(
				'update',
				'kafela_settings',
				null,
				array(
					'agency'              => 'Al Safa Tours',
					'rate_limit'          => 12,
					'country_code'        => '+966',
					'notification_emails' => 'a@example.test; b@example.test',
				),
				array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] )
			)
		);
		$this->assertSame( 'error', $update['status'], 'Kafela would silently turn "+966" into "966"; the adapter wants clean digits.' );

		$update = $this->push_one(
			$this->op(
				'update',
				'kafela_settings',
				null,
				array(
					'agency'              => 'Al Safa Tours',
					'rate_limit'          => 12,
					'country_code'        => '966',
					'notification_emails' => 'a@example.test; b@example.test',
				),
				array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] )
			)
		);
		$this->assertSame( 'applied', $update['status'] );
		$stored = get_option( 'pothik_settings' );
		$this->assertSame( 'Al Safa Tours', $stored['agency'] );
		$this->assertSame( 12, $stored['rate_limit'] );
		$this->assertSame( '966', $stored['country_code'] );
		$this->assertSame( 'a@example.test, b@example.test', $stored['notification_emails'], 'Kafela\'s own sanitizer normalized the list.' );
		$this->assertSame( 'হেজাজের কাফেলা', $stored['agency_bn'], 'Omitted fields keep their value: the whole object is sent to Kafela.' );
		$this->assertSame( '01711-000000', $stored['phone'] );

		$pulled = $this->pull_entity( 'kafela_settings' );
		$this->assertSame( 'Al Safa Tours', $pulled[1]['data']['agency'] );

		// Delete: not allowed for this entity.
		$delete = $this->push_one( $this->op( 'delete', 'kafela_settings', null, null, array( 'remote_id' => 1, 'base_revision' => $update['revision'] ) ) );
		$this->assertSame( 'error', $delete['status'] );
		$this->assertSame( 'pdock_forbidden', $delete['error']['code'] );
		$this->assertSame( 'Al Safa Tours', get_option( 'pothik_settings' )['agency'] );
	}

	/**
	 * The uninstall switch is read-only for sync: a remote edit must never arm "delete everything".
	 */
	public function test_readonly_delete_on_uninstall_is_rejected() {
		$items  = $this->pull_entity( 'kafela_settings' );
		$result = $this->push_one(
			$this->op( 'update', 'kafela_settings', null, array( 'delete_on_uninstall' => true ), array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] ) )
		);
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 'pdock_readonly_field', $result['error']['code'] );
		$this->assertSame( 'delete_on_uninstall', $result['error']['field'] );
		$this->assertFalse( get_option( 'pothik_settings', array() )['delete_on_uninstall'] ?? false );

		// An existing value armed in Kafela's own screen is preserved by unrelated updates.
		update_option( 'pothik_settings', array_merge( Pothik\Admin\Settings::get_all(), array( 'delete_on_uninstall' => true ) ), false );
		$items  = $this->pull_entity( 'kafela_settings' );
		$result = $this->push_one( $this->op( 'update', 'kafela_settings', null, array( 'license' => 'L-1' ), array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] ) ) );
		$this->assertSame( 'applied', $result['status'] );
		$this->assertTrue( get_option( 'pothik_settings' )['delete_on_uninstall'] );
	}

	/**
	 * Server-side limits: rate limit range, e-mail syntax, logo must be a real attachment.
	 */
	public function test_validation() {
		$items = $this->pull_entity( 'kafela_settings' );
		$rev   = $items[1]['revision'];
		$bad   = array(
			array( 'rate_limit' => 0 ),
			array( 'rate_limit' => 101 ),
			array( 'notification_emails' => 'not-an-email' ),
			array( 'logo_id' => 999999 ),
		);
		foreach ( $bad as $case ) {
			$result = $this->push_one( $this->op( 'update', 'kafela_settings', null, $case, array( 'remote_id' => 1, 'base_revision' => $rev ) ) );
			$this->assertSame( 'error', $result['status'], wp_json_encode( $case ) );
		}

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		if ( $attachment ) {
			$ok = $this->push_one( $this->op( 'update', 'kafela_settings', null, array( 'logo_id' => $attachment ), array( 'remote_id' => 1, 'base_revision' => $rev ) ) );
			$this->assertSame( 'applied', $ok['status'] );
			$this->assertSame( $attachment, get_option( 'pothik_settings' )['logo_id'] );
		}
	}

	/**
	 * Kafela's saved hook fires once for the write and no e-mail is attempted.
	 */
	public function test_saved_hook_fires_without_email() {
		$fired = 0;
		add_action(
			'pothik_settings_saved',
			static function () use ( &$fired ) {
				++$fired;
				wp_mail( 'agency@example.test', 'Settings saved', 'Body' );
			}
		);
		$items = $this->pull_entity( 'kafela_settings' );
		$this->push_one( $this->op( 'update', 'kafela_settings', null, array( 'agency' => 'X' ), array( 'remote_id' => 1, 'base_revision' => $items[1]['revision'] ) ) );
		$this->assertSame( 1, $fired );
		$this->assertNoMailAttempted();
	}

	/**
	 * A save made in Kafela's own screen reaches the change log.
	 */
	public function test_admin_change_is_tracked() {
		$this->pull_entity( 'kafela_settings' );
		$cursor = Pressdock\Store\Changes::max_id();
		do_action( 'pothik_settings_saved', array(), array() );
		$data = $this->pull( $cursor, array( 'entity' => 'kafela_settings' ) );
		$this->assertCount( 1, $data['items'] );
	}
}
