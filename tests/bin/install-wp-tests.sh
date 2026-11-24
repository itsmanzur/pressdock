#!/usr/bin/env bash
# Installs WordPress core and its PHPUnit test library for CI.
# Usage: install-wp-tests.sh <db-name> <db-user> <db-pass> <db-host> <wp-version|latest>
#
# The test library comes from the official wordpress-develop Git mirror (shallow, sparse checkout),
# so no Subversion client is needed. "latest" resolves to the current stable release, not trunk.
set -euo pipefail
DB_NAME=$1; DB_USER=$2; DB_PASS=$3; DB_HOST=${4-localhost}; WP_VERSION=${5-latest}
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION=$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*"' | head -1 | cut -d'"' -f4)
	if [ -z "$WP_VERSION" ]; then
		echo "Could not resolve the latest WordPress version" >&2
		exit 1
	fi
fi
# Git tags always have three parts (6.0 → 6.0.0); release zips use the short form.
GIT_TAG=$WP_VERSION
if [[ "$GIT_TAG" =~ ^[0-9]+\.[0-9]+$ ]]; then
	GIT_TAG="$GIT_TAG.0"
fi
echo "WordPress $WP_VERSION (tests from tag $GIT_TAG)"

# Core
mkdir -p "$WP_CORE_DIR" /tmp/wp-extract
curl -fsSL "https://wordpress.org/wordpress-$WP_VERSION.zip" -o /tmp/wordpress.zip
unzip -q -o /tmp/wordpress.zip -d /tmp/wp-extract
cp -r /tmp/wp-extract/wordpress/. "$WP_CORE_DIR"/

# Test library (tests/phpunit/includes + data) at the matching tag
rm -rf /tmp/wordpress-develop
git -c advice.detachedHead=false clone --quiet --depth 1 --filter=blob:none --sparse --branch "$GIT_TAG" \
	https://github.com/WordPress/wordpress-develop.git /tmp/wordpress-develop
git -C /tmp/wordpress-develop sparse-checkout set tests/phpunit/includes tests/phpunit/data
mkdir -p "$WP_TESTS_DIR"
rm -rf "$WP_TESTS_DIR/includes" "$WP_TESTS_DIR/data"
cp -r /tmp/wordpress-develop/tests/phpunit/includes "$WP_TESTS_DIR/includes"
cp -r /tmp/wordpress-develop/tests/phpunit/data "$WP_TESTS_DIR/data"

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
echo "Test library ready in $WP_TESTS_DIR"
