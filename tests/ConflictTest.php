<?php
/**
 * Optimistic concurrency.
 *
 * @package Pressdock
 */

/**
 * Tests for revision checks on update and delete.
 */
class ConflictTest extends Pdock_Test_Case {

	/**
	 * A matching base_revision applies and bumps the revision.
	 */
	public function test_update_with_current_revision_applies() {
		list( $id, $uuid, $rev ) = $this->push_create( 'Original' );

		$result = $this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'update',
					'entity'        => 'post',
					'uuid'          => $uuid,
					'base_revision' => $rev,
					'data'          => array( 'title' => 'Changed' ),
				),
			)
		);

		$this->assertSame( 'applied', $result[0]['status'] );
		$this->assertSame( $rev + 1, $result[0]['revision'] );
		$this->assertSame( 'Changed', get_the_title( $id ) );
	}

	/**
	 * A stale base_revision is not applied and returns the server record.
	 */
	public function test_stale_update_returns_conflict() {
		list( $id, $uuid, $rev ) = $this->push_create( 'Original' );

		// Someone edits in wp-admin meanwhile.
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Edited in admin' ) );

		$result = $this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'update',
					'entity'        => 'post',
					'remote_id'     => $id,
					'base_revision' => $rev,
					'data'          => array( 'title' => 'Offline edit' ),
				),
			)
		);

		$this->assertSame( 'conflict', $result[0]['status'] );
		$this->assertSame( 'Edited in admin', get_the_title( $id ), 'Server data is untouched.' );
		$this->assertSame( 'Edited in admin', $result[0]['server']['data']['title'] );
		$this->assertGreaterThan( $rev, $result[0]['server']['revision'] );
		$this->assertSame( $result[0]['revision'], $result[0]['server']['revision'] );
	}

	/**
	 * A stale delete does not delete.
	 */
	public function test_stale_delete_returns_conflict() {
		list( $id, $uuid, $rev ) = $this->push_create( 'Keep me' );
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Keep me 2' ) );

		$result = $this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'delete',
					'entity'        => 'post',
					'uuid'          => $uuid,
					'base_revision' => $rev,
				),
			)
		);

		$this->assertSame( 'conflict', $result[0]['status'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
	}

	/**
	 * update and delete must carry base_revision.
	 */
	public function test_base_revision_is_required() {
		list( , $uuid ) = $this->push_create();
		$result         = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'update',
					'entity' => 'post',
					'uuid'   => $uuid,
					'data'   => array( 'title' => 'x' ),
				),
			)
		);
		$this->assertSame( 'error', $result[0]['status'] );
		$this->assertSame( 'pdock_base_revision_required', $result[0]['error']['code'] );
	}

	/**
	 * One failing op does not abort the rest of the batch.
	 */
	public function test_one_error_does_not_abort_batch() {
		$uuid   = $this->uuid();
		$result = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $this->uuid(),
					'data'   => array( 'status' => 'draft' ), // Missing required title.
				),
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $uuid,
					'data'   => array( 'title' => 'Fine' ),
				),
			)
		);
		$this->assertSame( 'error', $result[0]['status'] );
		$this->assertSame( 'pdock_required', $result[0]['error']['code'] );
		$this->assertSame( 'applied', $result[1]['status'] );
	}
}
