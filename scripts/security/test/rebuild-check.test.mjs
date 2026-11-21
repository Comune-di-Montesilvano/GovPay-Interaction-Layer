// scripts/ci/rebuild-check.sh: decide se il rebuild giornaliero di latest ha una causa reale.
// Docker e curl finti (stub.mjs), report Trivy come copie fisse in un tmp, repo git temporaneo.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makeStubs, runScript, gitRepo, tempDir, readIf } from './stub.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const SCRIPT = resolve(here, '..', '..', 'ci', 'rebuild-check.sh');

// Variabili per immagine: STUB_PULL_FAIL="db", STUB_REV_<img>, STUB_APT_<img> ("errore" = exit 3).
const stubs = makeStubs({
  docker: `
cmd=$1; shift
ref=""; for a in "$@"; do case $a in ghcr.io/*) ref=$a;; esac; done
img=\${ref##*govpay-interaction-layer-}; img=\${img%%:*}
case $cmd in
  pull) case " $STUB_PULL_FAIL " in *" $img "*) exit 1;; esac; exit 0;;
  inspect) v="STUB_REV_$img"; echo "\${!v-<no value>}";;
  run)
    case "$*" in
      *"--entrypoint php"*) echo "8.5.1";;
      *) v="STUB_APT_$img"; val="\${!v-0}"; [ "$val" = errore ] && exit 3; echo "$val";;
    esac;;
esac`,
  curl: 'echo \'{"version":"8.5.1"}\'',
});

const CLEAN = { SchemaVersion: 2, Results: [{ Target: 'x', Vulnerabilities: [
  { VulnerabilityID: 'CVE-1', Severity: 'MEDIUM', FixedVersion: '1.1' },
  { VulnerabilityID: 'CVE-2', Severity: 'HIGH', FixedVersion: '' },
] }] };
const VULN = { SchemaVersion: 2, Results: [{ Target: 'x', Vulnerabilities: [
  { VulnerabilityID: 'CVE-3', Severity: 'CRITICAL', FixedVersion: '2.0' },
  { VulnerabilityID: 'CVE-4', Severity: 'HIGH', FixedVersion: '2.1' },
] }] };

function run({ reports = {}, env = {}, commitsAfter = 0, images = 'backoffice db' } = {}) {
  const repo = gitRepo(1 + commitsAfter);
  const rev = repo.shas[0];
  const trivy = tempDir('trivy-');
  for (const img of images.split(' ')) {
    const r = img in reports ? reports[img] : CLEAN;
    if (r !== null) writeFileSync(join(trivy, `${img}.json`), typeof r === 'string' ? r : JSON.stringify(r));
  }
  const out = join(tempDir('out-'), 'output');
  const summary = join(dirname(out), 'summary.md');
  const revs = Object.fromEntries(images.split(' ').map((i) => [`STUB_REV_${i}`, rev]));
  const res = runScript(SCRIPT, [], { stubs, cwd: repo.dir, env: {
    IMAGES: images, TRIVY_DIR: trivy, GITHUB_OUTPUT: out, GITHUB_STEP_SUMMARY: summary,
    STUB_PULL_FAIL: '', ...revs, ...env,
  } });
  return { ...res, output: readIf(out), summary: readIf(summary), rev };
}

test('nessuna causa su nessuna immagine → skip=true, reason vuota', () => {
  const r = run();
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.output, /^skip=true$/m);
  assert.match(r.output, /^reason=$/m);
  assert.match(r.summary, /Nessuna causa/);
});

test('a. commit su main dopo la revision di latest → causa', () => {
  const r = run({ commitsAfter: 2 });
  assert.match(r.output, /^skip=false$/m);
  assert.match(r.output, new RegExp(`backoffice: 2 commit su main dopo ${r.rev.slice(0, 7)}`));
});

test('a. label revision assente o non nel repo → causa (mai "nessun commit")', () => {
  const r = run({ env: { STUB_REV_db: '<no value>', STUB_REV_backoffice: 'f'.repeat(40) } });
  assert.match(r.output, /db: label revision assente/);
  assert.match(r.output, /backoffice: revision fffffff assente dal repository/);
});

test('b. pacchetti di sistema aggiornabili → causa con il numero', () => {
  const r = run({ env: { STUB_APT_db: '7' } });
  assert.match(r.output, /db: 7 pacchetti di sistema aggiornabili/);
  assert.doesNotMatch(r.output, /backoffice:/);
});

test('b. apt-get update fallito o output non numerico → causa, mai "0 aggiornamenti"', () => {
  assert.match(run({ env: { STUB_APT_db: 'errore' } }).output, /db: controllo apt fallito/);
  assert.match(run({ env: { STUB_APT_db: 'W: repo irraggiungibile' } }).output, /db: controllo apt fallito/);
});

test('c. Trivy: solo HIGH/CRITICAL con fix contano', () => {
  const r = run({ reports: { backoffice: VULN } });
  assert.match(r.output, /backoffice: 2 vulnerabilita' HIGH\/CRITICAL con fix/);
  assert.doesNotMatch(r.output, /db: .*vulnerabilita'/);
});

test('c. report Trivy assente o illeggibile → causa', () => {
  assert.match(run({ reports: { db: null } }).output, /db: report Trivy assente/);
  assert.match(run({ reports: { db: 'non json' } }).output, /db: report Trivy illeggibile/);
  assert.match(run({ reports: { db: '' } }).output, /db: report Trivy assente/);
});

test('latest non scaricabile → causa, le altre immagini valutate comunque', () => {
  const r = run({ env: { STUB_PULL_FAIL: 'db', STUB_APT_backoffice: '3' } });
  assert.match(r.output, /db: latest assente o non scaricabile/);
  assert.match(r.output, /backoffice: 3 pacchetti/);
});

test('più cause → tutte in reason, separate da "; "', () => {
  const r = run({ commitsAfter: 1, env: { STUB_APT_db: '1' } });
  const reason = r.output.match(/^reason=(.*)$/m)[1];
  assert.deepEqual(reason.split('; ').map((c) => c.split(':')[0]), ['backoffice', 'db', 'db']);
});

test('b. il controllo apt nel container fallisce su repo irraggiungibile (--error-on=any, exit 3)', () => {
  const before = stubs.calls().length;
  run({ images: 'db' });
  const apt = stubs.calls().slice(before).filter((c) => c.startsWith('docker run') && c.includes('apt-get update'));
  assert.equal(apt.length, 1);
  assert.match(apt[0], /--user 0 --entrypoint sh /);
  assert.match(apt[0], /apt-get update -qq --error-on=any >\/dev\/null 2>&1 \|\| exit 3;/);
  assert.match(apt[0], /apt list --upgradable/);
});
