import { appendFileSync, existsSync, mkdirSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Page, TestInfo } from '@playwright/test';
import { expect } from './test';

// Analisi axe (WCAG 2.1 AA) condivisa dalle spec di accessibilita'. Gate: violazioni critiche o
// gravi fanno fallire il test; moderate e minori solo nel report. Report per pagina in
// a11y-report/axe-summary.md (riepilogo del job), a11y-report/<pagina>.json (nodi con selettore e
// colori, per correggere) e come allegato JSON del test.
export const REPORT_DIR = 'a11y-report';
const SUMMARY = `${REPORT_DIR}/axe-summary.md`;
const IMPATTI = ['critical', 'serious', 'moderate', 'minor'] as const;

export async function analizza(page: Page, testInfo: TestInfo, nome: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  await testInfo.attach(`axe-${nome}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });

  const perImpatto = Object.fromEntries(IMPATTI.map((i) => [i, 0])) as Record<(typeof IMPATTI)[number], number>;
  for (const v of results.violations) {
    const i = (v.impact ?? 'minor') as (typeof IMPATTI)[number];
    perImpatto[i] += v.nodes.length;
  }
  const regole = results.violations.map((v) => `\`${v.id}\``).join(', ') || '—';
  mkdirSync(REPORT_DIR, { recursive: true });
  if (!existsSync(SUMMARY)) {
    writeFileSync(SUMMARY, '| Pagina | Critiche | Gravi | Moderate | Minori | Regole |\n|---|---|---|---|---|---|\n');
  }
  writeFileSync(`${REPORT_DIR}/${nome.replace(/[^a-z0-9]+/gi, '-')}.json`, JSON.stringify(results.violations, null, 2));
  appendFileSync(SUMMARY, `| ${nome} | ${IMPATTI.map((i) => perImpatto[i]).join(' | ')} | ${regole} |\n`);
  const bloccanti = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  expect(bloccanti.map((v) => `${v.id} (${v.nodes.length})`), `violazioni axe critiche/gravi su ${nome}: dettaglio in ${REPORT_DIR}/`).toEqual([]);
}
