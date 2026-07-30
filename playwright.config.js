import { defineConfig } from '@playwright/test';
const baseConfig = require('@wordpress/scripts/config/playwright.config');

// The client site. WP_ENV_PORT/WP_BASE_URL let this run alongside other wp-env projects.
const baseURL =
    process.env.WP_BASE_URL ||
    `http://localhost:${ process.env.WP_ENV_PORT || 8888 }`;

const config = defineConfig({
    ...baseConfig,
    testDir: 'e2e',
    retries: process.env.CI ? 1 : 0,
    reporter: [['html', { open: 'never' }]],

    // The preset's global setup signs in at wp-login.php and stores an admin session.
    // That cannot work here: the client redirects wp-login.php to the identity provider,
    // and these tests need to start out signed out to exercise the SSO flow at all.
    globalSetup: undefined,

    use: {
        ...baseConfig.use,
        baseURL,
        storageState: undefined,
        screenshot: 'only-on-failure',
        video: process.env.CI ? 'retain-on-failure' : 'on',
        trace: 'retain-on-failure',
    },
    outputDir: 'test-results',
    webServer: {
        ...baseConfig.webServer,
        command: 'npm run wp-env:start',
        url: baseURL,
        port: undefined,
    }
});

export default config;
