/**
 * Asserts that the surfaces this plugin renders emit no PHP diagnostics into the page.
 *
 * Scope note: every path here is reachable **without a token exchange**. No OIDC login
 * is performed and no id_token is faked, so nothing in this file depends on the identity
 * provider at admin.soli.nl being reachable or on the local stub having usable signing
 * keys.
 *
 * What that deliberately leaves out, and why:
 *
 * - The authorization-code callback and the role/assignment sync that hangs off it.
 *   Those are the plugin's most interesting code paths, but exercising them means
 *   driving a real token exchange. sso.spec.js already does that against the stub
 *   provider and already asserts the whole flow leaves debug.log free of PHP
 *   diagnostics, which is the stronger check for a server-to-server path no browser
 *   renders. Duplicating it here would add a second way for the same regression to be
 *   reported, and a second thing to unpick when the stub's keys are missing.
 * - The end-session redirect, for the same reason.
 *
 * What is left is still most of the plugin's surface area: Dependency_Checker and
 * User_Privacy initialise on every single request, the updater runs on every admin
 * load, and the login-page filter renders the refusal page from a query parameter alone.
 */

const { test } = require( '@playwright/test' );
const { loginAsAdmin, restUrl, expectNoPhpDiagnostics } = require( './helpers' );

test.describe( 'renders without PHP errors, signed out', () => {
	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( 'on the front page', async ( { page } ) => {
		// User_Privacy and Dependency_Checker are constructed on every request, so the
		// cheapest page still exercises them.
		await page.goto( '/' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the login form reached through bypass-sso', async ( { page } ) => {
		await page.goto( '/wp-login.php?bypass-sso' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the refusal page the plugin renders itself', async ( { page } ) => {
		// login-error is enough on its own: the plugin's settings filter switches the SSO
		// redirect off for error pages, so this renders the refusal message and the
		// "sign in with a different account" link without any login having happened.
		await page.goto( '/wp-login.php?login-error=unauthorized' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the refusal page with bypass-sso, the not-locked-out path', async ( {
		page,
	} ) => {
		await page.goto( '/wp-login.php?login-error=unauthorized&bypass-sso' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the anonymous users REST route User_Privacy filters', async ( { page } ) => {
		// Query form, not /wp-json/ - see restUrl(). Navigating rather than using the
		// request fixture is what makes diagnostics visible: PHP would print them ahead
		// of the JSON body, and the browser renders that response as text.
		await page.goto( restUrl( '/wp/v2/users' ) );
		await expectNoPhpDiagnostics( page );
	} );
} );

test.describe( 'renders without PHP errors, signed in as an administrator', () => {
	test.beforeEach( async ( { context, page } ) => {
		await context.clearCookies();
		await loginAsAdmin( page );
	} );

	test( 'on the admin dashboard', async ( { page } ) => {
		// Dependency_Checker prints its admin notices here, and the GitHub updater is
		// only instantiated on is_admin() requests.
		await page.goto( '/wp-admin/' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the users list table', async ( { page } ) => {
		await page.goto( '/wp-admin/users.php' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the plugins screen', async ( { page } ) => {
		// Exercises the updater's plugin-row and update-check hooks.
		await page.goto( '/wp-admin/plugins.php' );
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the OIDC client settings screen', async ( { page } ) => {
		// Third-party settings page, but this plugin filters the settings that render on
		// it, so a diagnostic scoped to this repo's files can surface here.
		await page.goto(
			'/wp-admin/options-general.php?page=openid-connect-generic-settings'
		);
		await expectNoPhpDiagnostics( page );
	} );

	test( 'on the authenticated users REST route', async ( { page } ) => {
		// The other half of the User_Privacy filter: signed-in requests must be
		// untouched. No nonce is sent, so WordPress treats this as anonymous for
		// authentication purposes - the point here is only that the filter runs on a
		// request that carries a session cookie without emitting anything.
		await page.goto( restUrl( '/wp/v2/users' ) );
		await expectNoPhpDiagnostics( page );
	} );
} );
