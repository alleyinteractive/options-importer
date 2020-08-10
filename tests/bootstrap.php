<?php
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	$cwd = explode( 'wp-content', dirname( __FILE__ ) );
	define( 'WP_CONTENT_DIR', $cwd[0] . '/wp-content' );
}

// Load Core's test suite.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// phpcs:ignore This is only for unit testing.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Setup our environment (theme, plugins).
 */
function _manually_load_environment() {
	require dirname( __FILE__ ) . '/../options-importer.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_environment' );

/**
 * Simple getter/setter for random values. Simplifies testing of caching.
 *
 * @param  string $new_value Some new value to set.
 * @return string The most recently set value.
 */
function _cache_test_data( $new_value = null ) {
	static $value;
	if ( isset( $new_value ) ) {
		$value = $new_value;
	}
	return $value;
}

// Include core's bootstrap.
// phpcs:ignore This is only for unit testing.
require $_tests_dir . '/includes/bootstrap.php';
