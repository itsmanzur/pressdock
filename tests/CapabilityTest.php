<?php
/**
 * Capability and transport checks.
 *
 * @package Pressdock
 */

/**
 * Tests for access control.
 */
class CapabilityTest extends Pdock_Test_Case {

	/**
	 * Anonymous requests are refused.
	 */
	public function test_requires_authentication() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/handshake' )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', '/pull' )->get_status() );
	}

	/**
	 * Plain HTTP is refused on a non-local site, even with WP_DEBUG on (as it is in the test suite).
	 */
	public function test_insecure_transport_is_refused() {
		unset( $_SERVER['HTTPS'] );
		if ( 'local' === wp_get_environment_type() ) {
			$this->markTestSkipped( 'The "local" environment type allows plain HTTP by design.' );
		}
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'The test suite runs with WP_DEBUG on.' );
		$response = $this->call( 'GET', '/handshake' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'pdock_insecure_transport', $response->get_data()['code'] );
	}

	/**
	 * The "allow local HTTP" setting only opens plain HTTP on a local development host.
	 */
	public function test_local_http_setting_needs_a_local_host() {
		unset( $_SERVER['HTTPS'] );
		if ( 'local' === wp_get_environment_type() ) {
			$this->markTestSkipped( 'The "local" environment type allows plain HTTP by design.' );
		}
		update_option( Pressdock\Settings::OPTION, array_merge( Pressdock\Settings::all(), array( 'allow_local_http' => 1 ) ) );

		// Test domain (example.org) is not a local host: still refused.
		$this->assertSame( 403, $this->call( 'GET', '/handshake' )->get_status() );

		$local = static function () {
			return 'http://mysite.local';
		};
		add_filter( 'home_url', $local );
		$status = $this->call( 'GET', '/handshake' )->get_status();
		remove_filter( 'home_url', $local );
		$this->assertSame( 200, $status );
	}

	/**
	 * A subscriber cannot read, create or delete posts through the API.
	 */
	public function test_subscriber_is_denied() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 403, $this->call( 'GET', '/pull', null, array( 'entity' => 'post' ) )->get_status() );

		$result = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array( 'title' => 'Nope' ),
				),
			)
		);
		$this->assertSame( 'error', $result[0]['status'] );
		$this->assertSame( 'pdock_forbidden', $result[0]['error']['code'] );
	}

	/**
	 * The handshake lists only entities the user may touch.
	 */
	public function test_handshake_lists_only_allowed_entities() {
		$data = $this->call( 'GET', '/handshake' )->get_data();
		$keys = wp_list_pluck( $data['entities'], 'key' );
		$this->assertContains( 'post', $keys );
		$this->assertSame( $this->admin_id, $data['user']['id'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$data = $this->call( 'GET', '/handshake' )->get_data();
		$this->assertSame( array(), $data['entities'] );
	}

	/**
	 * A contributor cannot publish through sync, because they cannot publish in wp-admin.
	 */
	public function test_contributor_cannot_publish() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$result = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array(
						'title'  => 'Sneaky',
						'status' => 'publish',
					),
				),
			)
		);
		$this->assertSame( 'error', $result[0]['status'] );
		$this->assertSame( 'pdock_forbidden_status', $result[0]['error']['code'] );
	}

	/**
	 * An author cannot edit someone else's post (per-object capability).
	 */
	public function test_author_cannot_edit_other_users_post() {
		list( $id, , $rev ) = $this->push_create( 'Admin post' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$result = $this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'update',
					'entity'        => 'post',
					'remote_id'     => $id,
					'base_revision' => $rev,
					'data'          => array( 'title' => 'Hijack' ),
				),
			)
		);
		$this->assertSame( 'error', $result[0]['status'] );
		$this->assertSame( 'pdock_forbidden', $result[0]['error']['code'] );
		$this->assertSame( 'Admin post', get_the_title( $id ) );
	}

	/**
	 * Undo is administrator only.
	 */
	public function test_undo_requires_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->call( 'POST', '/undo', array( 'batch_id' => $this->uuid() ) );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A disabled entity is invisible and rejected.
	 */
	public function test_disabled_entity_is_rejected() {
		update_option( 'pdock_settings', array( 'disabled_entities' => array( 'post' ) ) );
		Pressdock\Registry::reset();
		$this->assertSame( 404, $this->call( 'GET', '/pull', null, array( 'entity' => 'post' ) )->get_status() );
	}
}
