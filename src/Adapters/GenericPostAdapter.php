<?php
/**
 * Adapter for any post type, configured by an administrator without code.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built from the post type's registration: core columns it supports, its taxonomies,
 * and the meta keys the administrator chose (each with a type).
 */
final class GenericPostAdapter extends AbstractPostAdapter {

	/**
	 * Types an administrator may assign to a meta key.
	 */
	const META_TYPES = array( 'string', 'text', 'html', 'int', 'float', 'bool', 'date' );

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Meta key => field type.
	 *
	 * @var array
	 */
	private $meta;

	/**
	 * Field cache.
	 *
	 * @var array|null
	 */
	private $fields = null;

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type slug.
	 * @param array  $meta      Meta key => type.
	 */
	public function __construct( $post_type, array $meta = array() ) {
		$this->type = (string) $post_type;
		$this->meta = $meta;
	}

	/**
	 * Entity key for a post type: "pt_" plus the slug in [a-z0-9_].
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	public static function key_for( $post_type ) {
		return substr( 'pt_' . preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $post_type ) ), 0, 64 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function key() {
		return self::key_for( $this->type );
	}

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		$object = get_post_type_object( $this->type );
		return $object && ! empty( $object->labels->name ) ? (string) $object->labels->name : $this->type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function group() {
		return __( 'Post types', 'pressdock' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function post_type() {
		return $this->type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function fields() {
		if ( null !== $this->fields ) {
			return $this->fields;
		}
		$type   = $this->type;
		$object = get_post_type_object( $type );
		$fields = array();

		if ( post_type_supports( $type, 'title' ) ) {
			$fields['title'] = array(
				'type'       => 'string',
				'label'      => __( 'Title', 'pressdock' ),
				'max_length' => 500,
				'storage'    => array( 'post_field' => 'post_title' ),
			);
		}
		if ( post_type_supports( $type, 'editor' ) ) {
			$fields['content'] = array(
				'type'    => 'html',
				'label'   => __( 'Content', 'pressdock' ),
				'storage' => array( 'post_field' => 'post_content' ),
			);
		}
		if ( post_type_supports( $type, 'excerpt' ) ) {
			$fields['excerpt'] = array(
				'type'    => 'text',
				'label'   => __( 'Excerpt', 'pressdock' ),
				'storage' => array( 'post_field' => 'post_excerpt' ),
			);
		}
		$fields['status'] = array(
			'type'    => 'enum',
			'label'   => __( 'Status', 'pressdock' ),
			'options' => array( 'draft', 'pending', 'private', 'publish' ),
			'storage' => array( 'post_field' => 'post_status' ),
		);
		$fields['slug']   = array(
			'type'       => 'string',
			'label'      => __( 'Slug', 'pressdock' ),
			'max_length' => 200,
			'storage'    => array( 'post_field' => 'post_name' ),
		);
		if ( $object && $object->hierarchical ) {
			$fields['parent'] = array(
				'type'    => 'relation',
				'label'   => __( 'Parent', 'pressdock' ),
				'target'  => $this->key(),
				'storage' => array( 'post_field' => 'post_parent' ),
			);
		}
		if ( post_type_supports( $type, 'page-attributes' ) ) {
			$fields['menu_order'] = array(
				'type'    => 'int',
				'label'   => __( 'Order', 'pressdock' ),
				'storage' => array( 'post_field' => 'menu_order' ),
			);
		}
		if ( post_type_supports( $type, 'thumbnail' ) ) {
			$fields['featured_image'] = array(
				'type'    => 'media',
				'label'   => __( 'Featured image', 'pressdock' ),
				'storage' => array( 'thumbnail' => true ),
			);
		}

		foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
			if ( 'post_format' === $tax->name || empty( $tax->show_ui ) ) {
				continue;
			}
			$name            = $this->unique_name( preg_replace( '/[^a-z0-9_]/', '_', strtolower( $tax->name ) ), $fields );
			$fields[ $name ] = array(
				'type'      => 'list',
				'label'     => (string) $tax->labels->name,
				'max_items' => 100,
				'storage'   => array( 'taxonomy' => $tax->name ),
			);
		}

		foreach ( $this->meta as $meta_key => $meta_type ) {
			$meta_type       = in_array( $meta_type, self::META_TYPES, true ) ? $meta_type : 'string';
			$name            = $this->unique_name( preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $meta_key ) ), $fields );
			$fields[ $name ] = array(
				'type'    => $meta_type,
				'label'   => (string) $meta_key,
				'storage' => array( 'meta' => (string) $meta_key ),
			);
		}

		$this->fields = $fields;
		return $fields;
	}

	/**
	 * A field name that does not collide with an existing one.
	 *
	 * @param string $name   Wanted name.
	 * @param array  $fields Fields so far.
	 * @return string
	 */
	private function unique_name( $name, array $fields ) {
		$name = '' === trim( $name, '_' ) ? 'field' : $name;
		if ( ! isset( $fields[ $name ] ) ) {
			return $name;
		}
		$i = 2;
		while ( isset( $fields[ $name . '_' . $i ] ) ) {
			++$i;
		}
		return $name . '_' . $i;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function cast_out( $type, $value ) {
		if ( 'date' === $type ) {
			return is_string( $value ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
		}
		return parent::cast_out( $type, $value );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( array $data, $id = null ) {
		unset( $id );
		if ( isset( $data['status'] ) ) {
			return $this->check_status_capability( $data['status'] );
		}
		return true;
	}
}
