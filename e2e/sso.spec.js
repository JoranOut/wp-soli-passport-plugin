/**
 * SSO login flow against the stub OIDC provider.
 *
 * The stub lives at /oidc-stub/stub-provider/index.php on the same site and serves the
 * account picker; its accounts come from tests/fixtures/oidc-claims.json. See
 * tests/stub-provider/index.php for why it is same-origin.
 */
const { test, expect } = require( '@playwright/test' );
const { exec } = require( 'child_process' );
const { promisify } = require( 'util' );

const execAsync = promisify( exec );

const STUB_PATH = '/oidc-stub/stub-provider/index.php';

/**
 * Run a WP-CLI command against the client site.
 *
 * @param {string} command WP-CLI command without the leading `wp`.
 * @return {Promise<string>} Trimmed stdout.
 */
async function wpCli( command ) {
	const { stdout } = await execAsync( `npx wp-env run cli wp ${ command }` );

	// wp-env prints its own progress lines around the command output.
	return stdout
		.split( '\n' )
		.filter( ( line ) => ! line.startsWith( 'ℹ' ) && ! line.startsWith( '✔' ) )
		.join( '\n' )
		.trim();
}

/**
 * Run a shell command inside the client container.
 *
 * @param {string} script Shell snippet to run with `bash -c`.
 * @return {Promise<string>} Trimmed stdout.
 */
async function wpBash( script ) {
	const { stdout } = await execAsync(
		`npx wp-env run cli -- bash -c ${ JSON.stringify( script ) }`
	);

	return stdout
		.split( '\n' )
		.filter( ( line ) => ! line.startsWith( 'ℹ' ) && ! line.startsWith( '✔' ) )
		.join( '\n' )
		.trim();
}

const DEBUG_LOG = '/var/www/html/wp-content/debug.log';

/**
 * PHP diagnostics WordPress writes to debug.log when WP_DEBUG is on.
 *
 * The plugin also logs its own `[soli-passport]` lines there on purpose, so this matches
 * only the `PHP <level>:` prefix the error handler adds.
 */
const PHP_DIAGNOSTIC = /PHP (?:Warning|Notice|Fatal error|Parse error|Deprecated|Recoverable fatal error)/;

/**
 * Delete a user on the client so each test starts from a clean slate.
 *
 * @param {string} email Email address of the user to remove.
 */
async function deleteUser( email ) {
	await wpCli( `user delete ${ email } --yes --network` ).catch( () => {} );
}

/**
 * Sign in through the stub provider as one of the fixture accounts.
 *
 * @param {import('@playwright/test').Page} page      Playwright page.
 * @param {string}                          fixtureKey Fixture account key.
 */
async function signInAs( page, fixtureKey ) {
	await page.goto( '/wp-login.php' );

	// login_type is 'auto', so the client redirects straight to the provider.
	await expect( page ).toHaveURL( new RegExp( STUB_PATH.replace( /\./g, '\\.' ) ) );

	await page.locator( `#stub-user-${ fixtureKey }` ).click();

	// The redirect chain back through the client's callback must finish before the
	// test looks at cookies or navigates away.
	await page.waitForURL( ( url ) => ! url.pathname.includes( '/oidc-stub/' ) );
}

test.describe( 'SSO login', () => {
	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( 'a granted role signs in and receives that role locally', async ( { page } ) => {
		await deleteUser( 'eva.editor@example.com' );

		await signInAs( page, 'editor' );

		// Back on the client and authenticated.
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

		const roles = await wpCli( 'user get eva.editor@example.com --field=roles' );
		expect( roles ).toBe( 'editor' );
	} );

	test( 'assignments from the provider are stored as user meta', async ( { page } ) => {
		await deleteUser( 'eva.editor@example.com' );

		await signInAs( page, 'editor' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

		const meta = await wpCli(
			'user meta get eva.editor@example.com soli_passport_assignments --format=json'
		);
		const assignments = JSON.parse( meta );

		expect( assignments ).toHaveLength( 2 );
		expect( assignments[ 0 ] ).toMatchObject( {
			onderdeel_id: 3,
			instrument_soort: 'Trompet',
			instrument_familie: 'Koper',
		} );
	} );

	test( 'a user without access is refused and told why', async ( { page } ) => {
		await signInAs( page, 'no-access' );

		// Refused before a session is created, back on the client login page.
		await expect( page ).toHaveURL( /login-error=unauthorized/ );
		await expect( page.locator( 'body' ) ).toContainText(
			'Your Soli account does not have access to this site'
		);

		// No local user is created for someone who may not sign in.
		const exists = await wpCli( 'user list --field=user_email' );
		expect( exists ).not.toContain( 'nora.noaccess@example.com' );
	} );

	test( 'a role the provider did not map is refused', async ( { page } ) => {
		await signInAs( page, 'unconfigured-client' );

		await expect( page ).toHaveURL( /login-error=unauthorized/ );
	} );

	test( 'the error page offers a way out instead of a redirect loop', async ( { page } ) => {
		await signInAs( page, 'no-access' );

		// The regular form and the SSO button are hidden; only the way out is shown.
		await expect( page.locator( '#loginform' ) ).toBeHidden();

		const retry = page.locator( 'a' ).filter( { hasText: 'Sign in with a different account' } );
		await expect( retry ).toBeVisible();

		// Logging out at the provider first is what makes a retry useful.
		const href = await retry.getAttribute( 'href' );
		expect( href ).toContain( '/oauth/logout' );
		expect( href ).toContain( 'redirect_uri=' );
	} );

	test( 'bypass-sso shows the regular WordPress login form', async ( { page } ) => {
		await page.goto( '/wp-login.php?bypass-sso' );

		await expect( page ).toHaveURL( /wp-login\.php/ );
		await expect( page.locator( '#user_login' ) ).toBeVisible();
		await expect( page.locator( '#user_pass' ) ).toBeVisible();
	} );

	test( 'bypass-sso still works on an error page, so admins are never locked out', async ( {
		page,
	} ) => {
		await page.goto( '/wp-login.php?login-error=unauthorized&bypass-sso' );

		await expect( page.locator( '#loginform' ) ).toBeVisible();
		await expect( page.locator( '#user_login' ) ).toBeVisible();
	} );
} );

test.describe( 'PHP diagnostics', () => {
	test( 'the SSO flow runs without PHP warnings, notices or deprecations', async ( {
		page,
		request,
	} ) => {
		await deleteUser( 'eva.editor@example.com' );

		// Start from an empty log so only this test's requests are judged.
		await wpBash( `rm -f ${ DEBUG_LOG }` );

		// Walk the request paths this plugin actually hooks into: a front-end render,
		// the login redirect, the OIDC callback that syncs roles and assignments, an
		// authenticated admin load, and the anonymous users endpoint User_Privacy filters.
		await page.goto( '/' );
		await signInAs( page, 'editor' );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();
		await request.get( '/wp-json/wp/v2/users' );

		// A refused login too - that path logs on purpose, so it must stay clean of the
		// accidental kind.
		await page.context().clearCookies();
		await signInAs( page, 'no-access' );

		const log = await wpBash( `cat ${ DEBUG_LOG } 2>/dev/null || true` );
		const diagnostics = log
			.split( '\n' )
			.filter( ( line ) => PHP_DIAGNOSTIC.test( line ) );

		expect(
			diagnostics,
			`PHP diagnostics were written to debug.log:\n${ diagnostics.join( '\n' ) }`
		).toEqual( [] );
	} );
} );

test.describe( 'stub provider', () => {
	test( 'serves a discovery document and a signing key', async ( { request } ) => {
		const discovery = await request.get( `${ STUB_PATH }?action=openid-configuration` );
		expect( discovery.ok() ).toBeTruthy();

		const config = await discovery.json();
		expect( config.issuer ).toBe( 'https://stub-provider.test' );
		expect( config.scopes_supported ).toContain( 'roles' );

		const jwks = await request.get( `${ STUB_PATH }?action=jwks` );
		expect( jwks.ok() ).toBeTruthy();

		const keys = await jwks.json();
		expect( keys.keys[ 0 ] ).toMatchObject( { kty: 'RSA', alg: 'RS256' } );
	} );
} );
