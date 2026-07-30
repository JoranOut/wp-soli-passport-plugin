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

// Remove the assignments synced from the identity provider.
delete_metadata( 'user', 0, 'soli_passport_assignments', '', true );
