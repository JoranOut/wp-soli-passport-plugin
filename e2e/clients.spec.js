/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'OIDC Clients', () => {
	test( 'clients page loads correctly', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'OIDC Clients' );

		// Check table exists
		const table = page.locator( '#soli-passport-clients-table' );
		await expect( table ).toBeVisible();

		// Check search input exists
		const searchInput = page.locator( '.soli-search-input' );
		await expect( searchInput ).toBeVisible();

		// Check "Add Client" button exists
		const addButton = page.locator( 'a.btn-primary' ).filter( { hasText: 'Add Client' } );
		await expect( addButton ).toBeVisible();
	} );

	test( 'add client form loads correctly', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport&action=new' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'Add OIDC Client' );

		// Check form fields exist
		await expect( page.locator( '#client_id' ) ).toBeVisible();
		await expect( page.locator( '#name' ) ).toBeVisible();
		await expect( page.locator( '#redirect_uri' ) ).toBeVisible();
		await expect( page.locator( 'input[name="actief"]' ) ).toBeVisible();

		// Check submit button exists
		const submitButton = page.locator( 'button[type="submit"]' );
		await expect( submitButton ).toBeVisible();
		await expect( submitButton ).toContainText( 'Create Client' );
	} );

	test( 'can create a new client', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport&action=new' );

		// Fill in the form
		const uniqueId = 'test-client-' + Date.now();
		await page.locator( '#client_id' ).fill( uniqueId );
		await page.locator( '#name' ).fill( 'Test Client' );
		await page.locator( '#redirect_uri' ).fill( 'https://example.com/callback' );

		// Submit the form
		await page.locator( 'button[type="submit"]' ).click();

		// Should see success notice
		await expect( page.locator( '.soli-notice-success' ) ).toBeVisible();

		// Should see the generated secret
		await expect( page.locator( '#client-secret' ) ).toBeVisible();
	} );

	test( 'show inactive checkbox works', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport' );

		const checkbox = page.locator( '.soli-show-inactive' );
		await expect( checkbox ).toBeVisible();

		// Check the checkbox
		await checkbox.check();

		// URL should update
		await expect( page ).toHaveURL( /show_inactive=1/ );
	} );

	test( 'back link works on add form', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport&action=new' );

		// Click back link
		await page.locator( '.soli-back-link' ).click();

		// Should be back on clients list
		await expect( page.locator( 'h1' ) ).toContainText( 'OIDC Clients' );
		await expect( page ).toHaveURL( /page=soli-passport/ );
		await expect( page ).not.toHaveURL( /action=new/ );
	} );
} );
