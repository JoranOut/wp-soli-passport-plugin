/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Role Mappings', () => {
	test( 'role mappings page loads correctly', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'Role Mappings' );

		// Check table exists
		const table = page.locator( '#soli-passport-role-mappings-table' );
		await expect( table ).toBeVisible();

		// Check search input exists
		const searchInput = page.locator( '.soli-search-input' );
		await expect( searchInput ).toBeVisible();

		// Check "Add Mapping" button exists
		const addButton = page.locator( 'a.btn-primary' ).filter( { hasText: 'Add Mapping' } );
		await expect( addButton ).toBeVisible();
	} );

	test( 'page shows info card explaining how mappings work', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings' );

		// Check info card exists
		const infoCard = page.locator( '.soli-info-card' );
		await expect( infoCard ).toBeVisible();
		await expect( infoCard ).toContainText( 'How Role Mappings Work' );
		await expect( infoCard ).toContainText( 'priority' );
	} );

	test( 'add mapping form loads correctly', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings&action=new' );

		// Check page title
		await expect( page.locator( 'h1' ) ).toContainText( 'Add Role Mapping' );

		// Check form fields exist
		await expect( page.locator( '#client_id' ) ).toBeVisible();
		// mapping_type is a radio button group
		await expect( page.locator( 'input[name="mapping_type"]' ) ).toBeVisible();
		await expect( page.locator( '#role' ) ).toBeVisible();

		// Check submit button
		const submitButton = page.locator( 'button[type="submit"]' );
		await expect( submitButton ).toBeVisible();
		await expect( submitButton ).toContainText( 'Create Mapping' );
	} );

	test( 'back link works on add form', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings&action=new' );

		// Click back link
		await page.locator( '.soli-back-link' ).click();

		// Should be back on mappings list
		await expect( page.locator( 'h1' ) ).toContainText( 'Role Mappings' );
		await expect( page ).toHaveURL( /page=soli-passport-role-mappings/ );
		await expect( page ).not.toHaveURL( /action=new/ );
	} );

	test( 'mapping type selector changes available fields', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings&action=new' );

		// mapping_type is a radio button group
		const wpRoleRadio = page.locator( 'input[name="mapping_type"][value="wp_role"]' );
		await expect( wpRoleRadio ).toBeVisible();

		// Click WP Role radio button
		await wpRoleRadio.click();

		// Should show WP role dropdown
		await expect( page.locator( '#wp_role' ) ).toBeVisible();
	} );

	test( 'role dropdown contains expected roles', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings&action=new' );

		const roleSelect = page.locator( '#role' );
		await expect( roleSelect ).toBeVisible();

		// Check that expected role options exist (WordPress role slugs)
		await expect( roleSelect.locator( 'option[value="administrator"]' ) ).toHaveCount( 1 );
		await expect( roleSelect.locator( 'option[value="subscriber"]' ) ).toHaveCount( 1 );
		await expect( roleSelect.locator( 'option[value="no-access"]' ) ).toHaveCount( 1 );
	} );
} );

test.describe( 'Role Mappings with Client', () => {
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
		testClientId = 'test-mapping-client-' + Date.now();
		await page.goto( '/wp-admin/admin.php?page=soli-passport&action=new' );
		await page.locator( '#client_id' ).fill( testClientId );
		await page.locator( '#name' ).fill( 'Test Mapping Client' );
		await page.locator( '#redirect_uri' ).fill( 'https://example.com/callback' );
		await page.locator( 'button[type="submit"]' ).click();

		await context.close();
	} );

	test( 'can create a new WP role mapping', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings&action=new' );

		// Select the test client (by text content since value is the client_id)
		const clientSelect = page.locator( '#client_id' );
		const clientOptions = clientSelect.locator( 'option' );
		const clientCount = await clientOptions.count();
		// Select the last option which should be our test client
		if ( clientCount > 1 ) {
			await clientSelect.selectOption( { index: clientCount - 1 } );
		}

		// Click WP Role radio button
		await page.locator( 'input[name="mapping_type"][value="wp_role"]' ).click();

		// Select a WP role (subscriber)
		await page.locator( '#wp_role' ).selectOption( 'subscriber' );

		// Select a passport role (subscriber)
		await page.locator( '#role' ).selectOption( 'subscriber' );

		// Set priority
		await page.locator( '#priority' ).fill( '1' );

		// Submit the form
		await page.locator( 'button[type="submit"]' ).click();

		// Should see success notice
		await expect( page.locator( '.soli-notice-success' ) ).toBeVisible();
	} );

	test( 'table shows columns for client, source, priority, role', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=soli-passport-role-mappings' );

		const table = page.locator( '#soli-passport-role-mappings-table' );

		// Check column headers exist (Type was merged into Source)
		await expect( table.locator( 'th' ).filter( { hasText: 'Client' } ) ).toBeVisible();
		await expect( table.locator( 'th' ).filter( { hasText: 'Source' } ) ).toBeVisible();
		await expect( table.locator( 'th' ).filter( { hasText: 'Priority' } ) ).toBeVisible();
		await expect( table.locator( 'th' ).filter( { hasText: 'Role' } ) ).toBeVisible();
		await expect( table.locator( 'th' ).filter( { hasText: 'Actions' } ) ).toBeVisible();
	} );
} );
