#!/usr/bin/env bash
# Gate sugli alert CodeQL nuovi di una PR (job `codeql-gate` di ci.yml).
#
# CodeQL (default setup) pubblica sul commit della PR il check "CodeQL" (app
# github-advanced-security), rosso se la PR introduce alert sopra la soglia del repo. Quel check
# non puo' essere required: CodeQL non gira in merge_group e la coda resterebbe in attesa per
# sempre. Questo script aspetta il check sul commit della PR e ne riporta l'esito: il job e'
# required, in merge queue viene saltato (if: pull_request) e vale verde.
#
# Uso: codeql-gate.sh <owner/repo> <sha>   (GH_TOKEN con checks: read)
# Env: GATE_TIMEOUT (s, default 1500), GATE_START_TIMEOUT (s, default 600: CodeQL mai partito ->
#      avviso e uscita 0, per non bloccare una PR su un problema di GitHub), GATE_POLL (s, 30).
set -euo pipefail

repo=${1:?owner/repo}
sha=${2:?sha}
timeout=${GATE_TIMEOUT:-1500}
start_timeout=${GATE_START_TIMEOUT:-600}
poll=${GATE_POLL:-30}
t0=$(date +%s)

while :; do
  runs=$(gh api "repos/$repo/commits/$sha/check-runs?per_page=100" \
    -q '[.check_runs[] | {name, app: .app.slug, status, conclusion, title: .output.title, summary: .output.summary}]')
  codeql=$(jq -c '[.[] | select(.name == "CodeQL" and .app == "github-advanced-security")] | .[0] // empty' <<<"$runs")
  elapsed=$(( $(date +%s) - t0 ))

  if [ -n "$codeql" ] && [ "$(jq -r .status <<<"$codeql")" = completed ]; then
    conclusion=$(jq -r .conclusion <<<"$codeql")
    echo "Check CodeQL: $conclusion — $(jq -r '.title // ""' <<<"$codeql")"
    case "$conclusion" in
      success|neutral|skipped) exit 0 ;;
      *)
        jq -r '.summary // ""' <<<"$codeql"
        echo "::error::CodeQL segnala alert nuovi in questa PR ($conclusion): correggerli o chiuderli come falsi positivi nella scheda Security"
        exit 1 ;;
    esac
  fi

  analyze=$(jq '[.[] | select(.name | startswith("Analyze ("))] | length' <<<"$runs")
  if [ -z "$codeql" ] && [ "$analyze" = 0 ] && [ "$elapsed" -ge "$start_timeout" ]; then
    echo "::warning::CodeQL non partito su $sha dopo ${start_timeout}s: gate saltato"
    exit 0
  fi
  if [ "$elapsed" -ge "$timeout" ]; then
    echo "::error::check CodeQL non concluso dopo ${timeout}s (rilanciare il job quando CodeQL ha finito)"
    exit 1
  fi
  echo "in attesa di CodeQL (${elapsed}s, analisi avviate: $analyze)"
  sleep "$poll"
done
