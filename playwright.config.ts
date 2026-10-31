import { defineConfig, devices } from '@playwright/test';

const port = process.env.E2E_PORT ?? '8765';

export default defineConfig({
  testDir: 'tests/e2e',
  forbidOnly: !!process.env.CI,
  // Tests share one database and reset it: they run one at a time.
  workers: 1,
  fullyParallel: false,
  timeout: 90_000,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: `php -d session.upload_progress.enabled=0 -d variables_order=EGPCS -S 127.0.0.1:${port} -t public`,
    url: `http://127.0.0.1:${port}/`,
    reuseExistingServer: !process.env.CI,
    env: { APP_ENV: 'e2e', APP_DEBUG: '0' },
  },
});
