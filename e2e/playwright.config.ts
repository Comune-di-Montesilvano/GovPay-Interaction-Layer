import { defineConfig } from '@playwright/test';

// Gira nel container Playwright sulla rete dello stack e2e (e2e/run.sh): hostname = nomi dei
// servizi compose. File in ordine (prefisso numerico), un worker: lo stato passa dal DB del run.
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  timeout: 30_000,
  expect: { timeout: 10_000 },
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'it-IT',
    timezoneId: 'Europe/Rome',
  },
  projects: [
    { name: 'e2e', use: { baseURL: 'http://backoffice' } },
  ],
});
