<?php
/**
 * Hejazer Kafela adapters. They register only while Hejazer Kafela is active.
 *
 * Detection: the POTHIK_VERSION constant and the Pothik\PackageStore class. The check runs inside
 * the filter callback (lazily, after every plugin has loaded). No file of Hejazer Kafela is modified.
 *
 * Filters (documented in adapters/hejazer-kafela/README.md):
 *  - pdock_kafela_adapter_args( array $args, string $entity )  label, group, enabled, can
 *  - pdock_kafela_fire_hooks( bool $fire, string $entity, string $event )
 *
 * @package Pressdock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pdock_register_entities',
	static function ( $adapters ) {
		if ( ! defined( 'POTHIK_VERSION' ) || ! class_exists( '\Pothik\PackageStore' ) || ! class_exists( '\Pothik\InquiryRepository' ) ) {
			return $adapters;
		}

		$dir = __DIR__;
		require_once $dir . '/Support.php';
		require_once $dir . '/StringIdMap.php';
		require_once $dir . '/PackageIds.php';
		require_once $dir . '/PackageAdapter.php';
		require_once $dir . '/InquiryAdapter.php';
		require_once $dir . '/SingletonAdapter.php';
		require_once $dir . '/ReceiptAdapter.php';
		require_once $dir . '/SettingsAdapter.php';

		Pressdock\Adapters\Kafela\Support::guard_mail();

		// Packages first, inquiries after: a batch is also ordered by relation dependency.
		$bundle = array(
			new Pressdock\Adapters\Kafela\PackageAdapter(),
			new Pressdock\Adapters\Kafela\InquiryAdapter(),
			new Pressdock\Adapters\Kafela\ReceiptAdapter(),
			new Pressdock\Adapters\Kafela\SettingsAdapter(),
		);
		foreach ( $bundle as $adapter ) {
			if ( $adapter->enabled() ) {
				$adapters[] = $adapter;
			}
		}
		return $adapters;
	}
);

/*
 * Hejazer Kafela Pro (separate plugin). Registers only while Pro is active as well:
 * the POTHIK_PRO_VERSION constant and the PothikPro\Payments / PothikPro\Repo\Ledger classes.
 * Priority 20 so the free-plugin adapters are already in the list.
 */
add_filter(
	'pdock_register_entities',
	static function ( $adapters ) {
		if (
			! defined( 'POTHIK_VERSION' ) || ! defined( 'POTHIK_PRO_VERSION' )
			|| ! class_exists( '\PothikPro\Payments' ) || ! class_exists( '\PothikPro\Repo\Ledger' ) || ! class_exists( '\PothikPro\Access' )
		) {
			return $adapters;
		}

		$dir = __DIR__;
		require_once $dir . '/Support.php';
		require_once $dir . '/StringIdMap.php';
		require_once $dir . '/LedgerIds.php';
		require_once $dir . '/TableScanner.php';
		require_once $dir . '/pro/ReadOnlyTableAdapter.php';
		require_once $dir . '/pro/DepartureAdapter.php';
		require_once $dir . '/pro/PilgrimAdapter.php';
		require_once $dir . '/pro/LedgerAdapter.php';
		require_once $dir . '/pro/PaymentAdapter.php';

		Pressdock\Adapters\Kafela\Support::guard_mail();

		// Parents first: departure, pilgrim, ledger, then payments.
		$pro = array(
			new Pressdock\Adapters\Kafela\Pro\DepartureAdapter(),
			new Pressdock\Adapters\Kafela\Pro\PilgrimAdapter(),
			new Pressdock\Adapters\Kafela\Pro\LedgerAdapter(),
			new Pressdock\Adapters\Kafela\Pro\PaymentAdapter(),
		);
		foreach ( $pro as $adapter ) {
			if ( $adapter->enabled() ) {
				$adapters[] = $adapter;
			}
		}
		return $adapters;
	},
	20
);
