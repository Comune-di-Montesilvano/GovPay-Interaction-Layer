// scripts/verify-images.sh: verifica firme cosign (+ attestazioni con gh) prima del deploy.
// Container CLI e gh finti (stub.mjs): si controlla cosa viene chiesto a cosign/gh e come lo
// script reagisce ai loro esiti, non la crittografia (quella è di cosign).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { makeStubs, runScript, systemBin } from './stub.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const SCRIPT = resolve(here, '..', '..', 'verify-images.sh');
const DIGEST = `sha256:${'ab'.repeat(32)}`;
// Solo ciò che usa lo script (+ bash degli stub): nessun gh/docker/podman veri raggiungibili.
const SYS = systemBin(['env', 'sh', 'bash', 'mktemp', 'rm', 'cat', 'grep', 'head', 'cut', 'printf']);

// STUB_COSIGN_FAIL=<img> → verify fallisce; STUB_NO_DIGEST=1 → JSON senza digest;
// STUB_GH_AUTH=0 → gh non autenticato; STUB_ATT_FAIL=<tipo> → attestazione assente.
function stubs({ withGh = true } = {}) {
  const s = {
    docker: `
ref="\${@: -1}"
case "$ref" in *"$STUB_COSIGN_FAIL"*) [ -n "$STUB_COSIGN_FAIL" ] && { echo "Error: no signatures found" >&2; exit 1; };; esac
echo "Verification for $ref -- ok" >&2
if [ "$STUB_NO_DIGEST" = 1 ]; then echo '[{"critical":{}}]'; else
echo '[{"critical":{"image":{"docker-manifest-digest":"${DIGEST}"}}}]'; fi`,
  };
  if (withGh) s.gh = `
case "$1 $2" in
  "auth status") [ "\${STUB_GH_AUTH:-1}" = 1 ] || exit 1;;
  "attestation verify") case "$*" in *"$STUB_ATT_FAIL"*) [ -n "$STUB_ATT_FAIL" ] && exit 1;; esac;;
esac
exit 0`;
  return makeStubs(s);
}

// Senza gh: PATH ridotto agli stub + strumenti di sistema, in modo che un gh vero non risponda.
function run(args, { env = {}, withGh = true } = {}) {
  const st = stubs({ withGh });
  const r = runScript(SCRIPT, args, { stubs: st, env: { STUB_COSIGN_FAIL: '', STUB_ATT_FAIL: '', ...env,
    ...(withGh ? {} : { PATH: SYS }) } });
  return { ...r, calls: st.calls() };
}

test('tag obbligatorio', () => {
  const r = run([]);
  assert.notEqual(r.status, 0);
  assert.match(r.stderr, /uso: .* <tag immagine/);
});

test('tutto valido con gh → OK per le 3 immagini, digest firmato, provenance e SBOM verificati', () => {
  const r = run(['1.11.1']);
  assert.equal(r.status, 0, r.stderr);
  for (const img of ['backoffice', 'frontoffice', 'db']) {
    assert.match(r.stdout, new RegExp(`OK  ghcr\\.io/comune-di-montesilvano/govpay-interaction-layer-${img}:1\\.11\\.1@${DIGEST}`));
  }
  const att = r.calls.filter((c) => c.startsWith('gh attestation verify'));
  assert.equal(att.length, 6);
  assert.ok(att.every((c) => c.includes(`@${DIGEST}`) && c.includes('--signer-workflow Comune-di-Montesilvano/GovPay-Interaction-Layer/.github/workflows/docker-publish.yml')));
  // Tipo di predicato = argomento esatto dopo --predicate-type (non sottostringa della riga).
  const predicate = (c) => { const a = c.split(' '); return a[a.indexOf('--predicate-type') + 1]; };
  assert.deepEqual(att.map(predicate).sort(), [...Array(3).fill('https://cyclonedx.org/bom'), ...Array(3).fill('https://slsa.dev/provenance/v1')]);
});

test('cosign: identità limitata a docker-publish.yml del repo su tag v* o main, issuer GitHub', () => {
  const r = run(['latest']);
  const v = r.calls.filter((c) => c.startsWith('docker run --rm ghcr.io/sigstore/cosign/cosign:'));
  assert.equal(v.length, 3);
  for (const c of v) {
    assert.match(c, /cosign:v\d+\.\d+\.\d+@sha256:[0-9a-f]{64} verify /);
    assert.ok(c.includes('--certificate-identity-regexp ^https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer/\\.github/workflows/docker-publish\\.yml@refs/(tags/v.*|heads/main)$'), c);
    assert.ok(c.includes('--certificate-oidc-issuer https://token.actions.githubusercontent.com'));
  }
});

test('firma assente → exit 1, stderr di cosign mostrato, stop alla prima immagine', () => {
  const r = run(['1.0.0'], { env: { STUB_COSIGN_FAIL: 'frontoffice' } });
  assert.equal(r.status, 1);
  assert.match(r.stderr, /no signatures found/);
  assert.match(r.stderr, /ERRORE: firma non valida o assente per .*frontoffice:1\.0\.0/);
  assert.match(r.stdout, /OK .*backoffice/);
  assert.doesNotMatch(r.stdout, /db/);
});

test('su successo lo stderr di cosign non viene mostrato', () => {
  assert.doesNotMatch(run(['1.0.0']).stderr, /Verification for/);
});

test('digest non ricavabile dall\'output → exit 1 (mai OK senza digest)', () => {
  const r = run(['1.0.0'], { env: { STUB_NO_DIGEST: '1' } });
  assert.equal(r.status, 1);
  assert.match(r.stderr, /digest non ricavato/);
});

test('attestazione assente → exit 1', () => {
  const r = run(['1.0.0'], { env: { STUB_ATT_FAIL: 'cyclonedx' } });
  assert.equal(r.status, 1);
  assert.match(r.stderr, /attestazione https:\/\/cyclonedx\.org\/bom assente/);
});

test('gh presente ma non autenticato → avviso esplicito, solo firma cosign', () => {
  const r = run(['1.0.0'], { env: { STUB_GH_AUTH: '0' } });
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /gh presente ma non autenticato/);
  assert.equal(r.calls.filter((c) => c.startsWith('gh attestation')).length, 0);
});

test('gh assente → avviso, solo firma cosign', () => {
  const r = run(['1.0.0'], { withGh: false });
  assert.equal(r.status, 0, r.stderr);
  assert.match(r.stderr, /gh non trovato/);
});

test('CONTAINER_CLI=podman → usa podman', () => {
  const st = makeStubs({ podman: `echo '[{"critical":{"image":{"docker-manifest-digest":"${DIGEST}"}}}]'` });
  const r = runScript(SCRIPT, ['1.0.0'], { stubs: st, env: { CONTAINER_CLI: 'podman', PATH: SYS } });
  assert.equal(r.status, 0, r.stderr);
  assert.equal(st.calls().filter((c) => c.startsWith('podman run')).length, 3);
});
