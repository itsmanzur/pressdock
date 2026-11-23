#!/usr/bin/env bash
# Installs WordPress core and its PHPUnit test library for CI.
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version>
set -e
DB_NAME=$1; DB_USER=$2; DB_PASS=$3; DB_HOST=${4-localhost}; WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

if [ "$WP_VERSION" = "latest" ]; then
	TAG=trunk
	ZIP=https://wordpress.org/latest.zip
else
	TAG=tags/$WP_VERSION
	ZIP=https://wordpress.org/wordpress-$WP_VERSION.zip
fi

mkdir -p "$WP_CORE_DIR"
curl -sL "$ZIP" -o /tmp/wordpress.zip
unzip -q /tmp/wordpress.zip -d /tmp/wp-extract
cp -r /tmp/wp-extract/wordpress/. "$WP_CORE_DIR"/

mkdir -p "$WP_TESTS_DIR"
svn export --quiet --force "https://develop.svn.wordpress.org/$TAG/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
svn export --quiet --force "https://develop.svn.wordpress.org/$TAG/tests/phpunit/data/" "$WP_TESTS_DIR/data"

cat > "$WP_TESTS_DIR/wp-tests-config.php" <<PHP
<?php
define( 'ABSPATH', '$WP_CORE_DIR/' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
PHP
