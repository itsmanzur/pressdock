<?php
/**
 * Base class for entities stored in a custom database table.
 *
 * @package Pressdock
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

namespace Pressdock\Adapters;

use Pressdock\Contracts\EntityAdapter;
use Pressdock\Contracts\EnumerableAdapter;
use Pressdock\Tracker;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom tables fire no WordPress hooks, so the owning plugin (or a subclass hook) must call track()
 * after its own admin saves and deletes. Writes made through the sync API are tracked automatically.
 *
 * Each field may carry "column" (defaults to the field name).
 */
abstract class AbstractTableAdapter implements EntityAdapter, EnumerableAdapter {

	/**
	 * {@inheritdoc}
	 */
	public function ids_after( $after, $limit ) {
		global $wpdb;
		$table = $this->full_table();
		$pk    = $this->primary_key();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT `{$pk}` FROM `{$table}` WHERE `{$pk}` > %d ORDER BY `{$pk}` ASC LIMIT %d", (int) $after, (int) $limit ) );
		return array_map( 'intval', (array) $ids );
	}


	/**
	 * Table name without the WordPress prefix.
	 *
	 * @return string
	 */
	abstract public function table();

	/**
	 * Field definitions.
	 *
	 * @return array
	 */
	abstract public function fields();

	/**
	 * Primary key column (auto-increment integer).
	 *
	 * @return string
	 */
	public function primary_key() {
		return 'id';
	}

	/**
	 * {@inheritdoc}
	 */
	public function schema() {
		return $this->fields();
	}

	/**
	 * Full table name.
	 *
	 * @return string
	 */
	protected function full_table() {
		global $wpdb;
		return $wpdb->prefix . $this->table();
	}

	/**
	 * Tell the change log that a row changed. Call from the owning plugin's admin code.
	 *
	 * @param int    $id     Row id.
	 * @param string $action upsert|delete.
	 * @return void
	 */
	public function track( $id, $action = 'upsert' ) {
		Tracker::touch( $this->key(), (int) $id, $action );
	}

	/**
	 * {@inheritdoc}
	 */
	public function capability( $action ) {
		unset( $action );
		return 'manage_options';
	}

	/**
	 * Column for a field.
	 *
	 * @param string $name  Field name.
	 * @param array  $field Field definition.
	 * @return string
	 */
	protected function column( $name, array $field ) {
		$column = isset( $field['column'] ) ? $field['column'] : $name;
		return preg_replace( '/[^A-Za-z0-9_]/', '', $column );
	}

	/**
	 * $wpdb format for a field type.
	 *
	 * @param string $type Field type.
	 * @return string
	 */
	protected function format( $type ) {
		switch ( $type ) {
			case 'int':
			case 'bool':
			case 'relation':
			case 'media':
				return '%d';
			case 'float':
				return '%f';
			default:
				return '%s';
		}
	}

	/**
	 * Turn a DB row into a record.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	protected function build( array $row ) {
		$pk  = $this->primary_key();
		$out = array( 'id' => (int) $row[ $pk ] );
		foreach ( $this->fields() as $name => $field ) {
			$value = isset( $row[ $this->column( $name, $field ) ] ) ? $row[ $this->column( $name, $field ) ] : null;
			switch ( isset( $field['type'] ) ? $field['type'] : 'string' ) {
				case 'int':
				case 'media':
				case 'relation':
					$value = (int) $value;
					break;
				case 'float':
					$value = (float) $value;
					break;
				case 'bool':
					$value = (bool) $value;
					break;
				default:
					$value = null === $value ? '' : (string) $value;
			}
			$out[ $name ] = $value;
		}
		return $out;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		global $wpdb;
		$table = $this->full_table();
		$pk    = $this->primary_key();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $this->build( $row ) : null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function list( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$table        = $this->full_table();
		$pk           = $this->primary_key();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE `{$pk}` IN ({$placeholders})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$out          = array();
		foreach ( (array) $rows as $row ) {
			$record              = $this->build( $row );
			$out[ $record['id'] ] = $record;
		}
		return $out;
	}

	/**
	 * Map clean data to columns and formats.
	 *
	 * @param array $data Clean data.
	 * @return array array( values, formats )
	 */
	protected function to_columns( array $data ) {
		$values  = array();
		$formats = array();
		foreach ( $this->fields() as $name => $field ) {
			if ( ! array_key_exists( $name, $data ) ) {
				continue;
			}
			$column            = $this->column( $name, $field );
			$value             = $data[ $name ];
			$values[ $column ] = is_bool( $value ) ? (int) $value : $value;
			$formats[]         = $this->format( isset( $field['type'] ) ? $field['type'] : 'string' );
		}
		return array( $values, $formats );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create( array $data ) {
		global $wpdb;
		list( $values, $formats ) = $this->to_columns( $data );
		$ok                       = $wpdb->insert( $this->full_table(), $values, $formats );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Database insert failed.' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @throws \RuntimeException When the update fails.
	 */
	public function update( $id, array $data ) {
		global $wpdb;
		list( $values, $formats ) = $this->to_columns( $data );
		if ( array() === $values ) {
			return;
		}
		$ok = $wpdb->update( $this->full_table(), $values, array( $this->primary_key() => (int) $id ), $formats, array( '%d' ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'Database update failed.' );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $id, $force ) {
		global $wpdb;
		unset( $force );
		$wpdb->delete( $this->full_table(), array( $this->primary_key() => (int) $id ), array( '%d' ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function validate( array $data, $id = null ) {
		unset( $data, $id );
		return true;
	}
}
