#!/usr/bin/env node
// Commit delle modifiche del working tree su un branch remoto via GraphQL createCommitOnBranch:
// il commit risulta creato e verificato da GitHub per conto della GitHub App (niente chiavi di
// firma nel runner). Crea o riallinea il branch su `--base` prima del commit.
//
//   GH_TOKEN=... node scripts/security/gh-commit.mjs --repo owner/name --branch b --base <sha> \
//        --message "..." --allow <percorso> [--allow <percorso> ...]
// Stampa lo SHA del commit. Ripreso da ComunicaPA (scripts/security/gh-commit.mjs).
//
// Solo i file indicati con --allow (obbligatorio, `*` = un segmento di percorso) possono finire
// nel commit del bot: un tool di terze parti eseguito prima (composer, trivy, sed su file
// generati) che scrivesse altrove — script dei gate, workflow, sorgenti — fa fallire il commit.
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { parseArgs } from 'node:util';
import { pathToFileURL } from 'node:url';

/** Matcher dei percorsi consentiti: `*` vale un solo segmento (mai `/`). */
export function allowedMatcher(patterns) {
  if (!Array.isArray(patterns) || patterns.length === 0) throw new Error('allowlist vuota');
  const res = patterns.map((p) => new RegExp(
    '^' + p.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('[^/]*') + '$',
  ));
  return (path) => res.some((re) => re.test(path));
}

/** Da `git status --porcelain=v1 -z` alle fileChanges di createCommitOnBranch. */
export function buildFileChanges(statusZ, readFile, allowed) {
  const isAllowedPath = allowedMatcher(allowed);
  const parts = statusZ.split('\0').filter(Boolean);
  const additions = [], deletions = [], refused = [];
  const check = (p) => { if (!isAllowedPath(p)) refused.push(p); };
  for (let i = 0; i < parts.length; i++) {
    const code = parts[i].slice(0, 2), path = parts[i].slice(3);
    if (code[0] === 'R' || code[0] === 'C') {
      const from = parts[++i];
      if (code[0] === 'R' && !from.startsWith('.cache/')) { check(from); deletions.push({ path: from }); }
    }
    if (path.startsWith('.cache/')) continue;
    check(path);
    if (code.includes('D')) deletions.push({ path });
    else additions.push({ path, contents: readFile(path).toString('base64') });
  }
  if (refused.length) throw new Error(`file fuori allowlist: ${refused.join(', ')}`);
  return { additions, deletions };
}

async function gh(token, method, url, body) {
  const res = await fetch(`https://api.github.com${url}`, {
    method,
    headers: { authorization: `Bearer ${token}`, accept: 'application/vnd.github+json', 'content-type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const json = await res.json().catch(() => ({}));
  if (!res.ok || json.errors) throw new Error(`${method} ${url}: ${res.status} ${JSON.stringify(json.errors ?? json.message ?? json)}`);
  return json;
}

async function main() {
  const { values: a } = parseArgs({ options: {
    repo: { type: 'string' }, branch: { type: 'string' }, base: { type: 'string' }, message: { type: 'string' },
    allow: { type: 'string', multiple: true, default: [] },
  } });
  for (const k of ['repo', 'branch', 'base', 'message']) if (!a[k]) throw new Error(`--${k} obbligatorio`);
  if (!/^[0-9a-f]{40}$/.test(a.base)) throw new Error('--base deve essere uno SHA completo');
  const token = process.env.GH_TOKEN;
  if (!token) throw new Error('GH_TOKEN mancante');
  const status = execFileSync('git', ['status', '--porcelain=v1', '-z', '--untracked-files=all'], { encoding: 'utf8' });
  const fileChanges = buildFileChanges(status, (p) => readFileSync(p), a.allow);
  if (!fileChanges.additions.length && !fileChanges.deletions.length) throw new Error('nessuna modifica da committare');

  // Branch sul commit base (creato o riallineato con force: è un branch del solo bot).
  try {
    await gh(token, 'POST', `/repos/${a.repo}/git/refs`, { ref: `refs/heads/${a.branch}`, sha: a.base });
  } catch {
    await gh(token, 'PATCH', `/repos/${a.repo}/git/refs/heads/${a.branch}`, { sha: a.base, force: true });
  }
  const [headline, ...rest] = a.message.split('\n');
  const q = 'mutation($input: CreateCommitOnBranchInput!) { createCommitOnBranch(input: $input) { commit { oid } } }';
  const r = await gh(token, 'POST', '/graphql', { query: q, variables: { input: {
    branch: { repositoryNameWithOwner: a.repo, branchName: a.branch },
    expectedHeadOid: a.base,
    message: { headline, body: rest.join('\n').trim() },
    fileChanges,
  } } });
  console.log(r.data.createCommitOnBranch.commit.oid);
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
  main().catch((e) => { console.error(`::error::gh-commit: ${e.message}`); process.exit(1); });
}
