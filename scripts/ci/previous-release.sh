#!/usr/bin/env bash
# scripts/ci/previous-release.sh [tag-corrente] — stampa la versione (senza `v`) della release
# GitHub non pre-release piu' recente diversa dal tag corrente: punto di partenza del test di
# aggiornamento (job `upgrade` di docker-publish.yml). Senza tag (rebuild di latest) = l'ultima.
# Nessuna release utilizzabile → exit 1. Richiede `gh` con GH_TOKEN (contents: read).
set -euo pipefail

current=${1:-}
tags=$(gh release list --exclude-pre-releases --exclude-drafts --limit 20 --json tagName --jq '.[].tagName')
while IFS= read -r tag; do
  [ -n "$tag" ] || continue
  [ "$tag" = "$current" ] && continue
  if [[ "$tag" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)$ ]]; then
    echo "${BASH_REMATCH[1]}"
    exit 0
  fi
done <<<"$tags"
echo "::error::nessuna release precedente a '${current:-latest}' tra le ultime 20" >&2
exit 1
