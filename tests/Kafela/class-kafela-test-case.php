<?php
/**
 * Shared helpers for the Hejazer Kafela adapter tests.
 *
 * @package Pressdock
 */

use Pressdock\Adapters\Kafela\PackageIds;
use Pressdock\Adapters\Kafela\Support;

/**
 * Base case: Kafela must be loaded, its data is reset, e-mail attempts are recorded.
 */
abstract class Pdock_Kafela_Test_Case extends Pdock_Test_Case {

	/**
	 * Mails that would have been sent (recorded after the sync guard has had its say).
	 *
	 * @var array
	 */
	protected $mail_attempts = array();

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up() {
		if ( ! defined( 'POTHIK_VERSION' ) ) {
			$this->markTestSkipped( 'Hejazer Kafela is not active.' );
		}
		parent::set_up();

		delete_option( 'pothik_packages' );
		delete_option( 'pothik_receipt' );
		delete_option( 'pothik_settings' );
		delete_option( PackageIds::OPTION );
		Support::reset_package_memo();
		Pressdock\Registry::reset();

		$this->mail_attempts = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit ) {
				// Runs after the sync guard (priority 1): only mail that would really be sent is recorded.
				if ( null === $short_circuit ) {
					$this->mail_attempts[] = true;
				}
				return $short_circuit;
			},
			2
		);
	}

	/**
	 * Build one op.
	 *
	 * @param string      $op     create|update|delete.
	 * @param string      $entity Entity key.
	 * @param string|null $uuid   Record uuid (creates) or null.
	 * @param array|null  $data   Data.
	 * @param array       $extra  Extra op keys (remote_id, base_revision).
	 * @return array
	 */
	protected function op( $op, $entity, $uuid, $data = null, array $extra = array() ) {
		$out = array(
			'op_id'  => $this->uuid(),
			'op'     => $op,
			'entity' => $entity,
		);
		if ( null !== $uuid ) {
			$out['uuid'] = $uuid;
		}
		if ( null !== $data ) {
			$out['data'] = $data;
		}
		return array_merge( $out, $extra );
	}

	/**
	 * Push one op and return its result.
	 *
	 * @param array $op Op.
	 * @return array
	 */
	protected function push_one( array $op ) {
		$results = $this->push( array( $op ) );
		return $results[0];
	}

	/**
	 * Pull one entity from the start and return its items keyed by remote id.
	 *
	 * @param string $entity Entity key.
	 * @return array
	 */
	protected function pull_entity( $entity ) {
		$out = array();
		$data = $this->pull( 0, array( 'entity' => $entity, 'limit' => 500 ) );
		foreach ( $data['items'] as $item ) {
			$out[ $item['remote_id'] ] = $item;
		}
		return $out;
	}

	/**
	 * A valid package payload.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	protected function package_data( array $over = array() ) {
		return array_merge(
			array(
				'title'      => 'Ramadan Umrah 15 days',
				'status'     => 'published',
				'days'       => 15,
				'departure'  => '2027-03',
				'is_ramadan' => true,
				'prices'     => array(
					'quad'   => 180000,
					'double' => 210000,
				),
				'inclusions' => array( 'Visa', 'Hotel' ),
			),
			$over
		);
	}

	/**
	 * A valid inquiry payload.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	protected function inquiry_data( array $over = array() ) {
		return array_merge(
			array(
				'name'   => 'Rahim Uddin',
				'phone'  => '01711000111',
				'city'   => 'Dhaka',
				'share'  => 'double',
				'adults' => 2,
				'trip'   => 'group',
				'note'   => 'Need visa help',
			),
			$over
		);
	}

	/**
	 * Assert that mail sent while syncing is blocked and nothing reached the mailer.
	 *
	 * @return void
	 */
	protected function assertNoMailAttempted() {
		$this->assertSame( array(), $this->mail_attempts, 'wp_mail() must not run while syncing.' );
	}
}
