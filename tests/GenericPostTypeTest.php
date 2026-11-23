<?php
/**
 * Generic post type adapter: configured without code from the admin screen.
 *
 * @package Pressdock
 */

use Pressdock\Adapters\GenericPostAdapter;
use Pressdock\Adapters\PostTypes;
use Pressdock\Registry;
use Pressdock\Settings;

/**
 * Post types, taxonomies and chosen meta keys round-trip through the adapter.
 */
class GenericPostTypeTest extends Pdock_Test_Case {

	/**
	 * Register a test post type with a taxonomy and one registered meta key.
	 */
	public function set_up() {
		parent::set_up();
		register_post_type(
			'pdock_book',
			array(
				'public'   => true,
				'show_ui'  => true,
				'supports' => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
			)
		);
		register_taxonomy( 'pdock_genre', 'pdock_book', array( 'show_ui' => true ) );
		register_post_meta(
			'pdock_book',
			'pages',
			array(
				'type'   => 'integer',
				'single' => true,
			)
		);
	}

	/**
	 * Clean up registrations.
	 */
	public function tear_down() {
		unregister_post_meta( 'pdock_book', 'pages' );
		unregister_taxonomy( 'pdock_genre' );
		unregister_post_type( 'pdock_book' );
		parent::tear_down();
	}

	/**
	 * Schema follows what the post type supports and the chosen meta keys.
	 */
	public function test_schema_follows_post_type() {
		$adapter = new GenericPostAdapter( 'pdock_book', array( 'pages' => 'int' ) );
		$schema  = $adapter->schema();
		$this->assertSame( 'pt_pdock_book', $adapter->key() );
		$this->assertArrayHasKey( 'title', $schema );
		$this->assertArrayHasKey( 'excerpt', $schema );
		$this->assertArrayHasKey( 'featured_image', $schema );
		$this->assertSame( 'list', $schema['pdock_genre']['type'] );
		$this->assertSame( 'int', $schema['pages']['type'] );
		$this->assertArrayNotHasKey( 'parent', $schema ); // Not hierarchical.
	}

	/**
	 * Create and update write post columns, terms and meta; unknown meta stays untouched.
	 */
	public function test_round_trip_with_terms_and_meta() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$adapter = new GenericPostAdapter( 'pdock_book', array( 'pages' => 'int' ) );

		$id = $adapter->create(
			array(
				'title'       => 'Shonar Tori',
				'pdock_genre' => array( 'Poetry' ),
				'pages'       => 120,
			)
		);
		update_post_meta( $id, 'gallery', array( 1, 2 ) );

		$adapter->update(
			$id,
			array(
				'pdock_genre' => array( 'Poetry', 'Classic' ),
				'pages'       => 130,
			)
		);
		$row = $adapter->get( $id );
		$this->assertSame( 'Shonar Tori', $row['title'] );
		$this->assertSame( array( 'Classic', 'Poetry' ), $this->sorted( $row['pdock_genre'] ) );
		$this->assertSame( 130, $row['pages'] );
		$this->assertSame( array( 1, 2 ), get_post_meta( $id, 'gallery', true ) );
	}

	/**
	 * A user who may not create terms can only assign existing ones.
	 */
	public function test_new_terms_need_edit_terms_capability() {
		wp_insert_term( 'Poetry', 'pdock_genre' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$adapter = new GenericPostAdapter( 'pdock_book' );
		$id      = $adapter->create(
			array(
				'title'       => 'X',
				'pdock_genre' => array( 'Poetry', 'Brand new' ),
			)
		);
		$this->assertSame( array( 'Poetry' ), $adapter->get( $id )['pdock_genre'] );
		$this->assertFalse( get_term_by( 'name', 'Brand new', 'pdock_genre' ) );
	}

	/**
	 * Configured post types register as entities; dedicated adapters and excluded types are skipped.
	 */
	public function test_configuration_registers_entities() {
		$clean = PostTypes::sanitize(
			array(
				'pdock_book' => array(
					'on'   => 1,
					'meta' => array(
						'pages'  => 'int',
						'_hide'  => 'string',
						'unused' => '',
					),
				),
				'attachment' => array( 'on' => 1 ),
				'nope'       => array( 'on' => 1 ),
			)
		);
		$this->assertSame( array( 'pdock_book' ), array_keys( $clean ) );
		$this->assertSame( array( 'pages' => 'int' ), $clean['pdock_book']['meta'] );

		update_option( Settings::OPTION, array_merge( Settings::all(), array( 'post_types' => $clean ) ) );
		Registry::reset();
		$this->assertArrayHasKey( 'pt_pdock_book', Registry::all() );
		$this->assertArrayNotHasKey( 'pt_post', Registry::all() ); // "post" has a dedicated adapter.
	}

	/**
	 * Serialized meta is never offered, so it cannot be overwritten as text.
	 */
	public function test_serialized_meta_is_not_offered() {
		$id = self::factory()->post->create( array( 'post_type' => 'pdock_book' ) );
		update_post_meta( $id, 'gallery', array( 1, 2 ) );
		update_post_meta( $id, 'author_name', 'Tagore' );
		$candidates = PostTypes::meta_candidates( 'pdock_book' );
		$this->assertArrayHasKey( 'author_name', $candidates );
		$this->assertSame( 'int', $candidates['pages'] );
		$this->assertArrayNotHasKey( 'gallery', $candidates );
	}

	/**
	 * Sort helper.
	 *
	 * @param array $list Values.
	 * @return array
	 */
	private function sorted( array $list ) {
		sort( $list );
		return $list;
	}
}
