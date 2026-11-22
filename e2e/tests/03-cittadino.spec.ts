import { readFileSync } from 'node:fs';
import type { Page } from '@playwright/test';
import { test, expect } from './support/test';
import { CF_CITTADINO, FAKE, FRONTOFFICE, ID_DOMINIO } from './support/env';
import { pendenze, setPaid, unmatched } from './support/fake';

// Flusso 3 (accesso libero, tipo `none`): "Paga un avviso" con la pendenza del flusso 2 →
// checkout pagoPA finto → esito → pagamento registrato sul finto → ricevuta PDF.
async function cercaAvviso(page: Page, numeroAvviso: string): Promise<void> {
  await page.goto(`${FRONTOFFICE}/pagamento-avviso`);
  await page.locator('[name="codiceFiscale"]').fill(CF_CITTADINO);
  await page.locator('[name="codiceAvviso"]').fill(numeroAvviso);
  await page.getByRole('button', { name: /Cerca avviso/ }).click();
}

test('cittadino paga un avviso e scarica la ricevuta', async ({ page }) => {
  const [p] = await pendenze();
  expect(p, 'pendenza del flusso 2 assente').toBeTruthy();

  await cercaAvviso(page, p.numeroAvviso);
  await expect(page.getByRole('heading', { name: `Avviso ${p.numeroAvviso}` })).toBeVisible();
  await expect(page.getByText('€ 10,00').first()).toBeVisible();

  await page.getByRole('button', { name: 'Paga subito' }).or(page.getByRole('link', { name: 'Paga subito' })).first().click();
  await expect(page).toHaveURL(new RegExp(`^${FAKE}/__checkout/`));
  await page.getByRole('link', { name: 'Esito OK' }).click();
  await expect(page).toHaveURL(new RegExp(`^${FRONTOFFICE}/checkout/ok`));

  await setPaid(ID_DOMINIO, p.iuvAvviso);
  await cercaAvviso(page, p.numeroAvviso);
  const download = page.waitForEvent('download');
  await page.getByRole('link', { name: /ricevuta/i }).first().click();
  const pdf = await (await download).path();
  expect(readFileSync(pdf).subarray(0, 4).toString()).toBe('%PDF');
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
