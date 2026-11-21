// Metadati Packagist v2 (https://repo.packagist.org/p2/<vendor>/<pkg>.json): versioni con data di
// pubblicazione e repository sorgente, per età minima e provenienza delle correzioni.

/**
 * Formato "composer/2.0" minificato: ogni versione dopo la prima contiene solo le chiavi
 * cambiate rispetto alla precedente, "__unset" toglie la chiave (Composer\MetadataMinifier).
 */
export function expandMinified(list) {
  const out = [];
  let prev = null;
  for (const entry of list) {
    const cur = prev ? { ...prev } : {};
    for (const [k, v] of Object.entries(entry)) {
      if (v === '__unset') delete cur[k];
      else cur[k] = v;
    }
    out.push(cur);
    prev = cur;
  }
  return out;
}

/** { version, time, source } per ogni versione rilasciata del pacchetto. */
export function versionsFromP2(json, name) {
  const list = json?.packages?.[name];
  if (!Array.isArray(list)) throw new Error(`packagist: nessun metadato per ${name}`);
  const full = json.minified === 'composer/2.0' ? expandMinified(list) : list;
  return full.map((v) => ({ version: v.version, time: v.time ?? null, source: v.source?.url ?? null }));
}

/**
 * Repository sorgente in forma confrontabile: https://github.com/Owner/Repo(.git) e
 * git@github.com:owner/repo.git → github.com/owner/repo.
 */
export function normalizeRepoUrl(url) {
  if (typeof url !== 'string' || !url) return null;
  return url.trim().toLowerCase()
    .replace(/^git@([^:]+):/, '$1/')
    .replace(/^[a-z+]+:\/\//, '')
    .replace(/^[^@/]+@/, '')
    .replace(/\.git$/, '')
    .replace(/\/+$/, '');
}

/** Nomi composer validi (vendor/pkg), unica forma accettata nell'URL. Separatore obbligatorio tra
 * i blocchi alfanumerici: regex non ambigua, tempo lineare (niente backtracking esponenziale). */
export const isPackageName = (n) => typeof n === 'string' && /^[a-z0-9]+(?:[_.-][a-z0-9]+)*\/[a-z0-9]+(?:(?:[_.]|-{1,2})[a-z0-9]+)*$/.test(n);

export async function fetchP2(name, { fetchImpl = fetch } = {}) {
  if (!isPackageName(name)) throw new Error(`nome pacchetto non valido: ${name}`);
  const res = await fetchImpl(`https://repo.packagist.org/p2/${name}.json`);
  if (!res.ok) throw new Error(`packagist ${name}: HTTP ${res.status}`);
  return versionsFromP2(await res.json(), name);
}
