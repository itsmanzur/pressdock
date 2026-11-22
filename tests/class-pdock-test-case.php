<?php
/**
 * Shared helpers for Pressdock tests.
 *
 * @package Pressdock
 */

/**
 * Base test case: HTTPS, an administrator, and REST helpers.
 */
abstract class Pdock_Test_Case extends WP_UnitTestCase {

	/**
	 * Administrator id.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$_SERVER['HTTPS'] = 'on';
		Pressdock\Registry::reset();
		delete_option( 'pdock_baseline' );
		delete_option( 'pdock_settings' );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	/**
	 * New UUID.
	 *
	 * @return string
	 */
	protected function uuid() {
		return wp_generate_uuid4();
	}

	/**
	 * Dispatch a REST request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route under pressdock/v1.
	 * @param array|null $body   JSON body.
	 * @param array      $query  Query params.
	 * @return WP_REST_Response
	 */
	protected function call( $method, $route, $body = null, array $query = array() ) {
		$request = new WP_REST_Request( $method, '/pressdock/v1' . $route );
		foreach ( $query as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( null !== $body ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}

	/**
	 * Push ops and return the decoded result list.
	 *
	 * @param array       $ops      Ops.
	 * @param string|null $batch_id Batch id.
	 * @return array
	 */
	protected function push( array $ops, $batch_id = null ) {
		$response = $this->call(
			'POST',
			'/push',
			array(
				'batch_id' => $batch_id ? $batch_id : $this->uuid(),
				'ops'      => $ops,
			)
		);
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data()['results'];
	}

	/**
	 * Create a post through the sync API and return array( remote_id, uuid, revision ).
	 *
	 * @param string $title Title.
	 * @return array
	 */
	protected function push_create( $title = 'Hello' ) {
		$uuid   = $this->uuid();
		$result = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $uuid,
					'data'   => array(
						'title'  => $title,
						'status' => 'draft',
					),
				),
			)
		);
		$this->assertSame( 'applied', $result[0]['status'] );
		return array( $result[0]['remote_id'], $uuid, $result[0]['revision'] );
	}

	/**
	 * Pull helper.
	 *
	 * @param int   $since Cursor.
	 * @param array $query Extra query params.
	 * @return array
	 */
	protected function pull( $since = 0, array $query = array() ) {
		$response = $this->call( 'GET', '/pull', null, array_merge( array( 'since' => $since ), $query ) );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}
}
