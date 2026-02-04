<?php

namespace Soli\Passport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Setup page for OIDC key generation
 */
class Setup {

	/**
	 * Notice message to display
	 *
	 * @var array|null
	 */
	private ?array $notice = null;

	/**
	 * Keys directory path
	 *
	 * @var string
	 */
	private string $keys_dir;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->keys_dir = WP_CONTENT_DIR . '/oidc-keys';
	}

	/**
	 * Render the page
	 */
	public function render(): void {
		$this->process_actions();

		$keys_exist    = $this->keys_exist();
		$openssl_available = extension_loaded( 'openssl' );
		?>
		<div class="soli-admin-wrap">
			<h1><?php esc_html_e( 'OIDC Setup', 'soli-passport' ); ?></h1>

			<p class="soli-subtitle">
				<?php esc_html_e( 'Configure the OpenID Connect server keys for secure token signing.', 'soli-passport' ); ?>
			</p>

			<?php $this->render_notice(); ?>

			<div class="soli-card">
				<h3><?php esc_html_e( 'OIDC Signing Keys', 'soli-passport' ); ?></h3>

				<?php if ( ! $openssl_available ) : ?>
					<div class="soli-notice soli-notice-error">
						<p><?php esc_html_e( 'The OpenSSL PHP extension is not available. Please enable it to generate keys.', 'soli-passport' ); ?></p>
					</div>
				<?php elseif ( $keys_exist ) : ?>
					<div class="soli-status-box soli-status-success">
						<span class="soli-status-icon">✓</span>
						<div>
							<strong><?php esc_html_e( 'Keys are configured', 'soli-passport' ); ?></strong>
							<p><?php esc_html_e( 'Your OIDC signing keys are set up and ready to use.', 'soli-passport' ); ?></p>
						</div>
					</div>

					<div class="soli-key-info">
						<dl class="soli-dl">
							<dt><?php esc_html_e( 'Keys Location', 'soli-passport' ); ?></dt>
							<dd><code><?php echo esc_html( $this->keys_dir ); ?></code></dd>

							<dt><?php esc_html_e( 'Public Key', 'soli-passport' ); ?></dt>
							<dd>
								<?php if ( file_exists( $this->keys_dir . '/public.key' ) ) : ?>
									<span class="soli-badge soli-badge-success"><?php esc_html_e( 'Exists', 'soli-passport' ); ?></span>
								<?php else : ?>
									<span class="soli-badge soli-badge-error"><?php esc_html_e( 'Missing', 'soli-passport' ); ?></span>
								<?php endif; ?>
							</dd>

							<dt><?php esc_html_e( 'Private Key', 'soli-passport' ); ?></dt>
							<dd>
								<?php if ( file_exists( $this->keys_dir . '/private.key' ) ) : ?>
									<span class="soli-badge soli-badge-success"><?php esc_html_e( 'Exists', 'soli-passport' ); ?></span>
								<?php else : ?>
									<span class="soli-badge soli-badge-error"><?php esc_html_e( 'Missing', 'soli-passport' ); ?></span>
								<?php endif; ?>
							</dd>

							<dt><?php esc_html_e( 'MU-Plugin', 'soli-passport' ); ?></dt>
							<dd>
								<?php if ( file_exists( WPMU_PLUGIN_DIR . '/oidc-keys-loader.php' ) ) : ?>
									<span class="soli-badge soli-badge-success"><?php esc_html_e( 'Installed', 'soli-passport' ); ?></span>
								<?php else : ?>
									<span class="soli-badge soli-badge-error"><?php esc_html_e( 'Missing', 'soli-passport' ); ?></span>
								<?php endif; ?>
							</dd>
						</dl>
					</div>

					<form method="post" class="soli-form-inline">
						<?php wp_nonce_field( 'soli_passport_regenerate_keys', 'soli_passport_keys_nonce' ); ?>
						<input type="hidden" name="action" value="regenerate_keys" />
						<button type="submit" class="btn btn-warning" onclick="return confirm('<?php echo esc_js( __( 'Are you sure? This will invalidate all existing tokens. Users will need to log in again.', 'soli-passport' ) ); ?>');">
							<?php esc_html_e( 'Regenerate Keys', 'soli-passport' ); ?>
						</button>
						<span class="soli-hint"><?php esc_html_e( 'Only regenerate if keys are compromised.', 'soli-passport' ); ?></span>
					</form>

				<?php else : ?>
					<div class="soli-status-box soli-status-warning">
						<span class="soli-status-icon">!</span>
						<div>
							<strong><?php esc_html_e( 'Keys not configured', 'soli-passport' ); ?></strong>
							<p><?php esc_html_e( 'OIDC signing keys are required for the identity provider to work. Click the button below to generate them automatically.', 'soli-passport' ); ?></p>
						</div>
					</div>

					<form method="post">
						<?php wp_nonce_field( 'soli_passport_generate_keys', 'soli_passport_keys_nonce' ); ?>
						<input type="hidden" name="action" value="generate_keys" />
						<button type="submit" class="btn btn-primary">
							<?php esc_html_e( 'Generate OIDC Keys', 'soli-passport' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>

			<div class="soli-card">
				<h3><?php esc_html_e( 'OIDC Endpoints', 'soli-passport' ); ?></h3>
				<p><?php esc_html_e( 'Use these endpoints when configuring client applications:', 'soli-passport' ); ?></p>

				<dl class="soli-dl">
					<dt><?php esc_html_e( 'Authorization Endpoint', 'soli-passport' ); ?></dt>
					<dd><code><?php echo esc_url( home_url( '/?rest_route=/openid-connect/authorize' ) ); ?></code></dd>

					<dt><?php esc_html_e( 'Token Endpoint', 'soli-passport' ); ?></dt>
					<dd><code><?php echo esc_url( home_url( '/?rest_route=/openid-connect/token' ) ); ?></code></dd>

					<dt><?php esc_html_e( 'Userinfo Endpoint', 'soli-passport' ); ?></dt>
					<dd><code><?php echo esc_url( home_url( '/?rest_route=/openid-connect/userinfo' ) ); ?></code></dd>

					<dt><?php esc_html_e( 'JWKS Endpoint', 'soli-passport' ); ?></dt>
					<dd><code><?php echo esc_url( home_url( '/?rest_route=/openid-connect/jwks' ) ); ?></code></dd>
				</dl>
			</div>
		</div>
		<?php
	}

	/**
	 * Process form actions
	 */
	private function process_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_text_field( $_POST['action'] ) : '';

		if ( $action === 'generate_keys' ) {
			if ( ! isset( $_POST['soli_passport_keys_nonce'] ) || ! wp_verify_nonce( $_POST['soli_passport_keys_nonce'], 'soli_passport_generate_keys' ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Security check failed.', 'soli-passport' ),
				);
				return;
			}
			$this->generate_keys();
		} elseif ( $action === 'regenerate_keys' ) {
			if ( ! isset( $_POST['soli_passport_keys_nonce'] ) || ! wp_verify_nonce( $_POST['soli_passport_keys_nonce'], 'soli_passport_regenerate_keys' ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => __( 'Security check failed.', 'soli-passport' ),
				);
				return;
			}
			$this->generate_keys( true );
		}
	}

	/**
	 * Check if keys exist and are configured
	 *
	 * @return bool
	 */
	private function keys_exist(): bool {
		return defined( 'OIDC_PUBLIC_KEY' ) && defined( 'OIDC_PRIVATE_KEY' )
			&& ! empty( OIDC_PUBLIC_KEY ) && ! empty( OIDC_PRIVATE_KEY );
	}

	/**
	 * Generate OIDC keys
	 *
	 * @param bool $regenerate Whether this is a regeneration
	 */
	private function generate_keys( bool $regenerate = false ): void {
		if ( ! extension_loaded( 'openssl' ) ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'OpenSSL extension is not available.', 'soli-passport' ),
			);
			return;
		}

		// Create keys directory
		if ( ! file_exists( $this->keys_dir ) ) {
			if ( ! wp_mkdir_p( $this->keys_dir ) ) {
				$this->notice = array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: directory path */
						__( 'Could not create keys directory: %s', 'soli-passport' ),
						$this->keys_dir
					),
				);
				return;
			}
		}

		// Protect keys directory with .htaccess
		$htaccess_file = $this->keys_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Deny from all\n" );
		}

		// Generate RSA key pair
		$config = array(
			'private_key_bits' => 2048,
			'private_key_type' => OPENSSL_KEYTYPE_RSA,
		);

		$key = openssl_pkey_new( $config );
		if ( ! $key ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to generate RSA key pair.', 'soli-passport' ),
			);
			return;
		}

		// Extract private key
		openssl_pkey_export( $key, $private_key );

		// Extract public key
		$key_details = openssl_pkey_get_details( $key );
		$public_key  = $key_details['key'];

		// Save keys
		$private_key_file = $this->keys_dir . '/private.key';
		$public_key_file  = $this->keys_dir . '/public.key';

		if ( file_put_contents( $private_key_file, $private_key ) === false ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to save private key.', 'soli-passport' ),
			);
			return;
		}

		if ( file_put_contents( $public_key_file, $public_key ) === false ) {
			$this->notice = array(
				'type'    => 'error',
				'message' => __( 'Failed to save public key.', 'soli-passport' ),
			);
			return;
		}

		// Set secure permissions on private key
		chmod( $private_key_file, 0600 );
		chmod( $public_key_file, 0644 );

		// Create mu-plugin to load keys
		$this->create_mu_plugin();

		$this->notice = array(
			'type'    => 'success',
			'message' => $regenerate
				? __( 'OIDC keys regenerated successfully. All users will need to log in again.', 'soli-passport' )
				: __( 'OIDC keys generated successfully. The identity provider is now ready to use.', 'soli-passport' ),
		);
	}

	/**
	 * Create the mu-plugin to load OIDC keys
	 */
	private function create_mu_plugin(): void {
		// Create mu-plugins directory if it doesn't exist
		if ( ! file_exists( WPMU_PLUGIN_DIR ) ) {
			wp_mkdir_p( WPMU_PLUGIN_DIR );
		}

		$mu_plugin_content = <<<'PHP'
<?php
/**
 * OIDC Keys Loader
 *
 * Loads the OIDC signing keys for the OpenID Connect Server plugin.
 * Generated by Soli Passport Plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$oidc_keys_dir = WP_CONTENT_DIR . '/oidc-keys';

if ( file_exists( $oidc_keys_dir . '/public.key' ) && file_exists( $oidc_keys_dir . '/private.key' ) ) {
	define( 'OIDC_PUBLIC_KEY', file_get_contents( $oidc_keys_dir . '/public.key' ) );
	define( 'OIDC_PRIVATE_KEY', file_get_contents( $oidc_keys_dir . '/private.key' ) );
}

/**
 * Show warning when Soli Passport Plugin is deactivated but keys still exist.
 */
add_action( 'admin_notices', function () {
	// Only run if passport plugin is NOT active
	if ( defined( 'SOLI_PASSPORT__PLUGIN_VERSION' ) ) {
		return;
	}

	// Only show on plugins page
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'plugins' ) {
		return;
	}

	?>
	<div class="notice notice-warning">
		<p><strong>Soli Passport Plugin is deactivated but OIDC keys still exist.</strong></p>
		<p>If you delete the Soli Passport Plugin, the following will be permanently removed:</p>
		<ul style="list-style: disc; margin-left: 20px;">
			<li>All OIDC signing keys (wp-content/oidc-keys/)</li>
			<li>This mu-plugin (oidc-keys-loader.php)</li>
			<li>All registered OIDC clients and role mappings</li>
		</ul>
		<p><strong>This will break all applications using this site as their identity provider.</strong></p>
	</div>
	<?php
} );
PHP;

		file_put_contents( WPMU_PLUGIN_DIR . '/oidc-keys-loader.php', $mu_plugin_content );
	}

	/**
	 * Render notice message
	 */
	private function render_notice(): void {
		if ( ! $this->notice ) {
			return;
		}

		$class = 'soli-notice soli-notice-' . esc_attr( $this->notice['type'] );
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<p><?php echo esc_html( $this->notice['message'] ); ?></p>
		</div>
		<?php
	}
}
