import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildFileChanges, allowedMatcher } from '../gh-commit.mjs';

const read = (p) => Buffer.from(`contenuto di ${p}`);
const ALLOW = ['composer.json', 'composer.lock', 'Dockerfile', 'docker/db/Dockerfile', '.github/workflows/*.yml'];

test('modificati e aggiunti → additions base64, cancellati → deletions', () => {
  const status = ' M composer.lock\0 M composer.json\0?? Dockerfile\0 D docker/db/Dockerfile\0';
  assert.deepEqual(buildFileChanges(status, read, ALLOW), {
    additions: [
      { path: 'composer.lock', contents: Buffer.from('contenuto di composer.lock').toString('base64') },
      { path: 'composer.json', contents: Buffer.from('contenuto di composer.json').toString('base64') },
      { path: 'Dockerfile', contents: Buffer.from('contenuto di Dockerfile').toString('base64') },
    ],
    deletions: [{ path: 'docker/db/Dockerfile' }],
  });
});

test('rinomina (porcelain -z: R nuovo\\0vecchio) → addition + deletion', () => {
  const r = buildFileChanges('R  docker/db/Dockerfile\0Dockerfile\0', read, ALLOW);
  assert.deepEqual(r.additions.map((x) => x.path), ['docker/db/Dockerfile']);
  assert.deepEqual(r.deletions, [{ path: 'Dockerfile' }]);
});

test('file sotto .cache/ ignorati (cache di trivy e dei tool)', () => {
  const r = buildFileChanges('?? .cache/trivy/db.json\0 M composer.lock\0', read, ALLOW);
  assert.deepEqual(r.additions.map((x) => x.path), ['composer.lock']);
});

test('nessuna modifica → liste vuote', () => assert.deepEqual(buildFileChanges('', read, ALLOW), { additions: [], deletions: [] }));

test('file fuori allowlist → errore con tutti i percorsi (mai committati dal bot)', () => {
  assert.throws(() => buildFileChanges(' M scripts/security/diff-gate.mjs\0 M composer.lock\0 M app/Logger.php\0', read, ALLOW),
    /fuori allowlist: scripts\/security\/diff-gate\.mjs, app\/Logger\.php/);
  assert.throws(() => buildFileChanges(' D backoffice/src/routes/web.php\0', read, ALLOW), /fuori allowlist/);
});

test('rinomina da un file non consentito → errore anche sul vecchio percorso', () => {
  assert.throws(() => buildFileChanges('R  composer.lock\0scripts/ci/rebuild-check.sh\0', read, ALLOW), /scripts\/ci\/rebuild-check\.sh/);
});

test('allowlist: `*` vale un solo segmento, caratteri regex letterali', () => {
  const m = allowedMatcher(['.github/workflows/*.yml']);
  assert.equal(m('.github/workflows/ci.yml'), true);
  assert.equal(m('.github/workflows/sub/ci.yml'), false);
  assert.equal(m('.github/workflowsXci.yml'), false);
  assert.equal(allowedMatcher(['composer.lock'])('composerXlock'), false);
});

test('allowlist vuota o assente → errore (mai "tutto consentito")', () => {
  assert.throws(() => buildFileChanges(' M composer.lock\0', read, []), /allowlist vuota/);
  assert.throws(() => buildFileChanges(' M composer.lock\0', read, undefined), /allowlist vuota/);
});
