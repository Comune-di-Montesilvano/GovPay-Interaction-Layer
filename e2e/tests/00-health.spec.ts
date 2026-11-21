import { test, expect } from '@playwright/test';
import { BACKOFFICE, FRONTOFFICE, FAKE, MAILPIT } from './support/env';

test('stack e2e raggiungibile', async ({ request }) => {
  expect((await request.get(`${BACKOFFICE}/health`)).status()).toBe(200);
  expect((await request.get(`${FRONTOFFICE}/`)).status()).toBe(200);
  expect((await request.get(`${FAKE}/__fake/health`)).status()).toBe(200);
  expect((await request.get(`${MAILPIT}/api/v1/info`)).status()).toBe(200);
});
