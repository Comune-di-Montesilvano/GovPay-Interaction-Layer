import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { compareVersions, satisfies, isStable, parseVersion } from '../composer-version.mjs';
import { expandMinified, versionsFromP2, normalizeRepoUrl, isPackageName } from '../packagist.mjs';
import {
  planComposer, planImages, planNpm, parseFrom, replaceFromDigest, failedPrs, prMarkers, parseExcluded,
  renderIssue, branchFor, prBody, MARKER, EXCLUDED_MARKER, loadConfig,
} from '../autofix.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const fxText = (n) => readFileSync(join(here, 'fixtures', n), 'utf8');
const fx = (n) => JSON.parse(fxText(n));
const NOW = new Date('2026-11-21T12:00:00Z');
const D = (c) => `sha256:${c.repeat(64)}`;

// --- versioni composer ---

test('versioni: ordine numerico, stabilità, prefisso v, branch non confrontabili', () => {
  assert.ok(compareVersions('2.12.3', '2.9.10') > 0);
  assert.ok(compareVersions('v2.0.0', '2.0.0-RC1') > 0);
  assert.ok(compareVersions('2.0.0-beta2', '2.0.0-beta.10') < 0);
  assert.equal(compareVersions('1.2', '1.2.0.0'), 0);
  assert.equal(parseVersion('dev-main'), null);
  assert.equal(parseVersion('2.x-dev'), null);
  assert.throws(() => compareVersions('dev-main', '1.0'), /non confrontabile/);
  assert.equal(isStable('3.0.0-RC1'), false);
  assert.equal(isStable('3.0.0'), true);
});

test('vincoli advisory: OR con |/||, AND con virgola o spazio', () => {
  assert.equal(satisfies('2.12.2', '<2.12.3'), true);
  assert.equal(satisfies('2.12.3', '<2.12.3'), false);
  assert.equal(satisfies('1.9.0', '>=2.0.0,<2.12.3|<1.9.1'), true);
  assert.equal(satisfies('1.9.1', '>=2.0.0,<2.12.3|<1.9.1'), false);
  assert.equal(satisfies('7.4.4', '>=7.0.0-beta.1 <7.4.5 || >=8.0,<8.0.2'), true);
  assert.equal(satisfies('8.0.2', '>=7.0.0-beta.1 <7.4.5 || >=8.0,<8.0.2'), false);
  assert.equal(satisfies('1.0.0', '!=1.0.0'), false);
  assert.throws(() => satisfies('1.0', ''), /vuoto/);
});

// --- packagist ---

test('metadati minificati: chiavi ereditate dalla versione precedente, __unset le toglie', () => {
  const e = expandMinified([{ version: '2', a: 1, b: 2 }, { version: '1', b: '__unset' }, { version: '0', c: 3 }]);
  assert.deepEqual(e, [{ version: '2', a: 1, b: 2 }, { version: '1', a: 1 }, { version: '0', a: 1, c: 3 }]);
});

test('p2 reale (copia fissa guzzlehttp/psr7): ogni versione ha data e repository', () => {
  const v = versionsFromP2(fx('packagist-psr7.json'), 'guzzlehttp/psr7');
  assert.equal(v.length, 82);
  assert.ok(v.every((x) => x.time && x.source));
  assert.ok(v.some((x) => x.version === '2.12.3'));
  assert.throws(() => versionsFromP2({ packages: {} }, 'a/b'), /nessun metadato/);
});

test('repository normalizzati: https/.git/ssh/maiuscole equivalenti', () => {
  const n = 'github.com/guzzle/psr7';
  for (const u of ['https://github.com/guzzle/psr7.git', 'https://github.com/Guzzle/PSR7', 'git@github.com:guzzle/psr7.git', 'git+https://github.com/guzzle/psr7/']) {
    assert.equal(normalizeRepoUrl(u), n, u);
  }
  assert.notEqual(normalizeRepoUrl('https://github.com/evil/psr7.git'), n);
  assert.equal(isPackageName('guzzlehttp/psr7'), true);
  assert.equal(isPackageName('../etc/passwd'), false);
  assert.equal(isPackageName('a/b?x=1'), false);
});

// --- piano composer ---

const lockWith = (version, source = 'https://github.com/guzzle/psr7.git', extra = {}) => ({
  packages: [{ name: 'guzzlehttp/psr7', version, source: { url: source, type: 'git' }, dist: { type: 'zip' }, ...extra }],
  'packages-dev': [],
});
const psr7Audit = { advisories: { 'guzzlehttp/psr7': [{ advisoryId: 'PKSA-1', packageName: 'guzzlehttp/psr7', affectedVersions: '<2.12.3', cve: 'CVE-1', sources: [] }] }, abandoned: [] };
const realVersions = async () => versionsFromP2(fx('packagist-psr7.json'), 'guzzlehttp/psr7');

test('composer: versione corretta più bassa (non la più recente), stesso repository, età ok', async () => {
  const p = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), versions: realVersions, now: NOW });
  assert.deepEqual(p.fixed.map((f) => [f.pkg, f.from, f.version]), [['guzzlehttp/psr7', '2.4.0', '2.12.3']]);
  assert.deepEqual(p.fixed[0].ids, ['PKSA-1']);
});

test('composer: tutti gli advisory del pacchetto rispettati insieme', async () => {
  const audit = structuredClone(psr7Audit);
  audit.advisories['guzzlehttp/psr7'].push({ advisoryId: 'PKSA-2', packageName: 'guzzlehttp/psr7', affectedVersions: '>=2.0,<2.13.0', sources: [] });
  const p = await planComposer({ audit, lock: lockWith('2.4.0'), versions: realVersions, now: NOW });
  assert.ok(compareVersions(p.fixed[0].version, '2.13.0') >= 0, p.fixed[0].version);
});

const versions = (list) => async () => list;
const V = (version, hoursAgo, source = 'https://github.com/guzzle/psr7.git') => ({ version, time: new Date(NOW - hoursAgo * 3600e3).toISOString(), source });

test('composer: correzione più giovane di 48 h → in attesa (mai saltata a una più alta)', async () => {
  const p = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), now: NOW, versions: versions([V('2.12.3', 10), V('2.13.0', 5)]) });
  assert.equal(p.fixed.length, 0);
  assert.match(p.pending[0].reason, /da 10 h \(minimo 48 h\)/);
  const f = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), now: NOW, force: 'guzzlehttp/psr7@2.12.3', versions: versions([V('2.12.3', 10)]) });
  assert.equal(f.fixed[0].forced, true);
});

test('composer: repository sorgente cambiato → non correggibile (force non lo salta)', async () => {
  const p = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), now: NOW, force: 'guzzlehttp/psr7@2.12.3',
    versions: versions([V('2.12.3', 100, 'https://github.com/attacker/psr7.git')]) });
  assert.equal(p.fixed.length, 0);
  assert.match(p.unfixable[0].reason, /provenienza non verificata/);
});

test('composer: niente pre-release se installata stabile; nessuna corretta → non correggibile', async () => {
  const p = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), now: NOW, versions: versions([V('2.12.3-RC1', 100), V('2.12.2', 100)]) });
  assert.match(p.unfixable[0].reason, /nessuna versione corretta/);
});

test('composer: esclusa, abbandonato, path repository, assente dal lock, dev', async () => {
  const ex = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0'), now: NOW, versions: versions([V('2.12.3', 100)]), excluded: ['guzzlehttp/psr7@2.12.3'] });
  assert.equal(ex.skipped.length, 1);
  const ab = await planComposer({ audit: { advisories: [], abandoned: { 'a/b': 'c/d' } }, lock: { packages: [] }, now: NOW, versions: versions([]) });
  assert.match(ab.unfixable[0].reason, /abbandonato, sostituto suggerito c\/d/);
  const path = await planComposer({ audit: psr7Audit, lock: lockWith('2.4.0', null, { dist: { type: 'path' } }), now: NOW, versions: versions([]) });
  assert.match(path.unfixable[0].reason, /path repository/);
  const none = await planComposer({ audit: psr7Audit, lock: { packages: [] }, now: NOW, versions: versions([]) });
  assert.match(none.unfixable[0].reason, /non trovato in composer.lock/);
  const dev = await planComposer({ audit: psr7Audit, lock: lockWith('dev-main'), now: NOW, versions: versions([]) });
  assert.match(dev.unfixable[0].reason, /non confrontabile/);
});

test('composer: errore Packagist → non correggibile, gli altri pacchetti continuano', async () => {
  const audit = { advisories: { ...psr7Audit.advisories, 'x/y': [{ advisoryId: 'PKSA-9', packageName: 'x/y', affectedVersions: '<2', sources: [] }] }, abandoned: [] };
  const lock = lockWith('2.4.0'); lock.packages.push({ name: 'x/y', version: '1.0.0', source: { url: 'https://github.com/x/y.git' } });
  const p = await planComposer({ audit, lock, now: NOW, versions: async (pkg) => { if (pkg === 'x/y') throw new Error('HTTP 503'); return [V('2.12.3', 100)]; } });
  assert.equal(p.fixed.length, 1);
  assert.match(p.unfixable[0].reason, /Packagist: HTTP 503/);
});

// --- immagini base (copie fisse delle righe FROM) ---

test('FROM: solo name:tag@sha256, stage compresi', () => {
  const php = parseFrom(fxText('Dockerfile.php'));
  assert.deepEqual(php.map((f) => f.name), ['node', 'composer', 'php']);
  assert.equal(parseFrom(fxText('Dockerfile.db'))[0].name, 'mariadb');
});

test('sostituzione digest: una sola occorrenza, digest valido', () => {
  const t = fxText('Dockerfile.db');
  const [b] = parseFrom(t);
  const out = replaceFromDigest(t, { name: 'mariadb', tag: b.tag, from: b.digest, to: D('e') });
  assert.match(out, new RegExp(`FROM mariadb:${b.tag.replace('.', '\\.')}@${D('e')}`));
  assert.throws(() => replaceFromDigest(t, { name: 'mariadb', tag: b.tag, from: D('0'), to: D('e') }), /attesa 1 occorrenza, trovate 0/);
  assert.throws(() => replaceFromDigest(t, { name: 'mariadb', tag: b.tag, from: b.digest, to: 'sha256:xyz' }), /digest non valido/);
});

const dockerfiles = { Dockerfile: fxText('Dockerfile.php'), 'docker/db/Dockerfile': fxText('Dockerfile.db') };
const config = loadConfig();
const f = (id, ecosystem = 'os') => ({ id, ecosystem, pkg: 'p' });
const pinned = (name) => parseFrom(dockerfiles[name === 'php' ? 'Dockerfile' : 'docker/db/Dockerfile']).find((x) => x.name === name).digest;

test('immagini: digest nuovo che toglie CVE della base → correzione; quelle dei nostri layer no', async () => {
  const p = await planImages({
    imageFindings: { db: [f('CVE-A', 'image-lang'), f('CVE-B'), f('CVE-OUR')], backoffice: [] },
    config, dockerfiles, now: NOW,
    tagInfo: async () => ({ digest: D('e'), lastPushed: new Date(NOW - 72 * 3600e3).toISOString() }),
    scanIds: async (ref) => (ref.endsWith(pinned('mariadb')) ? new Set(['CVE-A', 'CVE-B']) : new Set(['CVE-B'])),
  });
  assert.equal(p.fixed.length, 1);
  assert.deepEqual([p.fixed[0].pkg.split(':')[0], p.fixed[0].ids, p.fixed[0].remaining], ['mariadb', ['CVE-A'], ['CVE-B', 'CVE-OUR']]);
  assert.equal(p.fixed[0].dockerfile, 'docker/db/Dockerfile');
});

test('immagini: backoffice e frontoffice (stesso FROM php) → una sola correzione', async () => {
  const p = await planImages({
    imageFindings: { backoffice: [f('CVE-A')], frontoffice: [f('CVE-A'), f('CVE-C')] }, config, dockerfiles, now: NOW,
    tagInfo: async () => ({ digest: D('e'), lastPushed: '2026-11-01T00:00:00Z' }),
    scanIds: async (ref) => (ref.endsWith(pinned('php')) ? new Set(['CVE-A', 'CVE-C']) : new Set()),
  });
  assert.equal(p.fixed.length, 1);
  assert.deepEqual(p.fixed[0].apps, ['backoffice', 'frontoffice']);
  assert.deepEqual(p.fixed[0].ids, ['CVE-A', 'CVE-C']);
});

test('immagini: tag fermo, digest giovane, nessun miglioramento, finding composer ignorati', async () => {
  const base = { imageFindings: { db: [f('CVE-A')] }, config, dockerfiles, now: NOW, scanIds: async () => new Set(['CVE-A']) };
  const same = await planImages({ ...base, tagInfo: async () => ({ digest: pinned('mariadb'), lastPushed: '2026-11-01T00:00:00Z' }) });
  assert.match(same.unfixable[0].reason, /senza aggiornamenti/);
  const young = await planImages({ ...base, tagInfo: async () => ({ digest: D('e'), lastPushed: new Date(NOW - 3 * 3600e3).toISOString() }) });
  assert.match(young.pending[0].reason, /da 3 h/);
  const noGain = await planImages({ ...base, tagInfo: async () => ({ digest: D('e'), lastPushed: '2026-11-01T00:00:00Z' }) });
  assert.match(noGain.unfixable[0].reason, /non corregge/);
  const onlyComposer = await planImages({ ...base, imageFindings: { backoffice: [f('CVE-X', 'composer')] }, tagInfo: async () => assert.fail('non serve') });
  assert.deepEqual(onlyComposer, { fixed: [], pending: [], unfixable: [], skipped: [] });
});

test('immagini: Dockerfile senza FROM per digest → non correggibile', async () => {
  const p = await planImages({ imageFindings: { db: [f('CVE-A')] }, config, dockerfiles: { 'docker/db/Dockerfile': 'FROM mariadb:12\n' },
    now: NOW, tagInfo: async () => assert.fail(), scanIds: async () => assert.fail() });
  assert.match(p.unfixable[0].reason, /FROM mariadb:<tag>@sha256 non trovato/);
});

test('npm: solo segnalato', () => {
  assert.deepEqual(planNpm([{ ecosystem: 'npm', pkg: 'chart.js', id: 'CVE-1' }, { ecosystem: 'composer', pkg: 'a/b', id: 'CVE-2' }]).map((x) => x.pkg), ['chart.js']);
});

// --- PR, issue, marcatori ---

test('branch: solo caratteri sicuri, digest abbreviato', () => {
  assert.equal(branchFor({ kind: 'composer', pkg: 'guzzlehttp/psr7', version: '2.12.3' }), 'security-autofix/guzzlehttp-psr7-2.12.3');
  assert.equal(branchFor({ kind: 'image', pkg: 'mariadb:12.3', version: D('e') }), 'security-autofix/mariadb-12.3-eeeeeeeeeeee');
});

test('marcatori PR e issue: andata e ritorno', () => {
  const body = prBody({ kind: 'composer', pkg: 'a/b', from: '1.0.0', version: '1.0.1', ids: ['PKSA-1'], published: 'x' });
  assert.deepEqual(prMarkers(body), ['a/b@1.0.1']);
  assert.ok(body.includes(MARKER('a/b@1.0.1')));
  const issue = renderIssue('failed', [{ pkg: 'a/b', version: '1.0.1', pr: '#3' }], { excluded: ['a/b@1.0.1', 'mariadb:12.3@' + D('e')] });
  assert.deepEqual(parseExcluded(issue), ['a/b@1.0.1', 'mariadb:12.3@' + D('e')]);
  assert.ok(issue.includes(EXCLUDED_MARKER('a/b@1.0.1')));
  assert.deepEqual(parseExcluded(null), []);
});

test('issue: celle con | e a capo non rompono la tabella', () => {
  const t = renderIssue('unfixable', [{ pkg: 'a/b', ids: ['X'], reason: 'uno | due\ntre' }]);
  assert.match(t, /\| uno \\\| due tre \|/);
});

test('PR fallite: almeno un check in errore', () => {
  const prs = [
    { number: 1, statusCheckRollup: [{ conclusion: 'SUCCESS' }, { conclusion: null, state: 'PENDING' }] },
    { number: 2, statusCheckRollup: [{ conclusion: 'FAILURE' }] },
    { number: 3, statusCheckRollup: [{ state: 'ERROR' }] },
    { number: 4 },
  ];
  assert.deepEqual(failedPrs(prs).map((p) => p.number), [2, 3]);
});

test('config: allowlist ristretta a lock e Dockerfile', () => {
  assert.deepEqual(config.allowedFiles.sort(), ['Dockerfile', 'composer.lock', 'docker/db/Dockerfile']);
  assert.equal(config.minAgeHours, 48);
});
