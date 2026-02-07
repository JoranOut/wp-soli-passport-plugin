<?php

namespace Soli\Passport\OIDC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Session Reset Handler
 *
 * Provides functionality to clear the WordPress session during OIDC flows.
 * This is useful when:
 * - User denies authorization to an application
 * - An error occurs during the OIDC flow
 * - User wants to login as a different user
 *
 * Usage:
 * - Redirect to: /?soli_passport_action=reset&redirect_uri=<encoded_uri>
 * - Or show the reset page: /?soli_passport_action=reset (without redirect_uri)
 */
class Session_Reset {

	/**
	 * Query parameter for action
	 */
	const ACTION_PARAM = 'soli_passport_action';

	/**
	 * Reset action value
	 */
	const ACTION_RESET = 'reset';

	/**
	 * Redirect URI parameter
	 */
	const REDIRECT_PARAM = 'redirect_uri';

	/**
	 * Initialize session reset hooks
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'handle_reset_request' ), 5 );

		// Modify Cancel link on OIDC authorize page to log out first
		add_action( 'login_footer', array( $this, 'modify_cancel_link' ) );
	}

	/**
	 * Modify the Cancel link on the OIDC authorize page
	 *
	 * The Cancel link normally redirects directly to the client with an error,
	 * but the user remains logged in on the provider. This modifies the Cancel
	 * link to go through our reset endpoint first, which logs out the user and
	 * then redirects back to the authorize URL so they can try again.
	 */
	public function modify_cancel_link(): void {
		// Only run on OIDC authorize pages
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['rest_route'] ) || strpos( $_GET['rest_route'], '/openid-connect/authorize' ) === false ) {
			return;
		}

		// Only if user is logged in (authorize page is shown)
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Build the current authorize URL (to redirect back to after logout)
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$current_url = home_url( '/wp-login.php' ) . '?' . ( isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : '' );

		// Build the reset URL that will log out and redirect back here
		$reset_url = self::get_reset_url( $current_url );

		?>
		<script>
		(function() {
			// Find the Cancel link and update its href
			var links = document.querySelectorAll('a');
			links.forEach(function(link) {
				if (link.textContent.trim() === 'Cancel') {
					link.href = <?php echo wp_json_encode( $reset_url ); ?>;
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handle incoming reset requests
	 */
	public function handle_reset_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public endpoint, nonce not applicable
		if ( ! isset( $_GET[ self::ACTION_PARAM ] ) || $_GET[ self::ACTION_PARAM ] !== self::ACTION_RESET ) {
			return;
		}

		// Get redirect URI if provided
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$redirect_uri = isset( $_GET[ self::REDIRECT_PARAM ] ) ? esc_url_raw( wp_unslash( $_GET[ self::REDIRECT_PARAM ] ) ) : '';

		// Log out the user if logged in
		if ( is_user_logged_in() ) {
			wp_logout();
		}

		// If redirect URI is provided and valid, redirect there
		if ( ! empty( $redirect_uri ) && $this->is_valid_redirect_uri( $redirect_uri ) ) {
			wp_safe_redirect( $redirect_uri );
			exit;
		}

		// Otherwise show the reset confirmation page
		$this->render_reset_page();
		exit;
	}

	/**
	 * Validate redirect URI
	 *
	 * Only allows redirects to:
	 * - Same site (wp_safe_redirect handles this)
	 * - Registered OIDC client redirect URIs
	 *
	 * @param string $uri The URI to validate
	 * @return bool
	 */
	private function is_valid_redirect_uri( string $uri ): bool {
		// Allow same-site redirects
		$site_url = wp_parse_url( home_url(), PHP_URL_HOST );
		$uri_host = wp_parse_url( $uri, PHP_URL_HOST );

		if ( $site_url === $uri_host ) {
			return true;
		}

		// Allow redirects to registered OIDC client redirect URIs
		$clients = \Soli\Passport\Database\Clients_Table::get_all( true );

		foreach ( $clients as $client ) {
			$client_host = wp_parse_url( $client['redirect_uri'], PHP_URL_HOST );
			if ( $client_host === $uri_host ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the reset confirmation page
	 *
	 * Shows a simple page confirming the session has been cleared
	 * with options to try again or go to the login page.
	 */
	private function render_reset_page(): void {
		$login_url = wp_login_url();
		$home_url  = home_url();

		// Get the referring client URL if available
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$referer     = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$client_url  = '';
		$client_name = '';

		if ( ! empty( $referer ) ) {
			$referer_host = wp_parse_url( $referer, PHP_URL_HOST );
			$clients      = \Soli\Passport\Database\Clients_Table::get_all( true );

			foreach ( $clients as $client ) {
				$client_host = wp_parse_url( $client['redirect_uri'], PHP_URL_HOST );
				if ( $client_host === $referer_host ) {
					$client_url  = $client['redirect_uri'];
					$client_name = $client['name'];
					break;
				}
			}
		}

		$this->render_page_html( $login_url, $home_url, $client_url, $client_name );
	}

	/**
	 * Render the HTML for the reset page
	 *
	 * @param string $login_url   WordPress login URL
	 * @param string $home_url    Site home URL
	 * @param string $client_url  Client redirect URL (if available)
	 * @param string $client_name Client name (if available)
	 */
	private function render_page_html( string $login_url, string $home_url, string $client_url, string $client_name ): void {
		$site_name = get_bloginfo( 'name' );
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php esc_html_e( 'Session Cleared', 'soli-passport' ); ?> - <?php echo esc_html( $site_name ); ?></title>
			<style>
				* {
					box-sizing: border-box;
				}
				body {
					font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
					background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
					min-height: 100vh;
					margin: 0;
					display: flex;
					align-items: center;
					justify-content: center;
					padding: 20px;
				}
				.container {
					background: white;
					border-radius: 12px;
					box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
					padding: 40px;
					max-width: 420px;
					width: 100%;
					text-align: center;
				}
				.icon {
					width: 64px;
					height: 64px;
					margin: 0 auto 20px;
					background: #10b981;
					border-radius: 50%;
					display: flex;
					align-items: center;
					justify-content: center;
				}
				.icon svg {
					width: 32px;
					height: 32px;
					fill: white;
				}
				h1 {
					color: #1f2937;
					font-size: 24px;
					margin: 0 0 12px;
					font-weight: 600;
				}
				p {
					color: #6b7280;
					font-size: 16px;
					line-height: 1.5;
					margin: 0 0 24px;
				}
				.buttons {
					display: flex;
					flex-direction: column;
					gap: 12px;
				}
				.btn {
					display: inline-flex;
					align-items: center;
					justify-content: center;
					padding: 12px 24px;
					border-radius: 8px;
					font-size: 16px;
					font-weight: 500;
					text-decoration: none;
					transition: all 0.2s ease;
					cursor: pointer;
					border: none;
				}
				.btn-primary {
					background: #4f46e5;
					color: white;
				}
				.btn-primary:hover {
					background: #4338ca;
				}
				.btn-secondary {
					background: #f3f4f6;
					color: #374151;
				}
				.btn-secondary:hover {
					background: #e5e7eb;
				}
				.client-name {
					font-weight: 600;
					color: #1f2937;
				}
			</style>
		</head>
		<body>
			<div class="container">
				<div class="icon">
					<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
						<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
					</svg>
				</div>
				<h1><?php esc_html_e( 'Session Cleared', 'soli-passport' ); ?></h1>
				<p>
					<?php esc_html_e( 'Your session has been cleared. You can now try to log in again or return to the application.', 'soli-passport' ); ?>
				</p>
				<div class="buttons">
					<a href="<?php echo esc_url( $login_url ); ?>" class="btn btn-primary">
						<?php esc_html_e( 'Log in again', 'soli-passport' ); ?>
					</a>
					<?php if ( ! empty( $client_url ) ) : ?>
						<a href="<?php echo esc_url( $client_url ); ?>" class="btn btn-secondary">
							<?php
							printf(
								/* translators: %s: Client application name */
								esc_html__( 'Return to %s', 'soli-passport' ),
								'<span class="client-name">' . esc_html( $client_name ) . '</span>'
							);
							?>
						</a>
					<?php endif; ?>
					<a href="<?php echo esc_url( $home_url ); ?>" class="btn btn-secondary">
						<?php esc_html_e( 'Go to homepage', 'soli-passport' ); ?>
					</a>
				</div>
			</div>
		</body>
		</html>
		<?php
	}

	/**
	 * Get the reset URL
	 *
	 * @param string $redirect_uri Optional redirect URI after reset
	 * @return string
	 */
	public static function get_reset_url( string $redirect_uri = '' ): string {
		$base_url = home_url( '/' ) . '?' . self::ACTION_PARAM . '=' . self::ACTION_RESET;

		if ( ! empty( $redirect_uri ) ) {
			// URL-encode the redirect_uri to preserve query params within it
			$base_url .= '&' . self::REDIRECT_PARAM . '=' . rawurlencode( $redirect_uri );
		}

		return $base_url;
	}
}
