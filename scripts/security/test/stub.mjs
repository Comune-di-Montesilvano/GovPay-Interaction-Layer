// Helper dei test degli script shell: comandi finti (docker, gh, curl) in una cartella messa in
// testa al PATH. Ogni stub registra gli argomenti in $STUB_LOG e risponde secondo variabili
// d'ambiente STUB_* del singolo test: niente rete, niente docker vero, niente file del repo.
import { mkdtempSync, writeFileSync, chmodSync, readFileSync, existsSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, delimiter } from 'node:path';
import { spawnSync } from 'node:child_process';

export function tempDir(prefix = 'gil-test-') {
  return mkdtempSync(join(tmpdir(), prefix));
}

/** Crea gli stub (nome → corpo sh) e ritorna { bin, log, env }. */
export function makeStubs(stubs) {
  const dir = tempDir('stubs-');
  const log = join(dir, 'calls.log');
  writeFileSync(log, '');
  for (const [name, body] of Object.entries(stubs)) {
    const p = join(dir, name);
    writeFileSync(p, `#!/usr/bin/env bash\nprintf '%s %s\\n' ${name} "$*" >> "$STUB_LOG"\n${body}\n`);
    chmodSync(p, 0o755);
  }
  return { bin: dir, log, calls: () => readFileSync(log, 'utf8').split('\n').filter(Boolean) };
}

/** Esegue uno script bash con gli stub in testa al PATH. */
export function runScript(script, args, { stubs, env = {}, cwd } = {}) {
  const r = spawnSync('bash', [script, ...args], {
    cwd, encoding: 'utf8',
    // PATH del test (es. systemBin) se indicato, altrimenti quello del processo; stub sempre in testa.
    env: { ...process.env, ...env, STUB_LOG: stubs.log, PATH: `${stubs.bin}${delimiter}${env.PATH ?? process.env.PATH}` },
  });
  return { status: r.status, stdout: r.stdout, stderr: r.stderr };
}

/** Repo git temporaneo con `n` commit; ritorna { dir, shas } (shas[0] il più vecchio). */
export function gitRepo(n) {
  const dir = tempDir('repo-');
  const git = (...a) => {
    const r = spawnSync('git', a, { cwd: dir, encoding: 'utf8', env: { ...process.env,
      GIT_AUTHOR_NAME: 't', GIT_AUTHOR_EMAIL: 't@example.invalid', GIT_COMMITTER_NAME: 't', GIT_COMMITTER_EMAIL: 't@example.invalid' } });
    if (r.status !== 0) throw new Error(r.stderr);
    return r.stdout.trim();
  };
  git('init', '-q');
  const shas = [];
  for (let i = 0; i < n; i++) {
    writeFileSync(join(dir, 'f.txt'), String(i));
    git('add', 'f.txt');
    git('commit', '-q', '-m', `c${i}`);
    shas.push(git('rev-parse', 'HEAD'));
  }
  return { dir, shas };
}

export const readIf = (p) => (existsSync(p) ? readFileSync(p, 'utf8') : '');

/**
 * Cartella con link ai soli strumenti di sistema indicati: PATH senza comandi veri che lo
 * script potrebbe trovare (es. `gh` in /usr/bin sui runner GitHub).
 */
export function systemBin(tools) {
  const dir = tempDir('sysbin-');
  for (const t of tools) {
    const r = spawnSync('bash', ['-c', `command -v ${t}`], { encoding: 'utf8' });
    const target = r.stdout.trim();
    // Builtin (printf, echo): niente link, li fornisce la shell.
    if (r.status === 0 && target && !target.startsWith('/')) continue;
    if (r.status !== 0 || !target) throw new Error(`strumento di sistema mancante: ${t}`);
    symlinkSync(target, join(dir, t));
  }
  return dir;
}
