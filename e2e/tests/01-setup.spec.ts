import { test, expect } from './support/test';
import { ADMIN_EMAIL, ADMIN_PASSWORD, BACKOFFICE, FAKE, FRONTOFFICE, ID_DOMINIO } from './support/env';
import { unmatched } from './support/fake';

// Flusso 1: setup wizard su DB vuoto → login operatore. Configura le URL dei finti: i file
// successivi partono da questo stato (stesso DB del run).
test.describe.configure({ mode: 'serial' });

test('@pre-upgrade wizard di primo avvio con GovPay e pagoPA finti', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveURL(/\/setup/);

  await page.goto('/setup/step/1');
  await page.locator('[name="entity_name"]').fill('Comune di Esempio');
  await page.locator('[name="entity_ipa_code"]').fill('c_x000');
  await page.locator('[name="id_dominio"]').fill(ID_DOMINIO);
  await page.locator('[name="id_a2a"]').fill('A2A-E2E');
  await page.getByRole('button', { name: /Avanti/ }).click();

  await expect(page).toHaveURL(/\/setup\/step\/2/);
  await page.locator('[name="admin_email"]').fill(ADMIN_EMAIL);
  await page.locator('[name="admin_password"]').fill(ADMIN_PASSWORD);
  await page.locator('[name="admin_password_confirm"]').fill(ADMIN_PASSWORD);
  await page.locator('[name="backoffice_url"]').fill(BACKOFFICE);
  await page.locator('[name="frontoffice_url"]').fill(FRONTOFFICE);
  await page.getByRole('button', { name: /Avanti/ }).click();

  await expect(page).toHaveURL(/\/setup\/step\/3/);
  await page.locator('[name="govpay_backoffice_url"]').fill(`${FAKE}/backoffice`);
  await page.locator('[name="govpay_pendenze_url"]').fill(`${FAKE}/pendenze`);
  await page.locator('[name="govpay_patch_url"]').fill(`${FAKE}/pendenze`);
  await page.locator('[name="govpay_pagamenti_url"]').fill(`${FAKE}/pagamenti`);
  await page.locator('[name="govpay_ragioneria_url"]').fill(`${FAKE}/ragioneria`);
  await page.locator('[name="authentication_govpay"]').selectOption('basic');
  await page.locator('[name="govpay_user"]').fill('utente-finto');
  await page.locator('[name="govpay_password"]').fill('password-finta');
  await page.getByRole('button', { name: /Avanti/ }).click();

  await expect(page).toHaveURL(/\/setup\/step\/4/);
  await page.locator('[name="mailer_dsn"]').fill('smtp://mailpit:1025');
  await page.locator('[name="mailer_from_address"]').fill('noreply@example.test');
  await page.locator('[name="mailer_from_name"]').fill('Comune di Esempio');
  await page.locator('[name="pagopa_checkout_url"]').fill(`${FAKE}/checkout`);
  await page.locator('[name="pagopa_checkout_key"]').fill('chiave-finta');
  await page.getByRole('button', { name: /Avanti/ }).click();

  await expect(page).toHaveURL(/\/setup\/step\/5/);
  await page.locator('form[action="/setup/complete"] [type="submit"]').click();
  await expect(page).toHaveURL(/\/setup\/done|\/login/);

  await page.goto('/login');
  await page.locator('[name="email"]').fill(ADMIN_EMAIL);
  await page.locator('[name="password"]').fill(ADMIN_PASSWORD);
  await page.locator('form [type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login/);
  await expect(page.getByText('Accesso effettuato')).toBeVisible();
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
