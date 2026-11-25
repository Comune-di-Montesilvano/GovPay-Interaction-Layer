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

// Le pagine backoffice si analizzano con GovPay raggiungibile (fake): se il controllo di stato
// risponde offline il test fallisce qui, col motivo, invece che come contrasto del badge rosso.
test('backoffice: GovPay risulta online', async ({ page }) => {
  await loginOperatore(page);
  const res = await page.request.get('/api/govpay/status');
  const body = await res.json();
  expect(body.status, `GovPay offline nello stack e2e: ${body.error}`).toBe('online');
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

// Stato "GovPay: Offline" (badge rosso in alto + testo in sidebar): in CI il controllo di stato a
// volte risponde offline e axe misurava il badge a meta' transizione di colore. Qui lo stato e'
// forzato e l'analisi parte appena compare il testo, cioe' nel momento peggiore.
test('axe backoffice dashboard con GovPay offline', async ({ page }, testInfo) => {
  await page.route('**/api/govpay/status', (route) => route.fulfill({ status: 503, contentType: 'application/json', body: '{"online":false,"error":"test"}' }));
  await loginOperatore(page);
  await page.goto('/');
  await expect(page.locator('.bo-govpay-status-text')).toHaveText(/Offline/);
  // Niente transizione sullo sfondo del badge: il testo bianco cambia subito, lo sfondo passerebbe
  // da chiaro a rosso in 0,3 s con il contrasto sotto 4,5:1 nel mezzo.
  const transizione = await page.locator('#bo-govpay-status-badge').evaluate((el) => getComputedStyle(el).transitionProperty);
  expect(transizione).not.toMatch(/background|all/);
  await analizza(page, testInfo, 'backoffice dashboard GovPay offline');
});
