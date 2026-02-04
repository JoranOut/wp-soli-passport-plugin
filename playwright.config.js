import { defineConfig } from '@playwright/test';
const baseConfig = require('@wordpress/scripts/config/playwright.config');

const config = defineConfig({
    ...baseConfig,
    testDir: 'e2e',
    retries: process.env.CI ? 1 : 0,
    reporter: [['html', { open: 'never' }]],
    use: {
        ...baseConfig.use,
        baseURL: process.env.BASE_URL || 'http://localhost:8889',
        screenshot: 'only-on-failure',
        video: process.env.CI ? 'retain-on-failure' : 'on',
        trace: 'retain-on-failure',
    },
    outputDir: 'test-results',
    webServer: {
        ...baseConfig.webServer,
        command: 'npm run wp-env:start',
    }
});

export default config;
