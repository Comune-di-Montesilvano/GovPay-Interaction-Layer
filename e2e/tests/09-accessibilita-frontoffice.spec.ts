import type { Page } from '@playwright/test';
import { test, expect, isolate } from './support/test';
import { analizza } from './support/axe';
import { CF_CITTADINO, FRONTOFFICE, ID_DOMINIO, OIDC } from './support/env';
import { pendenze } from './support/fake';

// Accessibilita' del frontoffice oltre le pagine d'ingresso (07): pagine raggiunte con azioni
// (ricerca avviso, tipologie, bollo, esiti checkout, errori), vista da telefono con i menu
// aperti e navigazione da tastiera con focus visibile. Gate axe in support/axe.ts.
// Gira dopo 02 (pendenza creata), 03 (pendenza pagata) e 04 (OIDC configurato).

async function cercaAvviso(page: Page, cf: string, numeroAvviso: string): Promise<void> {
  await page.goto(`${FRONTOFFICE}/pagamento-avviso`);
  await page.locator('[name="codiceFiscale"]').fill(cf);
  await page.locator('[name="codiceAvviso"]').fill(numeroAvviso);
  await page.getByRole('button', { name: /Cerca avviso/ }).click();
}

test('axe frontoffice anteprima avviso', async ({ page }, testInfo) => {
  const [p] = await pendenze();
  expect(p, 'pendenza del flusso 2 assente').toBeTruthy();
  await cercaAvviso(page, CF_CITTADINO, p.numeroAvviso);
  await expect(page.getByRole('heading', { name: `Avviso ${p.numeroAvviso}` })).toBeVisible();
  await analizza(page, testInfo, 'frontoffice anteprima avviso');
});

test('axe frontoffice avviso non trovato (errore form)', async ({ page }, testInfo) => {
  await cercaAvviso(page, CF_CITTADINO, '000000000000000099');
  await analizza(page, testInfo, 'frontoffice avviso non trovato');
});

test('axe frontoffice form avviso con campi non validi', async ({ page }, testInfo) => {
  await page.goto(`${FRONTOFFICE}/pagamento-avviso`);
  // Valori non validi inviati senza la validazione HTML5 del browser: si analizza l'errore server.
  await page.locator('form[action="/pagamento-avviso"]').evaluate((f) => f.setAttribute('novalidate', ''));
  await page.locator('[name="codiceFiscale"]').fill('ABC');
  await page.locator('[name="codiceAvviso"]').fill('12');
  await page.getByRole('button', { name: /Cerca avviso/ }).click();
  await analizza(page, testInfo, 'frontoffice avviso campi non validi');
});

test('axe frontoffice form pagamento spontaneo', async ({ page }, testInfo) => {
  await page.goto(`${FRONTOFFICE}/pagamento-spontaneo`);
  const tipologia = page.locator('a[href^="/pagamento-spontaneo?tipologia="]').first();
  await expect(tipologia, 'nessuna tipologia spontanea nello stack e2e').toHaveCount(1);
  await tipologia.click();
  await expect(page.locator('form')).not.toHaveCount(0);
  await analizza(page, testInfo, 'frontoffice form spontaneo');
});

test('axe frontoffice marca da bollo', async ({ page }, testInfo) => {
  const res = await page.goto(`${FRONTOFFICE}/pagamento-bollo`);
  expect(res?.status()).toBeLessThan(500);
  await analizza(page, testInfo, 'frontoffice marca da bollo');
});

test('axe frontoffice avviso bollo', async ({ page }, testInfo) => {
  const q = new URLSearchParams({ iuv: '00000000000000001', ente: ID_DOMINIO, importo: '1600', causale: 'Marca da bollo di prova' });
  const res = await page.goto(`${FRONTOFFICE}/avviso-bollo?${q}`);
  expect(res?.status()).toBe(200);
  await analizza(page, testInfo, 'frontoffice avviso bollo');
});

for (const [nome, path] of [
  ['checkout ok', '/checkout/ok'],
  ['checkout annullato', '/checkout/cancel'],
  ['checkout errore', '/checkout/error'],
  ['checkout ritorno GovPay', '/checkout/govpay-return'],
  ['pagina non trovata', '/pagina-che-non-esiste'],
  ['accesso negato', '/accesso-negato'],
] as const) {
  test(`axe frontoffice ${nome}`, async ({ page }, testInfo) => {
    const res = await page.goto(`${FRONTOFFICE}${path}`);
    expect(res?.status(), `${path}`).toBeLessThan(500);
    await analizza(page, testInfo, `frontoffice ${nome}`);
  });
}

test('axe frontoffice dettaglio pendenza (OIDC)', async ({ browser }, testInfo) => {
  const ctx = await browser.newContext();
  await isolate(ctx);
  const page = await ctx.newPage();
  await page.goto(`${FRONTOFFICE}/login?return_to=/pendenze`);
  await expect(page).toHaveURL(new RegExp(`^${OIDC}/OIDC/authorization`));
  await page.getByRole('link', { name: 'Accedi come Mario Rossi' }).click();
  await expect(page).toHaveURL(new RegExp(`^${FRONTOFFICE}/pendenze`));
  const dettaglio = page.locator('a[title="Dettaglio"]').first();
  await expect(dettaglio, 'nessuna pendenza nell area personale').toHaveCount(1);
  await dettaglio.click();
  await analizza(page, testInfo, 'frontoffice dettaglio pendenza');
  await ctx.close();
});

// Vista da telefono: layout a una colonna, menu a scomparsa e selettore lingua compresso.
test.describe('mobile 390x844', () => {
  test.use({ viewport: { width: 390, height: 844 } });

  for (const [nome, path] of [
    ['home', '/'],
    ['paga avviso', '/pagamento-avviso'],
    ['pagamento spontaneo', '/pagamento-spontaneo'],
    ['carrello', '/carrello'],
  ] as const) {
    test(`axe frontoffice mobile ${nome}`, async ({ page }, testInfo) => {
      await page.goto(`${FRONTOFFICE}${path}`);
      await analizza(page, testInfo, `frontoffice mobile ${nome}`);
    });
  }

  test('axe frontoffice mobile menu e lingua aperti, menu da tastiera', async ({ page }, testInfo) => {
    await page.goto(`${FRONTOFFICE}/`);
    const toggle = page.locator('#nav-toggle-btn');
    await expect(toggle).toBeVisible();
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#fo-nav-menu')).toBeVisible();
    await analizza(page, testInfo, 'frontoffice mobile menu aperto');
    await page.locator('.lang-menu summary').click();
    await expect(page.locator('.lang-menu-list')).toBeVisible();
    await analizza(page, testInfo, 'frontoffice mobile lingua aperta');
  });
});

// Ogni elemento che riceve il focus da tastiera deve mostrarlo (WCAG 2.4.7): outline o box-shadow.
async function focusVisibile(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const el = document.activeElement as HTMLElement | null;
    if (!el || el === document.body) return null;
    const cs = getComputedStyle(el);
    const outline = cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) > 0;
    const shadow = cs.boxShadow !== 'none';
    if (outline || shadow) return null;
    const id = el.id ? `#${el.id}` : '';
    const name = el.getAttribute('name') ? `[name=${el.getAttribute('name')}]` : '';
    return `${el.tagName.toLowerCase()}${id}${name} "${(el.textContent ?? '').trim().slice(0, 40)}"`;
  });
}

test('tastiera: paga un avviso con focus sempre visibile', async ({ page }) => {
  const [p] = await pendenze();
  await page.goto(`${FRONTOFFICE}/pagamento-avviso`);

  // Primo Tab: link "Salta al contenuto", visibile, porta al contenuto principale.
  await page.keyboard.press('Tab');
  const skip = page.locator('.skip-link');
  await expect(skip).toBeFocused();
  await expect(skip).toBeInViewport();
  await page.keyboard.press('Enter');
  await expect(page.locator('#main')).toBeFocused();

  // Tab fino al campo codice avviso (primo del form): ogni elemento attraversato mostra il focus.
  const senzaFocus: string[] = [];
  let trovato = false;
  for (let i = 0; i < 40 && !trovato; i++) {
    await page.keyboard.press('Tab');
    const problema = await focusVisibile(page);
    if (problema) senzaFocus.push(problema);
    trovato = await page.locator('[name="codiceAvviso"]').evaluate((el) => el === document.activeElement);
  }
  expect(trovato, 'campo codice avviso non raggiungibile con Tab').toBe(true);
  await page.keyboard.type(p.numeroAvviso);
  await page.keyboard.press('Tab');
  await expect(page.locator('[name="codiceFiscale"]')).toBeFocused();
  await page.keyboard.type(CF_CITTADINO);
  await page.keyboard.press('Enter');
  await expect(page.getByRole('heading', { name: `Avviso ${p.numeroAvviso}` })).toBeVisible();

  // Nell'anteprima: tutti gli elementi focalizzabili mostrano il focus.
  for (let i = 0; i < 30; i++) {
    await page.keyboard.press('Tab');
    const problema = await focusVisibile(page);
    if (problema) senzaFocus.push(problema);
  }
  expect([...new Set(senzaFocus)], 'elementi senza indicatore di focus').toEqual([]);
});
