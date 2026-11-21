#!/usr/bin/env node
// Gate differenziale: fallisce solo per vulnerabilità presenti nella testa e assenti nella base
// (stesso run, stesso DB vulnerabilità). Composer: tutte le severità + abbandonati (come
// `composer audit` assoluto); Trivy: HIGH/CRITICAL con fix.
//
//   node scripts/security/diff-gate.mjs --format composer|trivy --head h.json (--base b.json | --base-empty)
//
// Exit: 0 nessun nuovo · 1 nuovi (tabella su stdout) · 2 input mancante/non valido.
// Nei workflow lo script viene preso dalla BASE quando esiste: una PR non può indebolire il gate
// che la giudica.
import { readFileSync } from 'node:fs';
import { parseArgs } from 'node:util';
import { parseComposerAudit, parseTrivy, diffFindings } from './findings.mjs';

function fail(msg) {
  console.error(`::error::diff-gate: ${msg}`);
  process.exit(2);
}

let args;
try {
  ({ values: args } = parseArgs({ options: {
    format: { type: 'string' }, head: { type: 'string' }, base: { type: 'string' },
    'base-empty': { type: 'boolean', default: false },
  } }));
} catch (e) { fail(e.message); }

if (!['composer', 'trivy'].includes(args.format)) fail('--format composer|trivy obbligatorio');
if (!args.head) fail('--head obbligatorio');
if (!args.base && !args['base-empty']) fail('--base o --base-empty obbligatorio');

function load(path, label) {
  let json;
  try { json = JSON.parse(readFileSync(path, 'utf8')); } catch (e) { fail(`${label} (${path}): ${e.message}`); }
  try {
    return args.format === 'composer' ? parseComposerAudit(json) : parseTrivy(json);
  } catch (e) { fail(`${label} (${path}): ${e.message}`); }
}

const head = load(args.head, 'testa');
const base = args['base-empty'] ? [] : load(args.base, 'base');
const nuovi = diffFindings(head, base);

console.log(`diff-gate (${args.format}): testa ${head.length}, base ${base.length}, nuovi ${nuovi.length}`);
if (nuovi.length === 0) process.exit(0);

console.log('| severità | ecosistema | pacchetto | versione | id | corretta in | target |');
console.log('|---|---|---|---|---|---|---|');
for (const f of nuovi) {
  const fix = f.fixedVersions.join(', ') || (f.vulnerableRange ? `fuori da ${f.vulnerableRange}` : '');
  console.log(`| ${f.severity} | ${f.ecosystem} | ${f.pkg} | ${f.version} | ${[f.id, ...f.aliases].join(' ')} | ${fix} | ${f.target} |`);
}
console.error(`::error::${nuovi.length} vulnerabilità nuove rispetto alla base`);
process.exit(1);
