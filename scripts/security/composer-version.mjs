// Versioni e vincoli composer quanto basta per l'autofix: confronto tra versioni rilasciate e
// valutazione degli `affectedVersions` degli advisory Packagist (es. ">=2.0,<2.12.3|<1.9.1").
// Non è un risolutore: i vincoli di composer.json li valuta composer stesso (`update --with`).

const STABILITY = { dev: 0, alpha: 1, a: 1, beta: 2, b: 2, rc: 3, stable: 4, patch: 5, pl: 5, p: 5 };

/**
 * "v1.2.3", "1.2.3.0", "2.0.0-RC1", "3.0.0-beta.2" → { nums: [4 numeri], stab, stabNum }.
 * null per versioni di branch (dev-main, 1.x-dev): mai candidate né confrontabili.
 */
export function parseVersion(v) {
  if (typeof v !== 'string') return null;
  const s = v.trim().replace(/^v/i, '');
  if (/^dev-|-dev$|\.x-dev$/i.test(s)) return null;
  const m = s.match(/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:[-.]?(dev|alpha|a|beta|b|rc|stable|patch|pl|p)[.-]?(\d+)?)?$/i);
  if (!m) return null;
  const stab = (m[5] ?? 'stable').toLowerCase();
  return {
    nums: [m[1], m[2], m[3], m[4]].map((x) => Number.parseInt(x ?? '0', 10)),
    stab: STABILITY[stab], stabNum: Number.parseInt(m[6] ?? '0', 10),
  };
}

export const isStable = (v) => { const p = parseVersion(v); return !!p && p.stab >= STABILITY.stable; };

/** <0, 0, >0. Errore su versioni non confrontabili (mai un ordine inventato). */
export function compareVersions(a, b) {
  const pa = parseVersion(a), pb = parseVersion(b);
  if (!pa || !pb) throw new Error(`versione non confrontabile: ${!pa ? a : b}`);
  for (let i = 0; i < 4; i++) if (pa.nums[i] !== pb.nums[i]) return pa.nums[i] - pb.nums[i];
  if (pa.stab !== pb.stab) return pa.stab - pb.stab;
  return pa.stabNum - pb.stabNum;
}

/** Un vincolo semplice: ">=1.2", "<2.0.1", "!=1.5", "1.4.2", "*". */
function satisfiesOne(version, c) {
  if (c === '*' || c === '') return true;
  const m = c.match(/^(>=|<=|>|<|==|=|!=)?\s*(.+)$/);
  if (!m) throw new Error(`vincolo non supportato: ${c}`);
  const op = m[1] ?? '=';
  const cmp = compareVersions(version, m[2]);
  switch (op) {
    case '>=': return cmp >= 0;
    case '<=': return cmp <= 0;
    case '>': return cmp > 0;
    case '<': return cmp < 0;
    case '!=': return cmp !== 0;
    default: return cmp === 0;
  }
}

/** Grammatica degli advisory Packagist: OR con `|`/`||`, AND con `,` o spazio. */
export function satisfies(version, constraint) {
  if (typeof constraint !== 'string' || !constraint.trim()) throw new Error('vincolo vuoto');
  return constraint.split(/\s*\|\|?\s*/).some((alt) =>
    alt.trim().split(/\s*,\s*|\s+(?=[<>=!])/).filter(Boolean).every((c) => satisfiesOne(version, c.trim())));
}
