import { appendFileSync, mkdirSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Page, TestInfo } from '@playwright/test';
import { test, expect, isolate } from './support/test';
import { FRONTOFFICE, OIDC } from './support/env';
import { loginOperatore } from './support/login';

// Accessibilita' (WCAG 2.1 AA, axe-core) delle pagine principali. REPORT-ONLY: nessuna asserzione
// sulle violazioni, il test fallisce solo se la pagina non si carica. Risultati per pagina in
// a11y-report/axe-summary.md (riepilogo del job) e come allegato JSON del test.
// Gira dopo 04 (login OIDC gia' configurato) e 02 (pendenza esistente).
const REPORT_DIR = 'a11y-report';
const SUMMARY = `${REPORT_DIR}/axe-summary.md`;
const IMPATTI = ['critical', 'serious', 'moderate', 'minor'] as const;

async function analizza(page: Page, testInfo: TestInfo, nome: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  await testInfo.attach(`axe-${nome}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });

  const perImpatto = Object.fromEntries(IMPATTI.map((i) => [i, 0])) as Record<(typeof IMPATTI)[number], number>;
  for (const v of results.violations) {
    const i = (v.impact ?? 'minor') as (typeof IMPATTI)[number];
    perImpatto[i] += v.nodes.length;
  }
  const regole = results.violations.map((v) => `\`${v.id}\``).join(', ') || '—';
  mkdirSync(REPORT_DIR, { recursive: true });
  appendFileSync(SUMMARY, `| ${nome} | ${IMPATTI.map((i) => perImpatto[i]).join(' | ')} | ${regole} |\n`);
}

test.beforeAll(() => {
  mkdirSync(REPORT_DIR, { recursive: true });
  appendFileSync(SUMMARY, '| Pagina | Critiche | Gravi | Moderate | Minori | Regole |\n|---|---|---|---|---|---|\n');
});

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
