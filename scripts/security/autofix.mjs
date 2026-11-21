#!/usr/bin/env node
// Correzioni autonome delle vulnerabilità con versione corretta su main (security-autofix.yml).
// Adattato da ComunicaPA (scripts/security/autofix.mjs) a composer + immagini base.
//
// Regole (uguali a ComunicaPA dove l'ecosistema lo permette):
//  - composer: versione corretta più BASSA (non toccata da nessun advisory del pacchetto),
//    pubblicata da almeno minAgeHours, provenienza = stesso repository sorgente della versione
//    installata (Packagist non ha firme: un cambio di repository è il segnale di dirottamento
//    del pacchetto). Applicata con `composer update <pkg> --with <pkg>:<ver>`, senza script né
//    plugin, solo composer.lock modificato; un vincolo di composer.json che la esclude =
//    non correggibile (major, a mano).
//  - immagine base: digest più recente dello STESSO tag (Docker Official Image, Docker Hub),
//    pubblicato da almeno minAgeHours, adottato solo se elimina finding presenti nel digest
//    fissato (scansione di entrambi: un finding dei nostri layer non conta come corretto).
//  - npm (asset frontend) e pacchetti abbandonati: solo segnalati (Dependabot / a mano).
//  - una PR per correzione, auto-merge dopo la merge queue; PR con check falliti chiuse e la
//    correzione esclusa (issue "correzione fallita", da cui si toglie la riga per riprovare).
//
//   node scripts/security/autofix.mjs analyze --audit a.json --lock composer.lock \
//        [--trivy-fs fs.json] [--trivy-image app=img.json ...] [--excluded state.json] \
//        [--force pkg@ver|name:tag] --out plan.json
//   node scripts/security/autofix.mjs apply --plan plan.json --index N --out result.json
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { parseArgs } from 'node:util';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { parseComposerAudit, parseTrivy } from './findings.mjs';
import { compareVersions, satisfies, isStable, parseVersion } from './composer-version.mjs';
import { fetchP2, normalizeRepoUrl, isPackageName } from './packagist.mjs';

const here = dirname(fileURLToPath(import.meta.url));
export const loadConfig = () => JSON.parse(readFileSync(join(here, 'autofix.config.json'), 'utf8'));

const HOUR = 3600 * 1000;
const fixKey = (f) => `${f.pkg}@${f.version}`;

/** Versione installata di ogni pacchetto (packages + packages-dev) da composer.lock. */
export function lockVersions(lock) {
  const out = new Map();
  for (const p of [...(lock.packages ?? []), ...(lock['packages-dev'] ?? [])]) out.set(p.name, p);
  return out;
}

/**
 * Piano composer. `versions(pkg)` → [{ version, time, source }] (Packagist).
 * @returns {Promise<{ fixed: object[], pending: object[], unfixable: object[], skipped: object[] }>}
 */
export async function planComposer({ audit, lock, versions, excluded = [], force = '', now = new Date(), minAgeHours = 48 }) {
  const findings = parseComposerAudit(audit);
  const installed = lockVersions(lock);
  const out = { fixed: [], pending: [], unfixable: [], skipped: [] };
  const byPkg = new Map();
  for (const f of findings) {
    if (!byPkg.has(f.pkg)) byPkg.set(f.pkg, []);
    byPkg.get(f.pkg).push(f);
  }
  for (const [pkg, list] of byPkg) {
    const ids = list.map((f) => f.id);
    const abandoned = list.find((f) => f.id === 'abandoned');
    if (abandoned) {
      out.unfixable.push({ kind: 'composer', pkg, ids, reason: `pacchetto abbandonato${abandoned.fixedVersions[0] ? `, sostituto suggerito ${abandoned.fixedVersions[0]}` : ''}: sostituzione a mano` });
    }
    const advisories = list.filter((f) => f.id !== 'abandoned');
    if (!advisories.length) continue;
    const advIds = advisories.map((f) => f.id);
    const lockPkg = installed.get(pkg);
    if (!lockPkg) { out.unfixable.push({ kind: 'composer', pkg, ids: advIds, reason: 'non trovato in composer.lock' }); continue; }
    const from = lockPkg.version;
    if (!parseVersion(from)) { out.unfixable.push({ kind: 'composer', pkg, from, ids: advIds, reason: `versione installata ${from} non confrontabile (branch/dev)` }); continue; }
    if (lockPkg.dist?.type === 'path') { out.unfixable.push({ kind: 'composer', pkg, from, ids: advIds, reason: 'pacchetto locale (path repository): rigenerare il client' }); continue; }
    const ranges = advisories.map((f) => f.vulnerableRange).filter(Boolean);
    if (ranges.length !== advisories.length) { out.unfixable.push({ kind: 'composer', pkg, from, ids: advIds, reason: 'advisory senza affectedVersions' }); continue; }

    let all;
    try { all = await versions(pkg); } catch (e) { out.unfixable.push({ kind: 'composer', pkg, from, ids: advIds, reason: `metadati Packagist: ${e.message}` }); continue; }
    const allowPre = !isStable(from);
    const affected = (v) => ranges.some((r) => satisfies(v, r));
    const candidates = all
      .filter((v) => parseVersion(v.version) && (allowPre || isStable(v.version)))
      .filter((v) => compareVersions(v.version, from) > 0 && !affected(v.version))
      .sort((a, b) => compareVersions(a.version, b.version));
    if (!candidates.length) { out.unfixable.push({ kind: 'composer', pkg, from, ids: advIds, reason: 'nessuna versione corretta pubblicata' }); continue; }

    const pick = candidates[0];
    const fix = { kind: 'composer', pkg, from, version: pick.version.replace(/^v/i, ''), ids: advIds, published: pick.time };
    if (excluded.includes(fixKey(fix))) { out.skipped.push({ ...fix, reason: 'esclusa (correzione fallita in precedenza)' }); continue; }

    // Provenienza: stesso repository sorgente della versione installata.
    const was = normalizeRepoUrl(lockPkg.source?.url), now_ = normalizeRepoUrl(pick.source);
    if (!was || !now_ || was !== now_) {
      out.unfixable.push({ ...fix, reason: `provenienza non verificata: repository sorgente ${lockPkg.source?.url ?? '?'} → ${pick.source ?? '?'}` });
      continue;
    }
    const age = pick.time ? (now - new Date(pick.time)) / HOUR : NaN;
    const forced = force === fixKey(fix);
    if (!Number.isFinite(age)) { out.unfixable.push({ ...fix, reason: 'data di pubblicazione assente' }); continue; }
    if (age < minAgeHours && !forced) { out.pending.push({ ...fix, reason: `pubblicata da ${Math.floor(age)} h (minimo ${minAgeHours} h)` }); continue; }
    out.fixed.push({ ...fix, forced });
  }
  return out;
}

/** Righe `FROM name:tag@sha256:...` (con eventuale `AS stage`). */
export function parseFrom(text) {
  const out = [];
  for (const line of text.split(/\r?\n/)) {
    const m = line.match(/^FROM\s+([a-z0-9][a-z0-9._/-]*):([A-Za-z0-9._-]+)@(sha256:[0-9a-f]{64})(\s|$)/);
    if (m) out.push({ name: m[1], tag: m[2], digest: m[3], line });
  }
  return out;
}

/** Sostituisce il digest di name:tag; errore se la riga non c'è esattamente una volta. */
export function replaceFromDigest(text, { name, tag, from, to }) {
  if (!/^sha256:[0-9a-f]{64}$/.test(to)) throw new Error(`digest non valido: ${to}`);
  const needle = `FROM ${name}:${tag}@${from}`;
  const n = text.split(needle).length - 1;
  if (n !== 1) throw new Error(`${needle}: attesa 1 occorrenza, trovate ${n}`);
  return text.replace(needle, `FROM ${name}:${tag}@${to}`);
}

/**
 * Piano immagini base. `tagInfo(name, tag)` → { digest, lastPushed } (Docker Hub);
 * `scanIds(ref)` → Set di id HIGH/CRITICAL con fix trovati nell'immagine `ref`.
 */
export async function planImages({ imageFindings, config, dockerfiles, tagInfo, scanIds, excluded = [], force = '', now = new Date(), minAgeHours = 48 }) {
  const out = { fixed: [], pending: [], unfixable: [], skipped: [] };
  const groups = new Map(); // dockerfile+name:tag → { base, apps, ids }
  for (const [app, findings] of Object.entries(imageFindings)) {
    const conf = config.images?.[app];
    const ids = findings.filter((f) => f.ecosystem === 'os' || f.ecosystem === 'image-lang').map((f) => f.id);
    if (!ids.length) continue;
    if (!conf) { out.unfixable.push({ kind: 'image', pkg: app, ids, reason: 'immagine non configurata in autofix.config.json' }); continue; }
    const base = parseFrom(dockerfiles[conf.dockerfile] ?? '').find((b) => b.name === conf.base);
    if (!base) { out.unfixable.push({ kind: 'image', pkg: app, ids, reason: `FROM ${conf.base}:<tag>@sha256 non trovato in ${conf.dockerfile}` }); continue; }
    const key = `${conf.dockerfile}|${base.name}:${base.tag}`;
    const g = groups.get(key) ?? { base, dockerfile: conf.dockerfile, apps: [], ids: new Set() };
    g.apps.push(app);
    ids.forEach((i) => g.ids.add(i));
    groups.set(key, g);
  }
  for (const g of groups.values()) {
    const pkg = `${g.base.name}:${g.base.tag}`;
    const ids = [...g.ids].sort();
    const common = { kind: 'image', pkg, from: g.base.digest, dockerfile: g.dockerfile, apps: g.apps };
    let info;
    try { info = await tagInfo(g.base.name, g.base.tag); } catch (e) { out.unfixable.push({ ...common, ids, reason: `Docker Hub: ${e.message}` }); continue; }
    if (!/^sha256:[0-9a-f]{64}$/.test(info?.digest ?? '')) { out.unfixable.push({ ...common, ids, reason: 'digest del tag non valido' }); continue; }
    if (info.digest === g.base.digest) { out.unfixable.push({ ...common, ids, reason: 'immagine base senza aggiornamenti: attendere la correzione a monte' }); continue; }
    const fix = { ...common, version: info.digest, published: info.lastPushed };
    if (excluded.includes(fixKey(fix))) { out.skipped.push({ ...fix, ids, reason: 'esclusa (correzione fallita in precedenza)' }); continue; }
    const age = info.lastPushed ? (now - new Date(info.lastPushed)) / HOUR : NaN;
    const forced = force === pkg || force === fixKey(fix);
    if (!Number.isFinite(age)) { out.unfixable.push({ ...fix, ids, reason: 'data di pubblicazione del tag assente' }); continue; }
    if (age < minAgeHours && !forced) { out.pending.push({ ...fix, ids, reason: `digest pubblicato da ${Math.floor(age)} h (minimo ${minAgeHours} h)` }); continue; }
    // Corretti = presenti nella base fissata (non nei nostri layer) e assenti in quella nuova.
    let oldIds, newIds;
    try {
      oldIds = await scanIds(`${g.base.name}:${g.base.tag}@${g.base.digest}`);
      newIds = await scanIds(`${g.base.name}:${g.base.tag}@${info.digest}`);
    } catch (e) { out.unfixable.push({ ...fix, ids, reason: `scansione immagine base: ${e.message}` }); continue; }
    const fixedIds = ids.filter((i) => oldIds.has(i) && !newIds.has(i));
    if (!fixedIds.length) { out.unfixable.push({ ...fix, ids, reason: 'il digest più recente del tag non corregge queste vulnerabilità' }); continue; }
    out.fixed.push({ ...fix, ids: fixedIds, remaining: ids.filter((i) => !fixedIds.includes(i)), forced });
  }
  return out;
}

/** Solo segnalazione: npm (asset frontend) è di Dependabot. */
export function planNpm(fsFindings) {
  const byPkg = new Map();
  for (const f of fsFindings.filter((x) => x.ecosystem === 'npm')) {
    byPkg.set(f.pkg, [...(byPkg.get(f.pkg) ?? []), f.id]);
  }
  return [...byPkg].map(([pkg, ids]) => ({ kind: 'npm', pkg, ids, reason: 'npm (asset frontend): correzione via Dependabot (security update), non automatica' }));
}

// --- PR e issue -----------------------------------------------------------------------------

export const MARKER = (k) => `<!-- autofix-fix ${k} -->`;
export const EXCLUDED_MARKER = (k) => `<!-- autofix-excluded ${k} -->`;

export function prMarkers(body) {
  return [...String(body ?? '').matchAll(/<!-- autofix-fix (\S+) -->/g)].map((m) => m[1]);
}
export function parseExcluded(body) {
  return [...new Set([...String(body ?? '').matchAll(/<!-- autofix-excluded (\S+) -->/g)].map((m) => m[1]))];
}

/** PR autofix aperte con almeno un check fallito: da chiudere, correzioni da escludere. */
export function failedPrs(prs) {
  const bad = new Set(['FAILURE', 'ERROR', 'TIMED_OUT', 'CANCELLED', 'ACTION_REQUIRED', 'STARTUP_FAILURE']);
  return prs.filter((p) => (p.statusCheckRollup ?? []).some((c) => bad.has(c.conclusion ?? c.state)));
}

/** Branch del bot per una correzione: solo [a-z0-9._-]. */
export function branchFor(fix) {
  const v = fix.kind === 'image' ? fix.version.slice('sha256:'.length, 'sha256:'.length + 12) : fix.version;
  return `security-autofix/${`${fix.pkg}-${v}`.toLowerCase().replace(/[^a-z0-9._-]+/g, '-')}`;
}

const cell = (s) => String(s ?? '').replace(/\|/g, '\\|').replace(/\r?\n/g, ' ');

export function prBody(fix) {
  const what = fix.kind === 'image'
    ? `Immagine base \`${fix.pkg}\` in \`${fix.dockerfile}\` (${fix.apps.join(', ')}): digest \`${fix.from}\` → \`${fix.version}\`, pubblicato ${fix.published}.`
    : `\`${fix.pkg}\` ${fix.from} → **${fix.version}** (versione corretta più bassa, pubblicata ${fix.published}), solo \`composer.lock\`.`;
  return [
    '## Correzione di sicurezza automatica', '', what, '',
    `Vulnerabilità corrette: ${fix.ids.map((i) => `\`${i}\``).join(', ')}`,
    fix.remaining?.length ? `Ancora presenti dopo l'aggiornamento: ${fix.remaining.map((i) => `\`${i}\``).join(', ')}` : '',
    '',
    fix.forced ? '⚠️ Età minima saltata con `force` (dispatch manuale); provenienza verificata comunque.' : `Età minima rispettata; provenienza: ${fix.kind === 'image' ? 'Docker Official Image, stesso tag' : 'stesso repository sorgente della versione installata'}.`,
    '', 'Auto-merge dopo i check della merge queue. Se un check fallisce, il giro successivo chiude la PR ed esclude la correzione (issue "Autofix sicurezza: correzione fallita").',
    '', '_PR aperta da `security-autofix.yml`._', '', MARKER(fixKey(fix)),
  ].filter((l, i, a) => l !== '' || a[i - 1] !== '').join('\n');
}

export function renderIssue(kind, items, { excluded = [] } = {}) {
  const head = {
    pending: 'Correzioni disponibili ma pubblicate da meno dell\'età minima: il giro giornaliero le applica quando la superano (oppure dispatch con `force`).',
    unfixable: 'Vulnerabilità che l\'autofix non può correggere da solo: serve un intervento (major, sostituzione, eccezione motivata in `.trivyignore.yaml` / `config.audit.ignore`).',
    failed: 'Correzioni la cui PR non ha passato i check: escluse dai giri successivi. **Per riprovare, togliere la riga (e il suo marcatore) da questa issue.**',
  }[kind];
  const rows = items.map((i) => `| ${cell(i.pkg)} | ${cell(i.from ?? '')} | ${cell(i.version ?? '')} | ${cell((i.ids ?? []).join(' '))} | ${cell(i.reason ?? i.pr ?? '')} |`);
  return [
    head, '', '| pacchetto | da | a | vulnerabilità | motivo |', '|---|---|---|---|---|', ...rows, '',
    ...excluded.map(EXCLUDED_MARKER), '', '_Issue aggiornata da `security-autofix.yml`._',
  ].join('\n');
}

// --- CLI ------------------------------------------------------------------------------------

function readJson(p) { return JSON.parse(readFileSync(p, 'utf8')); }

async function dockerHubTag(name, tag) {
  // Solo Docker Official Images (namespace library): nessun altro editore accettato.
  if (!/^[a-z0-9][a-z0-9._-]*$/.test(name) || !/^[A-Za-z0-9._-]+$/.test(tag)) throw new Error(`immagine non ufficiale o nome non valido: ${name}:${tag}`);
  const res = await fetch(`https://hub.docker.com/v2/namespaces/library/repositories/${name}/tags/${tag}`);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const j = await res.json();
  // Fonte di verità del digest: il registry (quello che il build scarica); da Hub solo la data.
  const tok = await (await fetch(`https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/${name}:pull`)).json();
  const head = await fetch(`https://registry-1.docker.io/v2/library/${name}/manifests/${tag}`, { method: 'HEAD', headers: {
    authorization: `Bearer ${tok.token}`,
    accept: 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json',
  } });
  if (!head.ok) throw new Error(`registry HTTP ${head.status}`);
  const digest = head.headers.get('docker-content-digest');
  if (digest !== j.digest) throw new Error(`digest incoerente tra registry (${digest}) e Docker Hub (${j.digest}): tag appena ripubblicato?`);
  return { digest, lastPushed: j.tag_last_pushed };
}

function trivyIds(ref) {
  const out = execFileSync('trivy', ['image', '--quiet', '--skip-db-update', '--scanners', 'vuln', '--severity', 'CRITICAL,HIGH',
    '--ignore-unfixed', '--format', 'json', ref], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  return new Set(parseTrivy(JSON.parse(out)).map((f) => f.id));
}

async function analyze(a) {
  const config = loadConfig();
  const excluded = a.excluded && existsSync(a.excluded) ? (readJson(a.excluded).excluded ?? []) : [];
  const opts = { excluded, force: a.force ?? '', minAgeHours: config.minAgeHours };
  const composer = await planComposer({ ...opts, audit: readJson(a.audit), lock: readJson(a.lock), versions: (p) => fetchP2(p) });
  const imageFindings = {};
  for (const spec of a['trivy-image'] ?? []) {
    const at = spec.indexOf('=');
    imageFindings[spec.slice(0, at)] = parseTrivy(readJson(spec.slice(at + 1)));
  }
  const dockerfiles = Object.fromEntries([...new Set(Object.values(config.images).map((i) => i.dockerfile))]
    .map((d) => [d, readFileSync(d, 'utf8')]));
  const images = await planImages({ ...opts, imageFindings, config, dockerfiles, tagInfo: dockerHubTag, scanIds: async (r) => trivyIds(r) });
  const npm = a['trivy-fs'] ? planNpm(parseTrivy(readJson(a['trivy-fs']))) : [];
  const plan = {
    fixed: [...composer.fixed, ...images.fixed],
    pending: [...composer.pending, ...images.pending],
    unfixable: [...composer.unfixable, ...images.unfixable, ...npm],
    skipped: [...composer.skipped, ...images.skipped],
  };
  plan.fixed.forEach((f) => { f.branch = branchFor(f); f.body = prBody(f); });
  writeFileSync(a.out, JSON.stringify(plan, null, 2));
  console.log(`autofix: ${plan.fixed.length} correzioni, ${plan.pending.length} in attesa, ${plan.unfixable.length} non correggibili, ${plan.skipped.length} escluse`);
  for (const f of plan.fixed) console.log(`  fix ${f.pkg} ${f.from} -> ${f.version}`);
}

/** Composer nell'immagine fissata del Dockerfile, repo in sola lettura tranne composer.lock. */
function composerImage() {
  const m = readFileSync('Dockerfile', 'utf8').match(/^FROM (composer:[0-9.]+@sha256:[0-9a-f]{64})/m);
  if (!m) throw new Error('FROM composer:<versione>@sha256 non trovato nel Dockerfile');
  return m[1];
}
function runComposer(args, { home }) {
  // Stesso utente del runner (file del checkout scrivibili solo da lui). Su Windows/Docker Desktop
  // (collaudo locale) getuid non esiste e i bind mount sono di root: utente dell'immagine.
  const user = process.getuid ? ['-u', `${process.getuid()}:${process.getgid()}`] : [];
  return execFileSync('docker', ['run', '--rm', ...user, '-e', 'COMPOSER_HOME=/tmp/composer',
    '-v', `${process.cwd()}:/app:ro`, '-v', `${process.cwd()}/composer.lock:/app/composer.lock`,
    '-v', `${home}:/tmp/composer`, '-w', '/app', composerImage(), ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

async function apply(a) {
  const plan = readJson(a.plan);
  const fix = plan.fixed[Number(a.index)];
  if (!fix) throw new Error(`correzione ${a.index} assente dal piano`);
  const result = { ...fix, ok: false };
  try {
    if (fix.kind === 'composer') {
      if (!isPackageName(fix.pkg) || !parseVersion(fix.version)) throw new Error('pacchetto/versione non validi');
      const base = ['update', fix.pkg, '--with', `${fix.pkg}:${fix.version}`, '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress'];
      try { runComposer(base, { home: a.home }); } catch {
        // Dipendenze del pacchetto da aggiornare insieme: secondo tentativo con -w.
        runComposer([...base, '--with-dependencies'], { home: a.home });
      }
      const got = lockVersions(readJson('composer.lock')).get(fix.pkg)?.version?.replace(/^v/i, '');
      if (got !== fix.version) throw new Error(`composer.lock ha ${got ?? 'nessuna versione'} invece di ${fix.version}`);
      let audit;
      try { audit = runComposer(['audit', '--no-dev', '--locked', '--format=json'], { home: a.home }); } catch (e) { audit = e.stdout; }
      const still = parseComposerAudit(JSON.parse(audit)).filter((f) => f.pkg === fix.pkg && fix.ids.includes(f.id));
      if (still.length) throw new Error(`advisory ancora presenti: ${still.map((f) => f.id).join(', ')}`);
    } else {
      writeFileSync(fix.dockerfile, replaceFromDigest(readFileSync(fix.dockerfile, 'utf8'),
        { name: fix.pkg.split(':')[0], tag: fix.pkg.split(':')[1], from: fix.from, to: fix.version }));
    }
    result.ok = true;
  } catch (e) {
    result.error = String(e.stderr || e.message).trim().split('\n').slice(-5).join(' ');
  }
  writeFileSync(a.out, JSON.stringify(result, null, 2));
  console.log(`apply ${fix.pkg} ${fix.version}: ${result.ok ? 'ok' : `fallita (${result.error})`}`);
  if (!result.ok) process.exitCode = 1;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  const [cmd, ...rest] = process.argv.slice(2);
  const { values } = parseArgs({ args: rest, options: {
    audit: { type: 'string' }, lock: { type: 'string' }, 'trivy-fs': { type: 'string' },
    'trivy-image': { type: 'string', multiple: true }, excluded: { type: 'string' }, force: { type: 'string' },
    out: { type: 'string' }, plan: { type: 'string' }, index: { type: 'string' }, home: { type: 'string', default: '/tmp/composer-home' },
  } });
  const fn = { analyze, apply }[cmd];
  if (!fn) { console.error('::error::uso: autofix.mjs analyze|apply ...'); process.exit(2); }
  fn(values).catch((e) => { console.error(`::error::autofix ${cmd}: ${e.message}`); process.exit(1); });
}
