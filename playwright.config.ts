import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/browser',
  outputDir: 'test-results',
  fullyParallel: true,
  forbidOnly: Boolean(process.env.CI),
  // No retries: a test that passes only sometimes has found a race in either
  // the test or usephp.js, and both are worth seeing.
  retries: 0,
  reporter: process.env.CI ? [['github'], ['list']] : 'list',
  expect: { timeout: 10_000 },
  use: {
    browserName: 'chromium',
    trace: 'retain-on-failure',
    launchOptions: process.env.USEPHP_CHROMIUM_PATH
      ? { executablePath: process.env.USEPHP_CHROMIUM_PATH }
      : {},
  },
});
