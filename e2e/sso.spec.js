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
 * Meta key the OIDC client plugin stores the 'sub' claim in.
 *
 * It is the only thing tying a WordPress user to a provider account, so it is what
 * the identity tests read.
 */
const SUBJECT_IDENTITY_META = 'openid-connect-generic-subject-identity';

/**
 * Delete a user on the client so each test starts from a clean slate.
 *
 * @param {string} email Email address of the user to remove.
 */
async function deleteUser( email ) {
	await wpCli( `user delete ${ email } --yes --network` ).catch( () => {} );
}

/**
 * Read the provider identity a local user is mapped to.
 *
 * @param {string} user Anything WP-CLI accepts as a user: ID, login or email.
 * @return {Promise<string>} The 'sub' value, empty when the user was never mapped.
 */
async function subjectIdentity( user ) {
	return wpCli( `user meta get ${ user } ${ SUBJECT_IDENTITY_META }` );
}

/**
 * The session cookies WordPress sets for a signed-in user.
 *
 * @param {import('@playwright/test').Page} page Playwright page.
 * @return {Promise<Array>} Matching cookies; empty means no session.
 */
async function sessionCookies( page ) {
	const cookies = await page.context().cookies();

	return cookies.filter( ( cookie ) => cookie.name.startsWith( 'wordpress_logged_in' ) );
}

/**
 * Sign in through the stub provider as one of the fixture accounts.
 *
 * @param {import('@playwright/test').Page} page          Playwright page.
 * @param {string}                          fixtureKey    Fixture account key.
 * @param {Object}                          [options]     Options.
 * @param {string}                          [options.sign] Stub signing mode, see
 *                                                         tests/stub-provider/index.php.
 *                                                         Omit for a valid signature.
 */
async function signInAs( page, fixtureKey, { sign } = {} ) {
	await page.goto( '/wp-login.php' );

	// login_type is 'auto', so the client redirects straight to the provider.
	await expect( page ).toHaveURL( new RegExp( STUB_PATH.replace( /\./g, '\\.' ) ) );

	const account = page.locator( `#stub-user-${ fixtureKey }` );

	if ( sign ) {
		// Follow the picker's own link so the client's state and nonce are kept, and
		// only change how the stub signs the id_token.
		const href = await account.getAttribute( 'href' );

		// Fail loudly rather than silently signing in with a valid token, which would
		// make the signature tests pass for the wrong reason.
		expect( href ).toContain( 'stub_sign=valid' );

		await page.goto( href.replace( 'stub_sign=valid', `stub_sign=${ sign }` ) );
	} else {
		await account.click();
	}

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

test.describe( 'identity and roles', () => {
	const SHARED_EMAIL = 'shared.family@example.com';
	const REVOKED_EMAIL = 'rik.revoked@example.com';

	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( 'two provider accounts sharing an email address stay separate', async ( {
		page,
	} ) => {
		await deleteUser( SHARED_EMAIL );

		// The first account signs in and owns the local user for that address.
		await signInAs( page, 'shared-email-first' );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpadminbar' ) ).toBeVisible();

		const firstUser = await wpCli( `user get ${ SHARED_EMAIL } --field=ID` );
		expect( await subjectIdentity( firstUser ) ).toBe( '16' );
		expect( await wpCli( `user get ${ firstUser } --field=roles` ) ).toBe( 'editor' );

		// A second provider account - different sub, same email address. Members of
		// one household really do share one.
		await page.context().clearCookies();
		await signInAs( page, 'shared-email-second' );

		// It gets no session, and above all not the first account's.
		expect( await sessionCookies( page ) ).toEqual( [] );

		// The first account is untouched. This is what 'link_existing_users: off'
		// buys: with linking on, sub 17 would take over sub 16's user here and be
		// handed their role.
		expect( await subjectIdentity( firstUser ) ).toBe( '16' );
		expect( await wpCli( `user get ${ firstUser } --field=roles` ) ).toBe( 'editor' );
		expect( await wpCli( `user get ${ firstUser } --field=user_email` ) ).toBe(
			SHARED_EMAIL
		);

		// WordPress reserves an email address for one user, so the second account is
		// refused outright rather than merged into the first.
		await expect( page ).toHaveURL( /login-error=failed-user-creation/ );

		const emails = ( await wpCli( 'user list --field=user_email' ) ).split( '\n' );
		expect(
			emails.filter( ( email ) => email.trim() === SHARED_EMAIL )
		).toHaveLength( 1 );
	} );

	test( 'a role revoked at the provider is removed on the next login', async ( {
		page,
	} ) => {
		await deleteUser( REVOKED_EMAIL );

		// The provider grants administrator, and the local user really becomes one.
		await signInAs( page, 'role-revoked-before' );
		expect( await wpCli( `user get ${ REVOKED_EMAIL } --field=roles` ) ).toBe(
			'administrator'
		);

		const asAdmin = await page.goto( '/wp-admin/users.php' );
		expect( asAdmin.status() ).toBe( 200 );

		// Same account, same sub, but the provider no longer grants that role.
		await page.context().clearCookies();
		await signInAs( page, 'role-revoked-after' );

		expect( await wpCli( `user get ${ REVOKED_EMAIL } --field=roles` ) ).toBe(
			'subscriber'
		);
		expect(
			await wpCli( 'user list --role=administrator --field=user_email' )
		).not.toContain( REVOKED_EMAIL );

		// The capability is gone with it, not just the role label.
		const asSubscriber = await page.goto( '/wp-admin/users.php' );
		expect( asSubscriber.status() ).toBe( 403 );
		await expect( page.locator( 'body' ) ).toContainText(
			'not allowed to list users'
		);
	} );
} );

test.describe( 'id_token signature verification', () => {
	const EDITOR_EMAIL = 'eva.editor@example.com';

	// Both cases would sign in if the client stopped checking signatures against the
	// JWKS - the claims themselves are the ones that normally grant editor.
	const CASES = [
		[ 'wrong-key', 'signed with a key the provider does not publish', 'Signature+verification+failed' ],
		[ 'alg-none', 'not signed at all', 'Algorithm+not+supported' ],
	];

	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	for ( const [ mode, description, expectedMessage ] of CASES ) {
		test( `an id_token ${ description } is refused`, async ( { page } ) => {
			await deleteUser( EDITOR_EMAIL );

			await signInAs( page, 'editor', { sign: mode } );

			await expect( page ).toHaveURL( /login-error=jwt-verification-failed/ );
			expect( page.url() ).toContain( expectedMessage );

			// No session, and no local user for a token nobody can vouch for.
			expect( await sessionCookies( page ) ).toEqual( [] );
			expect( await wpCli( 'user list --field=user_email' ) ).not.toContain(
				EDITOR_EMAIL
			);
		} );
	}
} );

test.describe( 'public user endpoints', () => {
	// Pretty REST routes are not rewritten in the wp-env container, so the tests use
	// the query form. It goes through the same rest_endpoints filter.
	const USERS_ROUTE = '/?rest_route=/wp/v2/users';

	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( 'the users route is gone for anonymous requests but not for members', async ( {
		page,
		request,
	} ) => {
		await deleteUser( 'eva.editor@example.com' );

		// The `request` fixture carries no session, unlike `page.request`.
		const anonymous = await request.get( USERS_ROUTE );
		expect( anonymous.status() ).toBe( 404 );
		expect( ( await anonymous.json() ).code ).toBe( 'rest_no_route' );

		await signInAs( page, 'editor' );

		// WordPress ignores the session cookie on REST requests without a nonce, so
		// take the one wp-admin hands to the block editor - that is the caller this
		// restriction must not break.
		await page.goto( '/wp-admin/post-new.php' );
		const nonce = await page.evaluate( () => window.wpApiSettings.nonce );
		expect( nonce ).toBeTruthy();

		const member = await page.request.get( USERS_ROUTE, {
			headers: { 'X-WP-Nonce': nonce },
		} );
		expect( member.status() ).toBe( 200 );
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
		// Query form, because pretty REST routes are not rewritten in the container.
		await request.get( '/?rest_route=/wp/v2/users' );

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
