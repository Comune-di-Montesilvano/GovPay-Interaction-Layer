import { test, expect } from './support/test';
import { CF_CITTADINO, EMAIL_CITTADINO, ID_DOMINIO } from './support/env';
import { pendenze, unmatched } from './support/fake';
import { loginOperatore } from './support/login';

// Flusso 2: operatore crea una pendenza (tipologia di test seminata da run.sh), la vede nel
// dettaglio e scarica l'avviso PDF (dal GovPay finto).
test('@pre-upgrade operatore crea pendenza e scarica l\'avviso', async ({ page }) => {
  await loginOperatore(page);

  await page.goto('/pendenze/inserimento');
  await page.locator('[name="idTipoPendenza"]').selectOption('TEST_SERVIZIO');
  await page.locator('[name="causale"]').fill('Servizio di test e2e');
  await page.locator('[name="importo"]').fill('10.00');
  await page.getByRole('radio', { name: 'Persona fisica' }).check();
  await page.locator('[name="soggettoPagatore[identificativo]"]').fill(CF_CITTADINO);
  await page.locator('[name="soggettoPagatore[anagrafica]"]').fill('Rossi');
  const nome = page.locator('[name="soggettoPagatore[nome]"]');
  if (await nome.isVisible()) await nome.fill('Mario');
  await page.locator('[name="soggettoPagatore[email]"]').fill(EMAIL_CITTADINO);
  await page.locator('button[formaction="/pendenze/preview"]').click();

  await page.locator('#btnConfermaECrea').click();
  await expect(page).toHaveURL(/\/pendenze\/dettaglio\//);

  const create = await pendenze();
  expect(create).toHaveLength(1);
  const p = create[0];
  expect(p.soggettoPagatore.identificativo).toBe(CF_CITTADINO);
  expect(p.numeroAvviso).toMatch(/^3\d{17}$/);

  const res = await page.request.get(`/avvisi/${ID_DOMINIO}/${p.numeroAvviso}`);
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toContain('application/pdf');
  expect((await res.body()).subarray(0, 4).toString()).toBe('%PDF');
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
