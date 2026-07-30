<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency Checker
 *
 * This plugin is an adapter on top of an OIDC client plugin. Without one,
 * there is nothing to hook into, so we surface that as an admin notice.
 */
class Dependency_Checker {

	/**
	 * Initialize the dependency checker
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	/**
	 * Display an admin notice when no OIDC client plugin is active
	 */
	public function display_notices(): void {
		// Only show notices to administrators
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( self::is_oidc_client_active() ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Soli Passport', 'soli-passport' ); ?>:</strong>
				<?php esc_html_e( 'requires the OpenID Connect Generic plugin to be installed and activated. Without it, roles and assignments are not synced from the identity provider.', 'soli-passport' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Check if the OpenID Connect Generic (client) plugin is active
	 *
	 * @return bool
	 */
	public static function is_oidc_client_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$possible_plugins = array(
			'daggerhart-openid-connect-generic/openid-connect-generic.php',
			'openid-connect-generic/openid-connect-generic.php',
		);

		foreach ( $possible_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		return class_exists( 'OpenID_Connect_Generic' );
	}
}
