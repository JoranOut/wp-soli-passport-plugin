/**
 * Shared helpers for the Soli Passport e2e tests.
 *
 * These tests run against the wp-env *development* environment (port 8910), not the
 * tests environment - see the comment in playwright.config.js for why. That is the
 * environment whose WP_DEBUG / WP_DEBUG_DISPLAY the diagnostics assertions here depend
 * on, and the environment debug-mode.spec.js guards.
 */

const { expect } = require( '@playwright/test' );

const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'password';

/**
 * Fragments of paths that identify this plugin's own PHP files.
 *
 * Scoping matters more here than in most Soli plugins: this repo is an adapter that
 * loads on top of the third-party `daggerhart-openid-connect-generic` plugin, which is
 * installed into the same site by .wp-env.json. A deprecation in that plugin (or in
 * WordPress core) is not this repo's to fix and must not turn CI red, so the softer
 * diagnostics are matched only when the file they point at belongs here.
 *
 * The directory fragment covers every file this repo ships, including `updater.php`;
 * the bare filenames are a belt-and-braces match for the case where PHP reports a
 * relative path (`include_once 'updater.php'` in the main file does exactly that).
 */
const PLUGIN_PHP_FILES =
	'wp-soli-passport-plugin/|wp-soli-passport-plugin\\.php|class-soli-passport-(?:dependency-checker|user-privacy|role-sync)\\.php';

/** Diagnostics that are never acceptable, wherever they come from. */
const FATAL_ERROR_PATTERN = /Fatal error|Parse error|Recoverable fatal error/i;

/** Softer diagnostics, but only when they point at this plugin's files. */
const PLUGIN_DIAGNOSTIC_PATTERN = new RegExp(
	'(Warning|Notice|Deprecated):[^\\n]*(' + PLUGIN_PHP_FILES + ')',
	'i'
);

/**
 * Asserts that the currently loaded page contains no PHP diagnostics.
 *
 * `WP_DEBUG` and `WP_DEBUG_DISPLAY` are enabled for the wp-env `development`
 * environment (see `.wp-env.json`), so PHP diagnostics are printed into the rendered
 * document. Anything PHP emits before `<html>` or inside `<head>` is relocated into the
 * body by the HTML parser, so reading the body text catches diagnostics from any point
 * in the request.
 *
 * This complements the debug.log assertion in sso.spec.js rather than replacing it: the
 * log catches diagnostics from requests no browser rendered (the server-to-server token
 * call), while this catches what a visitor would actually see on the page.
 *
 * @param {import('@playwright/test').Page} page
 */
async function expectNoPhpDiagnostics( page ) {
	const url = page.url();
	const body = await page.locator( 'body' ).innerText();

	expect( body, `PHP fatal/parse error rendered by ${ url }` ).not.toMatch(
		FATAL_ERROR_PATTERN
	);
	expect(
		body,
		`PHP warning/notice/deprecation from this plugin rendered by ${ url }`
	).not.toMatch( PLUGIN_DIAGNOSTIC_PATTERN );
}

/**
 * Builds a REST URL that works regardless of the permalink structure.
 *
 * `.wp-env-setup.sh` does set pretty permalinks, but `?rest_route=` reaches WordPress
 * whatever the rewrite state is. A `/wp-json/` request under plain permalinks 404s at
 * Apache without ever entering WordPress, which makes an "expect 404" assertion pass
 * for entirely the wrong reason - so the query form is used unconditionally.
 *
 * @param {string} route REST route, e.g. `/wp/v2/users`.
 * @return {string} Relative URL.
 */
function restUrl( route ) {
	return '/?rest_route=' + encodeURIComponent( route );
}

/**
 * Logs in as the wp-env administrator with the local WordPress form.
 *
 * `login_type` is `auto`, so a bare wp-login.php redirects to the identity provider.
 * `?bypass-sso` is the escape hatch this plugin adds precisely so an administrator is
 * never locked out, and it is what lets these tests reach wp-admin without touching the
 * OIDC flow at all.
 *
 * @param {import('@playwright/test').Page} page
 */
async function loginAsAdmin( page ) {
	await page.goto( '/wp-login.php?bypass-sso' );
	await page.fill( '#user_login', ADMIN_USER );
	await page.fill( '#user_pass', ADMIN_PASSWORD );
	await page.click( '#wp-submit' );
	await page.waitForURL( /wp-admin/ );
}

module.exports = {
	ADMIN_USER,
	ADMIN_PASSWORD,
	PLUGIN_PHP_FILES,
	FATAL_ERROR_PATTERN,
	PLUGIN_DIAGNOSTIC_PATTERN,
	restUrl,
	loginAsAdmin,
	expectNoPhpDiagnostics,
};
