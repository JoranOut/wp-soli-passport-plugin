<?php

namespace Soli\Passport;

/*
  Plugin Name: Soli Passport Plugin
  Version: 0.1.0
  Author: Joran Out
  Description: OIDC identity provider functionality for Soli
  Requires PHP: 8.3
  Text Domain: soli-passport
  Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOLI_PASSPORT__PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
define( 'SOLI_PASSPORT__PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'SOLI_PASSPORT__PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );
define( 'SOLI_PASSPORT__PLUGIN_VERSION', '0.1.0' );

// Load database classes
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/database/class-soli-passport-database.php';
Database\Database::load_table_classes();

// Load core classes
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/class-soli-passport-dependency-checker.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/class-soli-passport-admin-bridge.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/class-soli-passport-menu.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/oidc/class-soli-passport-oidc.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/oidc/class-soli-passport-roles.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/oidc/class-soli-passport-session-reset.php';

// Load CLI commands
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/cli/class-soli-passport-test-data-cli.php';
CLI\Test_Data_CLI::register();

/**
 * Plugin activation hook
 */
function soli_passport_activate(): void {
	Database\Database::create_tables();
	// Set flag to flush rewrite rules on next init (after all plugins loaded)
	update_option( 'soli_passport_flush_rewrite_rules', true );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\soli_passport_activate' );

/**
 * Flush rewrite rules after activation (runs on init after all plugins loaded)
 */
add_action( 'init', function () {
	if ( get_option( 'soli_passport_flush_rewrite_rules' ) ) {
		flush_rewrite_rules();
		delete_option( 'soli_passport_flush_rewrite_rules' );
	}
}, 99 );

/**
 * Plugin deactivation hook
 */
function soli_passport_deactivate(): void {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\soli_passport_deactivate' );

add_action( 'init', function () {
	load_plugin_textdomain( 'soli-passport', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	include_once 'updater.php';

	if ( ! defined( 'WP_GITHUB_FORCE_UPDATE' ) ) {
		define( 'WP_GITHUB_FORCE_UPDATE', true );
	}

	if ( is_admin() ) {
		$config = array(
			'slug'               => plugin_basename( __FILE__ ),
			'proper_folder_name' => dirname( plugin_basename( __FILE__ ) ),
			'api_url'            => 'https://api.github.com/repos/Muziekvereniging-Soli/wp-soli-passport-plugin',
			'raw_url'            => 'https://raw.github.com/Muziekvereniging-Soli/wp-soli-passport-plugin/main',
			'github_url'         => 'https://github.com/Muziekvereniging-Soli/wp-soli-passport-plugin',
			'zip_url'            => 'https://github.com/Muziekvereniging-Soli/wp-soli-passport-plugin/archive/refs/heads/main.zip',
			'sslverify'          => true,
			'requires'           => '6.0.0',
			'tested'             => '6.7.0',
			'readme'             => 'readme.md',
		);

		new WP_GitHub_Updater( $config );
	}
} );

// Initialize dependency checker (shows admin notices)
$soli_passport_dependency_checker = new Dependency_Checker();
$soli_passport_dependency_checker->init();

// Server mode: OIDC Provider functionality
if ( Dependency_Checker::is_oidc_server_active() ) {
	// Initialize menu
	$soli_passport_menu = new Menu();
	$soli_passport_menu->init();

	// Initialize OIDC integration
	$soli_passport_oidc = new OIDC\OIDC();
	$soli_passport_oidc->init();

	// Initialize session reset endpoint
	$soli_passport_session_reset = new OIDC\Session_Reset();
	$soli_passport_session_reset->init();
}

// Client mode: Role sync functionality
if ( Dependency_Checker::is_oidc_client_active() ) {
	require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/client/class-soli-passport-role-sync.php';

	$soli_passport_role_sync = new Client\Role_Sync();
	$soli_passport_role_sync->init();
}
