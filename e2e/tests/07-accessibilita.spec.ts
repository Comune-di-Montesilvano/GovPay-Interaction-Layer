import { test, expect, isolate } from './support/test';
import { analizza } from './support/axe';
import { FRONTOFFICE, OIDC } from './support/env';
import { loginOperatore } from './support/login';

// Accessibilita' (WCAG 2.1 AA, axe-core) delle pagine principali: gate e report in support/axe.ts.
// Gira dopo 04 (login OIDC gia' configurato) e 02 (pendenza esistente). Pagine del frontoffice
// raggiunte con azioni (form, checkout, errori), vista mobile e tastiera: 09-accessibilita-frontoffice.

const FRONTOFFICE_PAGINE: Array<[string, string]> = [
  ['frontoffice home', '/'],
  ['frontoffice guida', '/guida'],
  ['frontoffice paga avviso', '/pagamento-avviso'],
  ['frontoffice pagamento spontaneo', '/pagamento-spontaneo'],
  ['frontoffice carrello', '/carrello'],
];

for (const [nome, path] of FRONTOFFICE_PAGINE) {
  test(`axe ${nome}`, async ({ page }, testInfo) => {
    const res = await page.goto(`${FRONTOFFICE}${path}`);
    expect(res?.status(), `${path} non caricata`).toBeLessThan(500);
    await analizza(page, testInfo, nome);
  });
}

test('axe frontoffice area personale (OIDC)', async ({ browser }, testInfo) => {
  const ctx = await browser.newContext();
  await isolate(ctx);
  const page = await ctx.newPage();
  await page.goto(`${FRONTOFFICE}/login?return_to=/pendenze`);
  await expect(page).toHaveURL(new RegExp(`^${OIDC}/OIDC/authorization`));
  await page.getByRole('link', { name: 'Accedi come Mario Rossi' }).click();
  await expect(page).toHaveURL(new RegExp(`^${FRONTOFFICE}/pendenze`));
  await analizza(page, testInfo, 'frontoffice area personale');
  await page.goto(`${FRONTOFFICE}/profile`);
  await analizza(page, testInfo, 'frontoffice profilo');
  await ctx.close();
});

test('axe backoffice login', async ({ page }, testInfo) => {
  await page.goto('/login');
  await analizza(page, testInfo, 'backoffice login');
});

const BACKOFFICE_PAGINE: Array<[string, string]> = [
  ['backoffice dashboard', '/'],
  ['backoffice lista pendenze', '/pendenze'],
  ['backoffice nuova pendenza', '/pendenze/inserimento'],
];

for (const [nome, path] of BACKOFFICE_PAGINE) {
  test(`axe ${nome}`, async ({ page }, testInfo) => {
    await loginOperatore(page);
    const res = await page.goto(path);
    expect(res?.status(), `${path} non caricata`).toBeLessThan(500);
    await analizza(page, testInfo, nome);
  });
}
