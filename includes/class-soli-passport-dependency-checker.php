<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency Checker
 *
 * Checks for required and optional plugin dependencies and displays admin notices.
 */
class Dependency_Checker {

	/**
	 * Initialize the dependency checker
	 */
	public function init(): void {
		add_action( 'admin_notices', array( $this, 'display_notices' ) );
	}

	/**
	 * Display admin notices for missing dependencies
	 */
	public function display_notices(): void {
		// Only show notices to administrators
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$is_server = self::is_oidc_server_active();
		$is_client = self::is_oidc_client_active();

		// Neither OIDC plugin is active
		if ( ! $is_server && ! $is_client ) {
			$this->display_error_notice(
				__( 'Soli Passport requires either the OpenID Connect Server plugin (provider mode) or the OpenID Connect Generic plugin (client mode) to be installed and activated.', 'soli-passport' )
			);
			return;
		}

		// Server mode: check for optional Admin plugin
		if ( $is_server && ! self::is_admin_plugin_active() ) {
			$this->display_info_notice(
				__( 'Soli Passport is running in standalone mode. Install the Soli Admin Plugin for enhanced features like relation-based role mappings.', 'soli-passport' )
			);
		}
	}

	/**
	 * Display an error notice
	 *
	 * @param string $message Notice message
	 * @param string $plugin_url Optional plugin URL
	 */
	private function display_error_notice( string $message, string $plugin_url = '' ): void {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Soli Passport', 'soli-passport' ); ?>:</strong>
				<?php echo esc_html( $message ); ?>
				<?php if ( $plugin_url ) : ?>
					<a href="<?php echo esc_url( $plugin_url ); ?>" target="_blank">
						<?php esc_html_e( 'Get the plugin', 'soli-passport' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display an info notice
	 *
	 * @param string $message Notice message
	 */
	private function display_info_notice( string $message ): void {
		// Only show on passport plugin pages
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'soli-passport' ) === false ) {
			return;
		}

		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Standalone Mode', 'soli-passport' ); ?>:</strong>
				<?php echo esc_html( $message ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Check if the OpenID Connect Server plugin is active
	 *
	 * @return bool
	 */
	public static function is_oidc_server_active(): bool {
		// Check for the OIDC Server plugin
		if ( ! function_exists( 'is_plugin_active' ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Check multiple possible plugin file names
		$possible_plugins = array(
			'openid-connect-server/openid-connect-server.php',
			'openid-connect-provider/openid-connect-provider.php',
		);

		foreach ( $possible_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		// Also check if the OIDC filter exists (alternative detection)
		return has_filter( 'oidc_registered_clients' ) !== false || class_exists( 'OpenIDConnectServer\Server' );
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

		// Check for the daggerhart OIDC client plugin
		$possible_plugins = array(
			'daggerhart-openid-connect-generic/openid-connect-generic.php',
			'openid-connect-generic/openid-connect-generic.php',
		);

		foreach ( $possible_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		// Alternative detection via action hook
		return has_action( 'openid-connect-generic-update-user-using-current-claim' ) !== false;
	}

	/**
	 * Check if the Soli Admin plugin is active
	 *
	 * @return bool
	 */
	public static function is_admin_plugin_active(): bool {
		// Check for the admin plugin constant
		return defined( 'SOLI_ADMIN__PLUGIN_VERSION' );
	}

	/**
	 * Get the current operation mode
	 *
	 * @return string 'standalone' or 'enhanced'
	 */
	public static function get_mode(): string {
		return self::is_admin_plugin_active() ? 'enhanced' : 'standalone';
	}

	/**
	 * Check if running in enhanced mode (admin plugin active)
	 *
	 * @return bool
	 */
	public static function is_enhanced_mode(): bool {
		return self::is_admin_plugin_active();
	}

	/**
	 * Check if running in standalone mode (no admin plugin)
	 *
	 * @return bool
	 */
	public static function is_standalone_mode(): bool {
		return ! self::is_admin_plugin_active();
	}
}
