// Normalizzazione dei risultati di `composer audit --format=json` e Trivy (JSON) in un formato
// unico, e diff testa ↔ base per i gate differenziali (ci.yml, image-check.yml) e l'autofix.
// Nessuna dipendenza: gira con il solo Node. Ripreso da ComunicaPA (scripts/security/findings.mjs),
// con composer al posto di pnpm.

/**
 * @typedef {{ tool: 'composer'|'trivy', ecosystem: 'composer'|'npm'|'os'|'image-lang',
 *   pkg: string, version: string, id: string, aliases: string[], severity: string,
 *   vulnerableRange: string|null, fixedVersions: string[], target: string }} Finding
 */

/** Struttura minima attesa, altrimenti errore (mai "nessun finding" su un output rotto). */
export class InvalidReportError extends Error {}

/**
 * Tutte le severità, come `composer audit` assoluto (che fallisce su qualsiasi advisory): il
 * gate differenziale non è più permissivo di quello assoluto, solo limitato ai nuovi.
 * I pacchetti abbandonati contano (config `audit.abandoned` = "fail", default di composer).
 * @returns {Finding[]}
 */
export function parseComposerAudit(json, { abandoned = true } = {}) {
  if (!json || typeof json !== 'object' || !('advisories' in json)) {
    throw new InvalidReportError('composer audit: manca "advisories"');
  }
  // Vuoto = [] (array JSON di PHP), altrimenti oggetto { pacchetto: [advisory, ...] }.
  const adv = Array.isArray(json.advisories) ? {} : json.advisories;
  if (typeof adv !== 'object' || adv === null) throw new InvalidReportError('composer audit: "advisories" non valido');
  const out = [];
  for (const [pkg, list] of Object.entries(adv)) {
    // Con un solo advisory PHP può serializzare un oggetto { "0": {...} }.
    for (const a of Array.isArray(list) ? list : Object.values(list ?? {})) {
      const aliases = [a.cve, ...(a.sources ?? []).map((s) => s.remoteId)]
        .filter((x) => typeof x === 'string' && x && x !== a.advisoryId);
      out.push({
        tool: 'composer', ecosystem: 'composer', pkg: a.packageName ?? pkg, version: '',
        id: String(a.advisoryId), aliases: [...new Set(aliases)],
        severity: String(a.severity ?? 'unknown').toUpperCase(),
        vulnerableRange: a.affectedVersions ?? null, fixedVersions: [], target: 'composer.lock',
      });
    }
  }
  if (abandoned) {
    const ab = Array.isArray(json.abandoned) ? {} : (json.abandoned ?? {});
    for (const [pkg, replacement] of Object.entries(ab)) {
      out.push({
        tool: 'composer', ecosystem: 'composer', pkg, version: '', id: 'abandoned',
        aliases: [], severity: 'ABANDONED', vulnerableRange: null,
        fixedVersions: replacement ? [String(replacement)] : [], target: 'composer.lock',
      });
    }
  }
  return out;
}

const SEVERE = new Set(['HIGH', 'CRITICAL']);
const NPM_LOCK_TYPES = new Set(['pnpm', 'npm', 'yarn']);

function ecosystemOf(result) {
  if (result.Class === 'os-pkgs') return 'os';
  if (result.Type === 'composer') return 'composer';
  if (NPM_LOCK_TYPES.has(result.Type)) return 'npm';
  return 'image-lang';
}

/** Solo HIGH/CRITICAL con versione corretta (come gli scan, `--ignore-unfixed`). @returns {Finding[]} */
export function parseTrivy(json) {
  if (!json || typeof json !== 'object' || !('SchemaVersion' in json)) {
    throw new InvalidReportError('trivy: manca "SchemaVersion"');
  }
  const out = [];
  for (const r of json.Results ?? []) {
    const ecosystem = ecosystemOf(r);
    for (const v of r.Vulnerabilities ?? []) {
      if (!SEVERE.has(v.Severity) || !v.FixedVersion) continue;
      out.push({
        tool: 'trivy', ecosystem, pkg: v.PkgName, version: v.InstalledVersion ?? '', id: v.VulnerabilityID,
        aliases: (v.VendorIDs ?? []).filter((a) => a !== v.VulnerabilityID), severity: v.Severity,
        vulnerableRange: null,
        fixedVersions: String(v.FixedVersion).split(',').map((s) => s.trim()).filter(Boolean),
        // Il target delle immagini contiene nome:tag (diverso tra testa e base): non entra nel confronto.
        target: r.Target,
      });
    }
  }
  return out;
}

const ids = (f) => new Set([f.id, ...f.aliases]);

/** Finding di `head` senza corrispondente in `base` (stesso ecosystem+pkg, id/alias in comune). */
export function diffFindings(head, base) {
  return head.filter((h) => {
    const hi = ids(h);
    return !base.some((b) => b.ecosystem === h.ecosystem && b.pkg === h.pkg && [...ids(b)].some((i) => hi.has(i)));
  });
}
