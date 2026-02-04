<?php
/**
 * Uninstall script for Soli Passport Plugin
 *
 * This file is executed when the plugin is deleted through the WordPress admin.
 *
 * @package Soli\Passport
 */

// If uninstall.php is not called by WordPress, die.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Define plugin constants if not already defined.
if ( ! defined( 'SOLI_PASSPORT__PLUGIN_DIR_PATH' ) ) {
	define( 'SOLI_PASSPORT__PLUGIN_DIR_PATH', plugin_dir_path( __FILE__ ) );
}

// Load database class.
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/database/class-soli-passport-database.php';

// Drop all plugin tables.
Soli\Passport\Database\Database::drop_tables();

// Clean up options.
delete_option( 'soli_passport_db_version' );

// Clean up OIDC transients for all users.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_soli_passport_client_%' OR option_name LIKE '_transient_timeout_soli_passport_client_%'" );

// Remove OIDC keys directory.
$keys_dir = WP_CONTENT_DIR . '/oidc-keys';
if ( is_dir( $keys_dir ) ) {
	$files = array( 'public.key', 'private.key', '.htaccess' );
	foreach ( $files as $file ) {
		$file_path = $keys_dir . '/' . $file;
		if ( file_exists( $file_path ) ) {
			unlink( $file_path );
		}
	}
	rmdir( $keys_dir );
}

// Remove OIDC keys loader mu-plugin.
$mu_plugin = WPMU_PLUGIN_DIR . '/oidc-keys-loader.php';
if ( file_exists( $mu_plugin ) ) {
	unlink( $mu_plugin );
}
