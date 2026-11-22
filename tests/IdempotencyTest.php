<?php
/**
 * Retried batches never apply twice.
 *
 * @package Pressdock
 */

/**
 * Tests for the op_id log.
 */
class IdempotencyTest extends Pdock_Test_Case {

	/**
	 * Replaying a create batch returns the same result and creates one post.
	 */
	public function test_retried_create_applies_once() {
		$op = array(
			'op_id'  => $this->uuid(),
			'op'     => 'create',
			'entity' => 'post',
			'uuid'   => $this->uuid(),
			'data'   => array(
				'title'  => 'Once only',
				'status' => 'draft',
			),
		);

		$batch = $this->uuid();
		$first = $this->push( array( $op ), $batch );
		$again = $this->push( array( $op ), $batch );

		$this->assertSame( 'applied', $first[0]['status'] );
		$this->assertSame( $first[0]['remote_id'], $again[0]['remote_id'] );
		$this->assertTrue( $again[0]['replayed'] );

		$found = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
				'title'       => 'Once only',
				'numberposts' => -1,
			)
		);
		$this->assertCount( 1, $found );
	}

	/**
	 * Replaying an update does not bump the revision a second time.
	 */
	public function test_retried_update_does_not_bump_revision_twice() {
		list( $id, $uuid, $rev ) = $this->push_create();
		$op                      = array(
			'op_id'         => $this->uuid(),
			'op'            => 'update',
			'entity'        => 'post',
			'uuid'          => $uuid,
			'base_revision' => $rev,
			'data'          => array( 'title' => 'Updated' ),
		);

		$first = $this->push( array( $op ) );
		$again = $this->push( array( $op ) );

		$this->assertSame( 'applied', $again[0]['status'], 'The stored result is returned, not a conflict.' );
		$this->assertSame( $first[0]['revision'], $again[0]['revision'] );
		$this->assertSame( $rev + 1, Pressdock\Store\Records::revision( 'post', $id ) );
	}

	/**
	 * A new op_id with an already-bound uuid does not create a duplicate.
	 */
	public function test_same_uuid_with_new_op_id_is_idempotent() {
		list( $id, $uuid ) = $this->push_create( 'Dup' );
		$result            = $this->push(
			array(
				array(
					'op_id'  => $this->uuid(),
					'op'     => 'create',
					'entity' => 'post',
					'uuid'   => $uuid,
					'data'   => array( 'title' => 'Dup' ),
				),
			)
		);
		$this->assertSame( 'applied', $result[0]['status'] );
		$this->assertSame( $id, $result[0]['remote_id'] );
	}

	/**
	 * Adapters can see the sync flag, so they can mute side effects.
	 */
	public function test_context_is_syncing_during_push() {
		$seen = null;
		add_action(
			'save_post_post',
			static function () use ( &$seen ) {
				$seen = Pressdock\Context::is_syncing();
			},
			1
		);
		$this->push_create();
		$this->assertTrue( $seen );
		$this->assertFalse( Pressdock\Context::is_syncing() );
	}

	/**
	 * More than 100 ops are refused.
	 */
	public function test_batch_limit() {
		$ops = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$ops[] = array(
				'op_id'  => $this->uuid(),
				'op'     => 'create',
				'entity' => 'post',
				'uuid'   => $this->uuid(),
				'data'   => array( 'title' => 'x' ),
			);
		}
		$response = $this->call(
			'POST',
			'/push',
			array(
				'batch_id' => $this->uuid(),
				'ops'      => $ops,
			)
		);
		$this->assertSame( 413, $response->get_status() );
	}
}
