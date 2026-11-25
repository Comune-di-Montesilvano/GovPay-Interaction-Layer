import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test, expect } from '@playwright/test';
import { aggiornaRiepilogo } from './support/axe';

// Riepilogo axe: una riga per pagina anche quando il test viene ritentato (retries in CI).
test('riepilogo axe: il retry sostituisce la riga della pagina', () => {
  const dir = mkdtempSync(join(tmpdir(), 'axe-riepilogo-'));
  aggiornaRiepilogo(dir, 'pagina-a', [1, 0, 0, 0], '`color-contrast`', 0);
  aggiornaRiepilogo(dir, 'pagina-b', [0, 0, 0, 0], '—', 0);
  aggiornaRiepilogo(dir, 'pagina-a', [0, 0, 0, 0], '—', 1);

  const righe = readFileSync(join(dir, 'axe-summary.md'), 'utf8').trim().split('\n');
  expect(righe).toEqual([
    '| Pagina | Critiche | Gravi | Moderate | Minori | Regole |',
    '|---|---|---|---|---|---|',
    '| pagina-a (ritentata) | 0 | 0 | 0 | 0 | — |',
    '| pagina-b | 0 | 0 | 0 | 0 | — |',
  ]);
});
