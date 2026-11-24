import { test, expect, isolate } from './support/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, BACKOFFICE } from './support/env';

// I form pubblici del backoffice (login, password dimenticata, reset) hanno il token CSRF nel
// markup, non solo iniettato da assets/js/csrf-guard.js: il login funziona anche senza JS e lo
// scanner ZAP non segnala "Absence of Anti-CSRF Tokens". Gira dopo 01 (operatore creato).
test('login operatore con JavaScript disattivato', async ({ browser }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL: BACKOFFICE });
  await isolate(ctx);
  const page = await ctx.newPage();
  await page.goto('/login');
  await expect(page.locator('form[action="/login"] input[name="csrf_token"]')).toHaveCount(1);
  await page.locator('[name="email"]').fill(ADMIN_EMAIL);
  await page.locator('[name="password"]').fill(ADMIN_PASSWORD);
  await page.locator('form [type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login/);
  await ctx.close();
});

test('form password dimenticata con token CSRF nel markup', async ({ page }) => {
  await page.goto('/password-dimenticata');
  await expect(page.locator('form[action="/password-dimenticata"] input[name="csrf_token"]')).toHaveCount(1);
});
