<?php
/**
 * Cross-user isolation.
 *
 * @package Pressdock
 */

/**
 * Users must not read or undo each other's batches.
 */
class SecurityTest extends Pdock_Test_Case {

	/**
	 * Push a create as the current user; returns array( batch_id, op_id, remote_id ).
	 *
	 * @return array
	 */
	private function create_batch() {
		$batch = $this->uuid();
		$op_id = $this->uuid();
		$res   = $this->push(
			array(
				array(
					'op_id'  => $op_id,
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array( 'title' => 'Mine', 'status' => 'draft' ),
				),
			),
			$batch
		);
		return array( $batch, $op_id, $res[0]['remote_id'] );
	}

	/**
	 * Undo is administrator only.
	 */
	public function test_editor_cannot_undo() {
		list( $batch, , $id ) = $this->create_batch();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->call( 'POST', '/undo', array( 'batch_id' => $batch ) )->get_status() );
		$this->assertNotNull( get_post( $id ) );
	}

	/**
	 * Replaying another user's op id does not return that user's stored result.
	 */
	public function test_op_replay_is_scoped_to_user() {
		list( , $op_id, $id ) = $this->create_batch();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$res = $this->push(
			array(
				array(
					'op_id'  => $op_id,
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array( 'title' => 'Other', 'status' => 'draft' ),
				),
			)
		);
		$this->assertArrayNotHasKey( 'replayed', $res[0] );
		$this->assertNotSame( $id, $res[0]['remote_id'] ?? null );
	}
}
