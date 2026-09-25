import { defineConfig, devices } from '@playwright/test'
import path from 'node:path'

// A private copy of the whole stack, so the tests never touch the everyday dev servers or the
// tracker_dev database: API on 8001 (APP_ENV=e2e reads apps/api/.env.e2e), dashboard on 3101.
const api = path.resolve(__dirname, '../api')
const dashboard = path.resolve(__dirname, '../dashboard')

export default defineConfig({
  testDir: './tests',
  globalSetup: './global-setup.ts',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 60_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: 'http://localhost:3101',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    timezoneId: 'Asia/Manila',
    locale: 'en-US',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    {
      command: 'php artisan serve --host=127.0.0.1 --port=8001 --no-reload',
      cwd: api,
      url: 'http://127.0.0.1:8001/health',
      env: { APP_ENV: 'e2e' },
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      // the built site (what ships), served like nginx does: static files plus the API on the same origin
      command: 'pnpm.cmd exec nuxt generate && node ../e2e/helpers/static-server.mjs .output/public 3101 8001',
      cwd: dashboard,
      url: 'http://localhost:3101',
      env: { NUXT_BUILD_DIR: '.nuxt-e2e', NUXT_TELEMETRY_DISABLED: '1' },
      reuseExistingServer: false,
      timeout: 360_000,
    },
  ],
})
