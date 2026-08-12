/**
 * Guards the environment the diagnostics assertions depend on.
 *
 * Every "renders without PHP errors" assertion in php-errors.spec.js works by reading PHP
 * diagnostics out of the rendered document. That only happens when both `WP_DEBUG` and
 * `WP_DEBUG_DISPLAY` are enabled: `wp_debug_mode()` leaves `display_errors` untouched when
 * `WP_DEBUG` is false, and then no diagnostic of any severity - not even a fatal - reaches
 * the page, so those assertions pass unconditionally and silently.
 *
 * wp-env's own `DEFAULT_CONFIG` (`@wordpress/env/lib/config/parse-config.js`) sets
 * `config.WP_DEBUG: true` at the root but also `env.tests.config = { WP_DEBUG: false,
 * SCRIPT_DEBUG: false }`, and environment-specific defaults beat the root-level `config`.
 * So a root-level `WP_DEBUG: true` does not reach every environment. Confusingly
 * `WP_DEBUG_LOG` and `WP_DEBUG_DISPLAY` *do* carry over, which makes a root-only config
 * look correct.
 *
 * These tests run against the **development** environment (see playwright.config.js), so
 * that is the environment asserted here, and `.wp-env.json` sets all three explicitly
 * under `env.development.config`. The `tests` environment keeps `WP_DEBUG_DISPLAY: false`
 * on purpose: nothing HTTP-facing runs there, only PHPUnit, which catches diagnostics
 * through its own handler and would just have display noise interleaved into its output.
 * The PHPUnit half of the guard lives in `phpunit.xml.dist` instead - `WP_DEBUG` alone is
 * not enough there, the `convert*ToExceptions` attributes are needed too.
 *
 * This test fails loudly if any of that regresses.
 */

const { test, expect } = require( '@playwright/test' );
const { loginAsAdmin } = require( './helpers' );

/**
 * Reads a constant's reported state from the Site Health "Info" tab.
 *
 * The constants live in a collapsed accordion panel, so the value is read from
 * `textContent` (which Playwright's `toHaveText` uses) rather than from `innerText`,
 * which is empty for hidden elements.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string}                          constant Constant name.
 * @return {import('@playwright/test').Locator} The value cell.
 */
function constantValue( page, constant ) {
	return page
		.locator( '#health-check-accordion-block-wp-constants tr', {
			has: page.locator( 'th', { hasText: new RegExp( `^${ constant }$` ) } ),
		} )
		.locator( 'td' );
}

/**
 * Site Health reports these constants as a translated word, so accept the English and
 * Dutch forms - this repo runs in English today but Soli sites are nl_NL.
 *
 * **Anchored on purpose.** `Uitgeschakeld` (disabled) contains `geschakeld`, so an
 * unanchored `/geschakeld/` matches while WP_DEBUG is off and the guard passes for
 * exactly the failure it exists to catch.
 */
const ENABLED = /^(?:Enabled|Ingeschakeld)$/;

test.describe( 'PHP diagnostics are visible in the test environment', () => {
	test( 'WP_DEBUG and WP_DEBUG_DISPLAY are enabled', async ( { context, page } ) => {
		await context.clearCookies();
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/site-health.php?tab=debug' );

		await expect( constantValue( page, 'WP_DEBUG' ) ).toHaveText( ENABLED );
		await expect( constantValue( page, 'WP_DEBUG_DISPLAY' ) ).toHaveText( ENABLED );
	} );
} );
