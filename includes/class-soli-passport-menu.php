<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load admin page classes
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/admin/pages/class-soli-passport-clients.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/admin/pages/class-soli-passport-role-mappings.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/admin/pages/class-soli-passport-user-roles.php';
require_once SOLI_PASSPORT__PLUGIN_DIR_PATH . 'includes/admin/pages/class-soli-passport-setup.php';

class Menu {

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		// Main menu - Soli Passport
		add_menu_page(
			__( 'Soli Passport', 'soli-passport' ),
			__( 'Soli Passport', 'soli-passport' ),
			'manage_options',
			'soli-passport',
			array( $this, 'render_clients' ),
			'dashicons-admin-network',
			31
		);

		// OIDC Clients (default page)
		add_submenu_page(
			'soli-passport',
			__( 'OIDC Clients', 'soli-passport' ),
			__( 'OIDC Clients', 'soli-passport' ),
			'manage_options',
			'soli-passport',
			array( $this, 'render_clients' )
		);

		// Role Mappings
		add_submenu_page(
			'soli-passport',
			__( 'Role Mappings', 'soli-passport' ),
			__( 'Role Mappings', 'soli-passport' ),
			'manage_options',
			'soli-passport-role-mappings',
			array( $this, 'render_role_mappings' )
		);

		// User Role Overrides
		add_submenu_page(
			'soli-passport',
			__( 'User Overrides', 'soli-passport' ),
			__( 'User Overrides', 'soli-passport' ),
			'manage_options',
			'soli-passport-user-roles',
			array( $this, 'render_user_roles' )
		);

		// Setup
		add_submenu_page(
			'soli-passport',
			__( 'Setup', 'soli-passport' ),
			__( 'Setup', 'soli-passport' ),
			'manage_options',
			'soli-passport-setup',
			array( $this, 'render_setup' )
		);
	}

	public function render_clients(): void {
		$page = new Clients();
		$page->render();
	}

	public function render_role_mappings(): void {
		$page = new Role_Mappings();
		$page->render();
	}

	public function render_user_roles(): void {
		$page = new User_Roles();
		$page->render();
	}

	public function render_setup(): void {
		$page = new Setup();
		$page->render();
	}

	public function enqueue_assets( string $hook ): void {
		$is_passport_page = str_starts_with( $hook, 'toplevel_page_soli-passport' )
			|| str_starts_with( $hook, 'soli-passport_page_' );

		if ( ! $is_passport_page ) {
			return;
		}

		wp_enqueue_style(
			'soli-passport-css',
			SOLI_PASSPORT__PLUGIN_DIR_URL . 'assets/css/admin.css',
			array(),
			SOLI_PASSPORT__PLUGIN_VERSION
		);

		wp_enqueue_script(
			'soli-passport-tables-js',
			SOLI_PASSPORT__PLUGIN_DIR_URL . 'assets/js/admin-tables.js',
			array( 'wp-i18n' ),
			SOLI_PASSPORT__PLUGIN_VERSION,
			true
		);

		wp_set_script_translations( 'soli-passport-tables-js', 'soli-passport', SOLI_PASSPORT__PLUGIN_DIR_PATH . 'languages' );
	}
}
