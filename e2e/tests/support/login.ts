import { expect, type Page } from '@playwright/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD } from './env';

/** Login operatore dal form reale (CSRF compreso). */
export async function loginOperatore(page: Page): Promise<void> {
  await page.goto('/login');
  await page.locator('[name="email"]').fill(ADMIN_EMAIL);
  await page.locator('[name="password"]').fill(ADMIN_PASSWORD);
  await page.locator('form [type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login/);
}
