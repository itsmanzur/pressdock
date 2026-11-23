<?php
/**
 * Base class for entities stored as a post type plus postmeta.
 *
 * @package Pressdock
 */

namespace Pressdock\Adapters;

use Pressdock\Context;
use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;
use Pressdock\Contracts\RestorableAdapter;
use Pressdock\Tracker;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Most CPT adapters only implement key(), label(), group(), post_type() and fields().
 *
 * Each field may carry a "storage" entry:
 *   array( 'post_field' => 'post_title' )  a column of the posts table
 *   array( 'meta' => '_my_key' )           a single postmeta value
 *   array( 'thumbnail' => true )           the featured image id
 * Fields without "storage" are stored as postmeta under the field name.
 */
abstract class AbstractPostAdapter implements EntityAdapter, RestorableAdapter, EnumerableAdapter {

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->post_type(),
				(int) $after,
				(int) $limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}


	/**
	 * Post type slug.
	 *
	 * @return string
	 */
	abstract public function post_type();

	/**
	 * Field definitions. See class docblock for "storage".
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * {@inheritdoc}
	 */
	public function schema() {
		return $this->fields();
	}

	/**
	 * Hook admin edits into the change log. Called once by the plugin on init.
	 *
	 * @return void
	 */
	public function register_hooks() {
		$type = $this->post_type();
		add_action( 'save_post_' . $type, array( $this, 'on_save' ), 20, 3 );
		add_action( 'rest_after_insert_' . $type, array( $this, 'on_rest_save' ), 20, 1 );
		add_action( 'trashed_post', array( $this, 'on_remove' ), 20, 1 );
		add_action( 'deleted_post', array( $this, 'on_remove' ), 20, 1 );
		add_action( 'untrash_post', array( $this, 'on_restore' ), 20, 1 );
	}

	/**
	 * Hook: post saved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function on_save( $post_id, $post = null, $update = false ) {
		unset( $update );
		if ( Context::is_syncing() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = $post ? $post : get_post( $post_id );
		if ( ! $post || in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return;
		}
		Tracker::touch( $this->key(), (int) $post_id, 'upsert' );
	}

	/**
	 * Hook: block-editor save, after postmeta is written.
	 *
	 * @param \WP_Post $post Post.
	 * @return void
	 */
	public function on_rest_save( $post ) {
		if ( $post instanceof \WP_Post ) {
			$this->on_save( $post->ID, $post, true );
		}
	}

	/**
	 * Hook: trashed or deleted.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_remove( $post_id ) {
		if ( Context::is_syncing() || get_post_type( $post_id ) !== $this->post_type() ) {
			return;
		}
		Tracker::touch( $this->key(), (int) $post_id, 'delete' );
	}

	/**
	 * Hook: restored from trash.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function on_restore( $post_id ) {
		if ( Context::is_syncing() || get_post_type( $post_id ) !== $this->post_type() ) {
			return;
		}
		Tracker::touch( $this->key(), (int) $post_id, 'upsert' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function capability( $action ) {
		$object = get_post_type_object( $this->post_type() );
		if ( ! $object ) {
			return 'manage_options';
		}
		switch ( $action ) {
			case 'create':
				return $object->cap->create_posts;
			case 'delete':
				return $object->cap->delete_posts;
			case 'update':
			case 'read':
			default:
				return $object->cap->edit_posts;
		}
	}

	/**
	 * Per-object capability check, used by the push processor and pull.
	 *
	 * @param string $action read|update|delete.
	 * @param int    $id     Post id.
	 * @return bool
	 */
	public function can_object( $action, $id ) {
		$meta_cap = 'delete' === $action ? 'delete_post' : 'edit_post';
		return current_user_can( $meta_cap, (int) $id );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== $this->post_type() || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		return $this->build( $post );
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		_prime_post_caches( $ids, false, true );
		$out = array();
		foreach ( $ids as $id ) {
			$row = $this->get( $id );
			if ( null !== $row ) {
				$out[ $id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Read all schema fields of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	protected function build( $post ) {
		$row = array( 'id' => (int) $post->ID );
		foreach ( $this->fields() as $name => $field ) {
			$storage = $this->storage( $name, $field );
			if ( isset( $storage['post_field'] ) ) {
				$value = $post->{$storage['post_field']};
			} elseif ( ! empty( $storage['thumbnail'] ) ) {
				$value = (int) get_post_thumbnail_id( $post->ID );
			} else {
				$value = get_post_meta( $post->ID, $storage['meta'], true );
			}
			$row[ $name ] = $this->cast_out( isset( $field['type'] ) ? $field['type'] : 'string', $value );
		}
		return $row;
	}

	/**
	 * Resolve the storage entry of a field.
	 *
	 * @param string $name  Field name.
	 * @param array  $field Field definition.
	 * @return array
	 */
	protected function storage( $name, array $field ) {
		return isset( $field['storage'] ) && is_array( $field['storage'] ) ? $field['storage'] : array( 'meta' => $name );
	}

	/**
	 * Cast a stored value to its schema type for output.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Stored value.
	 * @return mixed
	 */
	protected function cast_out( $type, $value ) {
		switch ( $type ) {
			case 'int':
			case 'media':
			case 'relation':
				return '' === $value || null === $value ? 0 : (int) $value;
			case 'float':
				return '' === $value || null === $value ? 0.0 : (float) $value;
			case 'bool':
				return (bool) $value;
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Split clean data into post columns and writers for meta and thumbnail.
	 *
	 * @param array $data Clean data.
	 * @return array array( postarr, meta, thumbnail|null )
	 */
	protected function split( array $data ) {
		$postarr   = array();
		$meta      = array();
		$thumbnail = null;
		$fields    = $this->fields();
		foreach ( $data as $name => $value ) {
			if ( ! isset( $fields[ $name ] ) ) {
				continue;
			}
			$storage = $this->storage( $name, $fields[ $name ] );
			if ( isset( $storage['post_field'] ) ) {
				$postarr[ $storage['post_field'] ] = $value;
			} elseif ( ! empty( $storage['thumbnail'] ) ) {
				$thumbnail = (int) $value;
			} else {
				$meta[ $storage['meta'] ] = $value;
			}
		}
		return array( $postarr, $meta, $thumbnail );
	}

	/**
	 * Write meta and thumbnail.
	 *
	 * @param int      $id        Post id.
	 * @param array    $meta      Meta key => value.
	 * @param int|null $thumbnail Featured image id, 0 to remove, null to leave alone.
	 * @return void
	 */
	protected function write_extras( $id, array $meta, $thumbnail ) {
		foreach ( $meta as $key => $value ) {
			if ( null === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, $key, $value );
			}
		}
		if ( null !== $thumbnail ) {
			if ( $thumbnail > 0 ) {
				set_post_thumbnail( $id, $thumbnail );
			} else {
				delete_post_thumbnail( $id );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When WordPress refuses the insert.
	 */
	public function create( array $data ) {
		list( $postarr, $meta, $thumbnail ) = $this->split( $data );
		$postarr['post_type']               = $this->post_type();
		if ( empty( $postarr['post_status'] ) ) {
			$postarr['post_status'] = 'draft';
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( esc_html( $id->get_error_message() ) );
		}
		$this->write_extras( (int) $id, $meta, $thumbnail );
		return (int) $id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When WordPress refuses the update.
	 */
	public function update( $id, array $data ) {
		list( $postarr, $meta, $thumbnail ) = $this->split( $data );
		if ( array() !== $postarr ) {
			$postarr['ID'] = (int) $id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}
		}
		$this->write_extras( (int) $id, $meta, $thumbnail );
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $id, $force ) {
		if ( $force || ! EMPTY_TRASH_DAYS ) {
			wp_delete_post( (int) $id, true );
		} else {
			wp_trash_post( (int) $id );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( array $data, $id = null ) {
		unset( $data, $id );
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function restore( $id, array $before ) {
		$post = get_post( (int) $id );
		if ( ! $post || $post->post_type !== $this->post_type() ) {
			return false;
		}
		if ( 'trash' === $post->post_status ) {
			wp_untrash_post( (int) $id );
		}
		$data = $before;
		unset( $data['id'] );
		$this->update( (int) $id, $data );
		return true;
	}

	/**
	 * Helper for subclasses that want to refuse a status the user cannot set.
	 *
	 * @param string $status Post status.
	 * @return WP_Error|true
	 */
	protected function check_status_capability( $status ) {
		$object = get_post_type_object( $this->post_type() );
		if ( $object && 'publish' === $status && ! current_user_can( $object->cap->publish_posts ) ) {
			return new WP_Error( 'pdock_forbidden_status', 'You may not publish this content.', array( 'field' => 'status' ) );
		}
		return true;
	}
}
