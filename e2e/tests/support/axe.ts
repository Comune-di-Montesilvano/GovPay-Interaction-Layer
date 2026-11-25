import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import type { Page, TestInfo } from '@playwright/test';
import { expect } from './test';

// Analisi axe (WCAG 2.1 AA) condivisa dalle spec di accessibilita'. Gate: violazioni critiche o
// gravi fanno fallire il test; moderate e minori solo nel report. Report per pagina in
// a11y-report/axe-summary.md (riepilogo del job), a11y-report/<pagina>.json (nodi con selettore e
// colori, per correggere) e come allegato JSON del test.
export const REPORT_DIR = 'a11y-report';
const IMPATTI = ['critical', 'serious', 'moderate', 'minor'] as const;

type Riga = { celle: number[]; regole: string; ritentata: boolean };

// Una riga per pagina in axe-summary.md, nell'ordine della prima analisi: un retry (retries: 1 in
// CI) sostituisce la riga della pagina invece di aggiungerne un'altra. Righe in axe-righe.json,
// riepilogo riscritto per intero via file temporaneo + rename (mai un file a meta').
export function aggiornaRiepilogo(dir: string, nome: string, celle: number[], regole: string, retry: number): void {
  mkdirSync(dir, { recursive: true });
  const fileRighe = `${dir}/axe-righe.json`;
  let righe: Record<string, Riga> = {};
  try {
    righe = JSON.parse(readFileSync(fileRighe, 'utf8')) as Record<string, Riga>;
  } catch (e) {
    if ((e as NodeJS.ErrnoException).code !== 'ENOENT') throw e;
  }
  righe[nome] = { celle, regole, ritentata: retry > 0 };
  scriviAtomico(fileRighe, JSON.stringify(righe, null, 2));
  const md = ['| Pagina | Critiche | Gravi | Moderate | Minori | Regole |', '|---|---|---|---|---|---|'];
  for (const [n, r] of Object.entries(righe)) {
    md.push(`| ${n}${r.ritentata ? ' (ritentata)' : ''} | ${r.celle.join(' | ')} | ${r.regole} |`);
  }
  scriviAtomico(`${dir}/axe-summary.md`, md.join('\n') + '\n');
}

function scriviAtomico(file: string, contenuto: string): void {
  writeFileSync(`${file}.tmp`, contenuto);
  renameSync(`${file}.tmp`, file);
}

export async function analizza(page: Page, testInfo: TestInfo, nome: string): Promise<void> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
  await testInfo.attach(`axe-${nome}.json`, { body: JSON.stringify(results.violations, null, 2), contentType: 'application/json' });

  const perImpatto = Object.fromEntries(IMPATTI.map((i) => [i, 0])) as Record<(typeof IMPATTI)[number], number>;
  for (const v of results.violations) {
    const i = (v.impact ?? 'minor') as (typeof IMPATTI)[number];
    perImpatto[i] += v.nodes.length;
  }
  const regole = results.violations.map((v) => `\`${v.id}\``).join(', ') || '—';
  aggiornaRiepilogo(REPORT_DIR, nome, IMPATTI.map((i) => perImpatto[i]), regole, testInfo.retry);
  writeFileSync(`${REPORT_DIR}/${nome.replace(/[^a-z0-9]+/gi, '-')}.json`, JSON.stringify(results.violations, null, 2));
  const bloccanti = results.violations.filter((v) => v.impact === 'critical' || v.impact === 'serious');
  expect(bloccanti.map((v) => `${v.id} (${v.nodes.length})`), `violazioni axe critiche/gravi su ${nome}: dettaglio in ${REPORT_DIR}/`).toEqual([]);
}
