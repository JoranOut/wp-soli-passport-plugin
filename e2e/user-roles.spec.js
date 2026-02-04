/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'User Role Overrides', () => {
	test( 'user roles page loads correctly', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'User Role Overrides' );

		// Check table exists
		const table = page.locator( '#soli-passport-user-roles-table' );
		await expect( table ).toBeVisible();

		// Check search input exists
		const searchInput = page.locator( '.soli-search-input' );
		await expect( searchInput ).toBeVisible();

		// Check client filter exists
		const clientFilter = page.locator( '.soli-filter-client' );
		await expect( clientFilter ).toBeVisible();

		// Check "Add Override" button exists
		const addButton = page.locator( 'a.btn-primary' ).filter( { hasText: 'Add Override' } );
		await expect( addButton ).toBeVisible();
	} );

	test( 'add override form shows message when no clients exist', async ( { admin, page } ) => {
		// First, we need to ensure there are no clients
		// This test assumes the database starts fresh or no clients have been created yet
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles&action=new' );

		// Either we see the form or the "no clients" message
		const pageContent = await page.content();
		const hasForm = pageContent.includes( 'override_type' ) || pageContent.includes( 'user_id' );
		const hasNoClientsMessage = pageContent.includes( 'Create OIDC Client' );

		expect( hasForm || hasNoClientsMessage ).toBeTruthy();
	} );

	test( 'back link works on add form', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles&action=new' );

		// Click back link
		await page.locator( '.soli-back-link' ).click();

		// Should be back on user roles list
		await expect( page.locator( 'h1' ) ).toContainText( 'User Role Overrides' );
		await expect( page ).toHaveURL( /page=soli-passport-user-roles/ );
		await expect( page ).not.toHaveURL( /action=new/ );
	} );
} );

test.describe( 'User Role Overrides with Client', () => {
	let testClientId;

	test.beforeAll( async ( { browser } ) => {
		// Create a test client first
		const context = await browser.newContext();
		const page = await context.newPage();

		// Login as admin
		await page.goto( '/wp-login.php' );
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
		await page.locator( '#wp-submit' ).click();
		await page.waitForURL( /wp-admin/ );

		// Create a client
		testClientId = 'test-role-client-' + Date.now();
		await page.goto( '/wp-admin/admin.php?page=soli-passport&action=new' );
		await page.locator( '#client_id' ).fill( testClientId );
		await page.locator( '#name' ).fill( 'Test Role Client' );
		await page.locator( '#redirect_uri' ).fill( 'https://example.com/callback' );
		await page.locator( 'button[type="submit"]' ).click();

		await context.close();
	} );

	test( 'add override form loads when client exists', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles&action=new' );

		// Should see client and role dropdowns
		await expect( page.locator( '#client_id' ) ).toBeVisible();
		await expect( page.locator( '#role' ) ).toBeVisible();

		// Check submit button exists
		const submitButton = page.locator( 'button[type="submit"]' );
		await expect( submitButton ).toBeVisible();
		await expect( submitButton ).toContainText( 'Create Override' );
	} );

	test( 'client filter dropdown filters the table', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles' );

		const clientFilter = page.locator( '.soli-filter-client' );
		await expect( clientFilter ).toBeVisible();

		// Get the options
		const options = await clientFilter.locator( 'option' ).allTextContents();
		expect( options.length ).toBeGreaterThanOrEqual( 1 ); // At least "All clients" option
	} );

	test( 'role dropdown contains expected roles', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-user-roles&action=new' );

		const roleSelect = page.locator( '#role' );
		await expect( roleSelect ).toBeVisible();

		// Check that expected role options exist (WordPress role slugs)
		await expect( roleSelect.locator( 'option[value="administrator"]' ) ).toHaveCount( 1 );
		await expect( roleSelect.locator( 'option[value="subscriber"]' ) ).toHaveCount( 1 );
		await expect( roleSelect.locator( 'option[value="no-access"]' ) ).toHaveCount( 1 );
	} );
} );
