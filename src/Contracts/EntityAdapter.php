<?php
/**
 * Entity adapter contract.
 *
 * @package Pressdock
 */

namespace Pressdock\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One syncable entity type (for example "kafela_package").
 *
 * Record arrays always contain the key "id" (int) plus one key per schema field.
 */
interface EntityAdapter {

	/**
	 * Unique machine key, lowercase letters, digits and underscores.
	 *
	 * @return string
	 */
	public function key();

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Group label, normally the owning plugin name.
	 *
	 * @return string
	 */
	public function group();

	/**
	 * Field definitions keyed by field name.
	 *
	 * Each field: type (string,int,float,bool,date,datetime,enum,text,html,relation,media),
	 * label, required (bool), readonly (bool), options (enum values), target (relation entity key).
	 *
	 * @return array
	 */
	public function schema();

	/**
	 * WordPress capability needed for an action.
	 *
	 * @param string $action read|create|update|delete.
	 * @return string
	 */
	public function capability( $action );

	/**
	 * One record or null when it does not exist (or is trashed/deleted).
	 *
	 * @param int $id Object id.
	 * @return array|null
	 */
	public function get( $id );

	/**
	 * Several records keyed by id. Missing ids are omitted.
	 *
	 * @param int[] $ids Object ids.
	 * @return array
	 */
	public function list( array $ids );

	/**
	 * Create a record from already sanitized data.
	 *
	 * @param array $data Field values.
	 * @return int New object id.
	 */
	public function create( array $data );

	/**
	 * Update a record from already sanitized, partial data.
	 *
	 * @param int   $id   Object id.
	 * @param array $data Field values.
	 * @return void
	 */
	public function update( $id, array $data );

	/**
	 * Delete a record.
	 *
	 * @param int  $id    Object id.
	 * @param bool $force True to remove permanently, false to trash where supported.
	 * @return void
	 */
	public function delete( $id, $force );

	/**
	 * Business validation on top of schema validation.
	 *
	 * @param array    $data Field values.
	 * @param int|null $id   Object id for updates, null for creates.
	 * @return \WP_Error|true
	 */
	public function validate( array $data, $id = null );
}
