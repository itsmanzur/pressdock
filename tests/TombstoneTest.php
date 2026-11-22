<?php
/**
 * Deletes are delivered as tombstones.
 *
 * @package Pressdock
 */

/**
 * Tests for delete tracking.
 */
class TombstoneTest extends Pdock_Test_Case {

	/**
	 * A delete pushed from the app appears as a tombstone in the next pull.
	 */
	public function test_pushed_delete_becomes_tombstone() {
		list( $id, $uuid, $rev ) = $this->push_create();
		$cursor                  = $this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) )['next_cursor'];

		$result = $this->push(
			array(
				array(
					'op_id'         => $this->uuid(),
					'op'            => 'delete',
					'entity'        => 'post',
					'uuid'          => $uuid,
					'base_revision' => $rev,
					'force'         => true,
				),
			)
		);
		$this->assertSame( 'applied', $result[0]['status'] );
		$this->assertNull( get_post( $id ) );

		$data = $this->pull( $cursor, array( 'entity' => 'post' ) );
		$this->assertCount( 1, $data['items'] );
		$this->assertTrue( $data['items'][0]['deleted'] );
		$this->assertNull( $data['items'][0]['data'] );
		$this->assertSame( $uuid, $data['items'][0]['uuid'] );
	}

	/**
	 * Trashing in wp-admin is a tombstone too, and restoring brings the record back.
	 */
	public function test_admin_trash_and_restore() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$cursor = Pressdock\Store\Changes::max_id();

		wp_trash_post( $id );
		$data = $this->pull( $cursor, array( 'entity' => 'post' ) );
		$this->assertTrue( $data['items'][0]['deleted'] );

		$cursor = $data['next_cursor'];
		wp_untrash_post( $id );
		$data = $this->pull( $cursor, array( 'entity' => 'post' ) );
		$this->assertFalse( $data['items'][0]['deleted'] );
		$this->assertNotNull( $data['items'][0]['data'] );
	}

	/**
	 * Permanent deletion in wp-admin also leaves a tombstone.
	 */
	public function test_admin_permanent_delete() {
		$id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->pull( 0, array( 'entity' => 'post', 'limit' => 500 ) );
		$cursor = Pressdock\Store\Changes::max_id();

		wp_delete_post( $id, true );
		$data = $this->pull( $cursor, array( 'entity' => 'post' ) );
		$this->assertTrue( $data['items'][0]['deleted'] );
		$this->assertSame( $id, $data['items'][0]['remote_id'] );
	}

	/**
	 * Deleting twice with the right revision is harmless.
	 */
	public function test_delete_is_idempotent_by_revision() {
		list( , $uuid, $rev ) = $this->push_create();
		$op                   = function () use ( $uuid, $rev ) {
			return array(
				'op_id'         => $this->uuid(),
				'op'            => 'delete',
				'entity'        => 'post',
				'uuid'          => $uuid,
				'base_revision' => $rev,
				'force'         => true,
			);
		};
		$first                = $this->push( array( $op() ) );
		$this->assertSame( 'applied', $first[0]['status'] );

		$deleted_revision = $first[0]['revision'];
		$again            = $this->push(
			array(
				array_merge( $op(), array( 'base_revision' => $deleted_revision ) ),
			)
		);
		$this->assertSame( 'applied', $again[0]['status'] );
	}
}
