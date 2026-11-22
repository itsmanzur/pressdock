<?php
/**
 * PHPUnit bootstrap for wp-env.
 *
 * Run: npx wp-env start && npx wp-env run tests-cli --env-cwd=wp-content/plugins/pressdock vendor/bin/phpunit
 *
 * @package Pressdock
 */

$pdock_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $pdock_tests_dir ) {
	$pdock_tests_dir = '/wordpress-phpunit';
}

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $pdock_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/pressdock.php';

		// Hejazer Kafela is optional: its tests skip themselves when the plugin is not next to this one.
		$kafela = dirname( __DIR__ ) . '/../hejazer-kafela/hejazer-kafela.php';
		if ( is_readable( $kafela ) ) {
			require $kafela;
		}
		// Hejazer Kafela Pro, same rule.
		$pro = dirname( __DIR__ ) . '/../pothik-pro/pothik-pro.php';
		if ( is_readable( $pro ) ) {
			require $pro;
		}
	}
);

// Create the real tables once, before the suite starts wrapping tests in transactions.
tests_add_filter(
	'setup_theme',
	static function () {
		Pressdock\Installer::migrate();
		if ( class_exists( '\Pothik\InquiryRepository' ) ) {
			\Pothik\InquiryRepository::init_table();
		}
		if ( class_exists( '\PothikPro\Schema' ) ) {
			\PothikPro\Schema::install();
		}
	}
);

require $pdock_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/class-pdock-test-case.php';
require_once __DIR__ . '/Kafela/class-kafela-test-case.php';
