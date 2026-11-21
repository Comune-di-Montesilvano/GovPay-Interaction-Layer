import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { parseComposerAudit, parseTrivy, diffFindings, InvalidReportError } from '../findings.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const fx = (n) => JSON.parse(readFileSync(join(here, 'fixtures', n), 'utf8'));
const cli = join(here, '..', 'diff-gate.mjs');

const gate = (...a) => spawnSync(process.execPath, [cli, ...a], { encoding: 'utf8' });
const tmpJson = (obj) => {
  const p = join(mkdtempSync(join(tmpdir(), 'gate-')), 'r.json');
  writeFileSync(p, typeof obj === 'string' ? obj : JSON.stringify(obj));
  return p;
};

test('composer: advisory per pacchetto con CVE/GHSA come alias, abbandonati inclusi', () => {
  const f = parseComposerAudit(fx('composer-audit-head.json'));
  const psr7 = f.find((x) => x.pkg === 'guzzlehttp/psr7' && x.id === 'PKSA-vznr-tgp9-fd7d');
  assert.ok(psr7);
  assert.deepEqual(psr7.aliases, ['CVE-2026-59882', 'GHSA-c2w2-prh8-qm98']);
  assert.equal(psr7.severity, 'MEDIUM');
  assert.equal(psr7.vulnerableRange, '<2.12.3');
  assert.ok(f.some((x) => x.pkg === 'twig/twig'));
  assert.deepEqual(f.filter((x) => x.id === 'abandoned').map((x) => [x.pkg, x.fixedVersions]),
    [['swiftmailer/swiftmailer', ['symfony/mailer']]]);
});

test('composer: report vuoto (advisories/abandoned = []) → nessun finding', () => {
  assert.deepEqual(parseComposerAudit({ advisories: [], abandoned: [], filter: [] }), []);
});

test('composer: advisory singolo serializzato come oggetto {"0": ...} letto lo stesso', () => {
  const f = parseComposerAudit({ advisories: { 'a/b': { 0: { advisoryId: 'PKSA-1', packageName: 'a/b', cve: null, sources: [] } } } });
  assert.deepEqual(f.map((x) => [x.pkg, x.id, x.severity]), [['a/b', 'PKSA-1', 'UNKNOWN']]);
});

test('report rotti → InvalidReportError (mai "nessun finding")', () => {
  assert.throws(() => parseComposerAudit({}), InvalidReportError);
  assert.throws(() => parseComposerAudit(null), InvalidReportError);
  assert.throws(() => parseTrivy({ Results: [] }), InvalidReportError);
});

test('trivy: HIGH/CRITICAL con fix, ecosistema da classe/tipo', () => {
  const f = parseTrivy(fx('trivy-image-head.json'));
  assert.deepEqual([...new Set(f.map((x) => x.ecosystem))].sort(), ['image-lang', 'os']);
  const gosu = f.find((x) => x.id === 'CVE-2026-97054');
  assert.deepEqual(gosu.fixedVersions, ['1.26.11', '1.27.4']);
  assert.equal(parseTrivy({ SchemaVersion: 2, Results: [{ Class: 'lang-pkgs', Type: 'composer', Target: 'composer.lock',
    Vulnerabilities: [{ VulnerabilityID: 'CVE-1', PkgName: 'a/b', Severity: 'HIGH', FixedVersion: '1.0.1' },
      { VulnerabilityID: 'CVE-2', PkgName: 'a/b', Severity: 'MEDIUM', FixedVersion: '1.0.1' },
      { VulnerabilityID: 'CVE-3', PkgName: 'a/b', Severity: 'CRITICAL' }] }] })
    .map((x) => [x.ecosystem, x.id]).join(), 'composer,CVE-1');
});

test('diff: stesso pacchetto, id o alias in comune = già noto; ecosistema diverso = nuovo', () => {
  const b = [{ ecosystem: 'composer', pkg: 'a/b', id: 'PKSA-1', aliases: ['CVE-9'] }];
  assert.equal(diffFindings([{ ecosystem: 'composer', pkg: 'a/b', id: 'PKSA-2', aliases: ['CVE-9'] }], b).length, 0);
  assert.equal(diffFindings([{ ecosystem: 'composer', pkg: 'a/c', id: 'PKSA-1', aliases: [] }], b).length, 1);
  assert.equal(diffFindings([{ ecosystem: 'npm', pkg: 'a/b', id: 'PKSA-1', aliases: [] }], b).length, 1);
});

test('cli composer: nuovi rispetto alla base → exit 1 con tabella (twig e abbandonato)', () => {
  const r = gate('--format', 'composer', '--head', join(here, 'fixtures', 'composer-audit-head.json'),
    '--base', join(here, 'fixtures', 'composer-audit-base.json'));
  assert.equal(r.status, 1, r.stderr);
  assert.match(r.stdout, /twig\/twig/);
  assert.match(r.stdout, /swiftmailer\/swiftmailer/);
  assert.doesNotMatch(r.stdout, /guzzlehttp\/psr7/);
});

test('cli: testa uguale alla base → exit 0', () => {
  const p = join(here, 'fixtures', 'composer-audit-head.json');
  const r = gate('--format', 'composer', '--head', p, '--base', p);
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stdout, /nuovi 0/);
});

test('cli trivy: --base-empty → tutto nuovo, exit 1', () => {
  const r = gate('--format', 'trivy', '--head', join(here, 'fixtures', 'trivy-image-head.json'), '--base-empty');
  assert.equal(r.status, 1);
  assert.match(r.stdout, /nuovi 4/);
});

test('cli: input non valido → exit 2 (mai verde su un report rotto)', () => {
  const ok = join(here, 'fixtures', 'composer-audit-head.json');
  assert.equal(gate('--format', 'composer', '--head', tmpJson('non json'), '--base', ok).status, 2);
  assert.equal(gate('--format', 'composer', '--head', ok, '--base', tmpJson({})).status, 2);
  assert.equal(gate('--format', 'trivy', '--head', tmpJson({ Results: [] }), '--base-empty').status, 2);
  assert.equal(gate('--format', 'pnpm', '--head', ok, '--base', ok).status, 2);
  assert.equal(gate('--format', 'composer', '--head', ok).status, 2);
  assert.equal(gate('--format', 'composer', '--head', join(here, 'nope.json'), '--base', ok).status, 2);
});
