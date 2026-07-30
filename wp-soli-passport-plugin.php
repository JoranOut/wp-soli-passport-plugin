<?php

namespace Soli\Passport;

/*
  Plugin Name: Soli Passport Plugin
  Version: 0.1.0
  Author: Joran Out
  Description: OIDC client adapter for Soli: syncs roles and assignments from the admin.soli.nl identity provider
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

require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/class-soli-passport-dependency-checker.php';

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
			'api_url'            => 'https://api.github.com/repos/JoranOut/wp-soli-passport-plugin',
			'raw_url'            => 'https://raw.github.com/JoranOut/wp-soli-passport-plugin/main',
			'github_url'         => 'https://github.com/JoranOut/wp-soli-passport-plugin',
			'zip_url'            => 'https://github.com/JoranOut/wp-soli-passport-plugin/archive/refs/heads/main.zip',
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

// Sync roles and assignments from the identity provider on OIDC login
if ( Dependency_Checker::is_oidc_client_active() ) {
	require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/client/class-soli-passport-role-sync.php';

	$soli_passport_role_sync = new Client\Role_Sync();
	$soli_passport_role_sync->init();
}
