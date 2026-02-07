/**
 * OIDC Login Flow E2E Test
 *
 * Tests the complete OIDC authentication flow:
 * - Provider (localhost:8889): OpenID Connect Server with testuser
 * - Client (localhost:8888): OpenID Connect Generic Client
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );
const { exec } = require( 'child_process' );
const { promisify } = require( 'util' );
const execAsync = promisify( exec );

const OIDC_PROVIDER = 'http://localhost:8889';
const OIDC_CLIENT = 'http://localhost:8888';

/**
 * Helper to run WP-CLI commands on the tests environment (provider on :8889)
 */
async function wpCliProvider( command ) {
	const { stdout } = await execAsync( `npx wp-env run tests-cli wp ${ command }` );
	return stdout.trim();
}

/**
 * Helper to run WP-CLI commands on the dev environment (client on :8888)
 */
async function wpCliClient( command ) {
	const { stdout } = await execAsync( `npx wp-env run cli wp ${ command }` );
	return stdout.trim();
}

/**
 * Helper to get the resolved role for a user via WP-CLI
 */
async function getResolvedRole( username, clientId ) {
	// Get user ID first, then resolve role
	const userId = await wpCliProvider( `user get ${ username } --field=ID` );
	const result = await wpCliProvider( `eval "echo Soli\\\\Passport\\\\OIDC\\\\OIDC::get_user_role(${ userId }, '${ clientId }');"` );
	return result;
}

/**
 * Helper to complete OIDC login flow
 * Works with both button mode and auto-login SSO mode
 */
async function completeOidcFlow( page, username, password ) {
	// Go to OIDC client login page
	await page.goto( `${ OIDC_CLIENT }/wp-login.php` );

	// With auto-login SSO, we should be auto-redirected to provider
	// With button mode, we need to click the OpenID Connect button first
	const oidcButton = page.locator( 'a' ).filter( { hasText: /OpenID Connect/i } );
	if ( await oidcButton.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await oidcButton.click();
	}

	// Should be redirected to provider
	await expect( page ).toHaveURL( /localhost:8889/, { timeout: 5000 } );

	// Login on the provider (if login form is shown)
	const loginForm = page.locator( '#user_login' );
	if ( await loginForm.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await page.locator( '#user_login' ).fill( username );
		await page.locator( '#user_pass' ).fill( password );
		await page.locator( '#wp-submit' ).click();
	}

	// Approve authorization if shown
	const authorizeButton = page.locator( 'button, input[type="submit"]' ).filter( { hasText: /authorize|allow|approve/i } );
	if ( await authorizeButton.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
		await authorizeButton.click();
	}

	// Should be redirected back to client
	await expect( page ).toHaveURL( /localhost:8888/, { timeout: 10000 } );
}

test.describe( 'OIDC Login Flow', () => {
	test.beforeEach( async ( { context } ) => {
		// Clear all cookies before each test to ensure clean state
		await context.clearCookies();
		// Clear the OIDC consent timestamp so the authorize page shows again
		await wpCliProvider( 'user meta delete testuser oidc_consent_timestamp_soli-dev-client' ).catch( () => {} );
	} );

	test( 'testuser exists on OIDC provider', async ( { page } ) => {
		// Login to provider as admin
		await page.goto( `${ OIDC_PROVIDER }/wp-login.php` );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();

		// Go to users page
		await page.goto( `${ OIDC_PROVIDER }/wp-admin/users.php` );

		// Verify testuser exists
		const testUserRow = page.locator( 'tr' ).filter( { hasText: 'testuser' } );
		await expect( testUserRow ).toBeVisible();
		await expect( testUserRow ).toContainText( 'testuser@soli.nl' );
		await expect( testUserRow ).toContainText( 'Subscriber' );
	} );

	test( 'can login via OIDC flow', async ( { page } ) => {
		await completeOidcFlow( page, 'testuser', 'testpass' );

		// Verify we're logged in
		await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );
} );

test.describe( 'OIDC Cancel and Retry', () => {
	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
		// Destroy testuser's sessions on the provider to ensure they need to login
		await wpCliProvider( 'user session destroy testuser --all' ).catch( () => {} );
		// Delete testuser on client if exists (from previous test runs)
		await wpCliClient( 'user delete testuser@soli.nl --yes' ).catch( () => {} );
		// Clear the OIDC consent timestamp so the authorize page shows again
		await wpCliProvider( 'user meta delete testuser oidc_consent_timestamp_soli-dev-client' ).catch( () => {} );
	} );

	test( 'cancel on authorize page logs out and allows retry', async ( { page } ) => {
		// Step 1: Go to client login - should redirect to provider
		await page.goto( `${ OIDC_CLIENT }/wp-login.php` );

		// With auto-login (SSO), we should be redirected to provider automatically
		await expect( page ).toHaveURL( /localhost:8889/, { timeout: 5000 } );

		// Store the authorize URL params for later comparison
		const authorizeUrl = page.url();

		// Step 2: Login on the provider (if login form is shown)
		const loginForm = page.locator( '#user_login' );
		if ( await loginForm.isVisible( { timeout: 2000 } ).catch( () => false ) ) {
			await page.locator( '#user_login' ).fill( 'testuser' );
			await page.locator( '#user_pass' ).fill( 'testpass' );
			await page.locator( '#wp-submit' ).click();
		}

		// Wait for navigation
		await page.waitForLoadState( 'networkidle' );

		// Step 3: Should see authorize page - find and click Cancel (it's a link)
		// If already authorized, the test can't proceed - skip in that case
		const cancelLink = page.locator( 'a' ).filter( { hasText: 'Cancel' } );
		await expect( cancelLink ).toBeVisible( { timeout: 5000 } );

		// Verify the Cancel link was modified by our JavaScript to go through reset endpoint
		const cancelHref = await cancelLink.getAttribute( 'href' );
		expect( cancelHref ).toContain( 'soli_passport_action=reset' );

		await cancelLink.click();

		// Step 4: Should be redirected back to login form on provider (logged out)
		// The reset endpoint logs out and redirects back to the authorize URL
		await expect( page ).toHaveURL( /localhost:8889/, { timeout: 5000 } );
		await expect( page ).toHaveURL( /openid-connect/ );

		// Should see login form again (because we're logged out)
		await expect( page.locator( '#user_login' ) ).toBeVisible( { timeout: 5000 } );

		// Step 5: Login again
		await page.locator( '#user_login' ).fill( 'testuser' );
		await page.locator( '#user_pass' ).fill( 'testpass' );
		await page.locator( '#wp-submit' ).click();

		// Step 6: Should see authorize page again
		const authorizeButton = page.locator( 'button, input[type="submit"]' ).filter( { hasText: 'Authorize' } );
		await expect( authorizeButton ).toBeVisible( { timeout: 5000 } );

		// Step 7: This time authorize
		await authorizeButton.click();

		// Should be redirected back to client and logged in
		await expect( page ).toHaveURL( /localhost:8888/, { timeout: 10000 } );

		// Verify we're logged in
		await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );
} );

test.describe( 'OIDC Role Resolution', () => {
	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
		// Clear the OIDC consent timestamp so the authorize page shows again
		await wpCliProvider( 'user meta delete testuser oidc_consent_timestamp_soli-dev-client' ).catch( () => {} );
	} );

	test( 'user gets role from WP role mapping', async ( { page } ) => {
		// Default test data maps subscriber → subscriber
		// First verify the role resolution via WP-CLI
		const role = await getResolvedRole( 'testuser', 'soli-dev-client' );
		expect( role ).toBe( 'subscriber' );

		// Then verify the OIDC flow completes successfully
		await completeOidcFlow( page, 'testuser', 'testpass' );
		await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
	} );

	test( 'user override takes precedence over role mapping', async ( { page } ) => {
		// Get testuser's WP user ID
		const userId = await wpCliProvider( 'user get testuser --field=ID' );

		// Add a user override for testuser → "vip-member"
		await wpCliProvider( `eval '
			global $wpdb;
			$table = $wpdb->prefix . "soli_passport_user_roles";
			$wpdb->insert($table, array(
				"wp_user_id" => ${ userId },
				"client_id" => "soli-dev-client",
				"role" => "vip-member"
			));
		'` );

		try {
			// Verify the role resolution returns the override
			const role = await getResolvedRole( 'testuser', 'soli-dev-client' );
			expect( role ).toBe( 'vip-member' );

			// Verify the OIDC flow still works
			await completeOidcFlow( page, 'testuser', 'testpass' );
			await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		} finally {
			// Clean up: remove the override
			await wpCliProvider( `eval '
				global $wpdb;
				$table = $wpdb->prefix . "soli_passport_user_roles";
				$wpdb->delete($table, array(
					"wp_user_id" => ${ userId },
					"client_id" => "soli-dev-client"
				));
			'` );
		}
	} );

	test( 'custom role mapping is applied correctly', async ( { page } ) => {
		// Update the subscriber role mapping to return "custom-subscriber-role"
		await wpCliProvider( `eval '
			global $wpdb;
			$table = $wpdb->prefix . "soli_passport_role_mappings";
			$wpdb->update($table,
				array("role" => "custom-subscriber-role"),
				array("client_id" => "soli-dev-client", "wp_role" => "subscriber")
			);
		'` );

		try {
			// Verify the role resolution returns the custom role
			const role = await getResolvedRole( 'testuser', 'soli-dev-client' );
			expect( role ).toBe( 'custom-subscriber-role' );

			// Verify the OIDC flow still works
			await completeOidcFlow( page, 'testuser', 'testpass' );
			await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		} finally {
			// Clean up: restore the original mapping
			await wpCliProvider( `eval '
				global $wpdb;
				$table = $wpdb->prefix . "soli_passport_role_mappings";
				$wpdb->update($table,
					array("role" => "subscriber"),
					array("client_id" => "soli-dev-client", "wp_role" => "subscriber")
				);
			'` );
		}
	} );

	test( 'editor role override sets WordPress user role to editor on client', async ( { page } ) => {
		// Get testuser's WP user ID on the provider
		const providerUserId = await wpCliProvider( 'user get testuser --field=ID' );

		// Add an editor role override for testuser on the provider
		await wpCliProvider( `eval '
			global $wpdb;
			$table = $wpdb->prefix . "soli_passport_user_roles";
			$wpdb->insert($table, array(
				"wp_user_id" => ${ providerUserId },
				"client_id" => "soli-dev-client",
				"role" => "editor"
			));
		'` );

		try {
			// Verify the role resolution returns editor
			const role = await getResolvedRole( 'testuser', 'soli-dev-client' );
			expect( role ).toBe( 'editor' );

			// Complete the OIDC login flow
			await completeOidcFlow( page, 'testuser', 'testpass' );

			// Verify we're logged in on the client
			await page.goto( `${ OIDC_CLIENT }/wp-admin/` );
			await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

			// Check the WordPress user role on the client (8888)
			// The OIDC plugin creates users with email, so look up by email
			const clientUserRole = await wpCliClient( 'user get testuser@soli.nl --field=roles' );
			expect( clientUserRole ).toContain( 'editor' );
		} finally {
			// Clean up: remove the override from provider
			await wpCliProvider( `eval '
				global $wpdb;
				$table = $wpdb->prefix . "soli_passport_user_roles";
				$wpdb->delete($table, array(
					"wp_user_id" => ${ providerUserId },
					"client_id" => "soli-dev-client"
				));
			'` );

			// Clean up: delete the user from client (if created)
			await wpCliClient( 'user delete testuser@soli.nl --yes' ).catch( () => {} );
		}
	} );
} );
