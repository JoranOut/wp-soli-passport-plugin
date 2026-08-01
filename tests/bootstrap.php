<?php
/**
 * PHPUnit bootstrap for the Soli Passport plugin.
 *
 * Loads the WordPress test suite that wp-env mounts at /wordpress-phpunit, then the
 * classes under test. The main plugin file is deliberately not loaded: it only wires
 * Role_Sync up when an OIDC client plugin is active, which is an integration concern
 * covered by the e2e suite.
 *
 * @package Soli\Passport
 */

$soli_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $soli_tests_dir ) {
	$soli_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $soli_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test suite at ' . $soli_tests_dir . PHP_EOL;
	echo 'Run these tests through wp-env: npm run test:unit' . PHP_EOL;
	exit( 1 );
}

/**
 * Locate the PHPUnit Polyfills the WordPress test suite requires.
 *
 * Installed into the tests-cli container by .wp-env-setup.sh rather than through a
 * composer.json here, so the plugin itself stays dependency-free. The container's
 * composer home is named after the host user, hence the glob.
 *
 * @return string|null Absolute path to the polyfills directory.
 */
function soli_passport_find_polyfills(): ?string {
	$candidates = array();

	$from_env = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
	if ( $from_env ) {
		$candidates[] = $from_env;
	}

	$composer_home = getenv( 'COMPOSER_HOME' );
	if ( $composer_home ) {
		$candidates[] = $composer_home . '/vendor/yoast/phpunit-polyfills';
	}

	$home = getenv( 'HOME' );
	if ( $home ) {
		$candidates[] = $home . '/.composer/vendor/yoast/phpunit-polyfills';
	}

	$candidates = array_merge( $candidates, glob( '/home/*/.composer/vendor/yoast/phpunit-polyfills' ) ?: array() );

	foreach ( $candidates as $candidate ) {
		if ( file_exists( rtrim( $candidate, '/' ) . '/phpunitpolyfills-autoload.php' ) ) {
			return rtrim( $candidate, '/' );
		}
	}

	return null;
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	$soli_polyfills = soli_passport_find_polyfills();

	if ( ! $soli_polyfills ) {
		echo 'Could not find the PHPUnit Polyfills the WordPress test suite needs.' . PHP_EOL;
		echo 'Install it into the container with:' . PHP_EOL;
		echo '  npm run test:unit:install-deps' . PHP_EOL;
		exit( 1 );
	}

	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $soli_polyfills );
}

require_once $soli_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		if ( ! defined( 'SOLI_PASSPORT__PLUGIN_DIR_PATH' ) ) {
			define( 'SOLI_PASSPORT__PLUGIN_DIR_PATH', dirname( __DIR__ ) . '/' );
		}

		require_once dirname( __DIR__ ) . '/includes/client/class-soli-passport-role-sync.php';
		require_once dirname( __DIR__ ) . '/includes/class-soli-passport-user-privacy.php';
	}
);

require $soli_tests_dir . '/includes/bootstrap.php';
