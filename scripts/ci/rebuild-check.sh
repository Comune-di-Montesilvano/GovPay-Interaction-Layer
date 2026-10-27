#!/usr/bin/env bash
# Decide se il rebuild giornaliero di `latest` (docker-publish.yml, schedule) ha una causa reale.
# Per ogni immagine ghcr.io/<org>/govpay-interaction-layer-<img>:latest valuta:
#   a. commit su main dopo la label org.opencontainers.image.revision (merge di PR, compresi
#      dependabot: composer, npm, actions, digest delle immagini base fissate nei Dockerfile);
#   b. aggiornamenti di sistema disponibili (apt list --upgradable dentro l'immagine);
#   c. vulnerabilita' HIGH/CRITICAL con fix nel report Trivy dell'immagine (JSON prodotto
#      dagli step trivy-action prima di questo script, in $TRIVY_DIR/<img>.json).
# Immagine assente, senza label o senza report Trivy = causa (meglio un rebuild in piu' che un
# latest non verificato). Nessuna causa su nessuna immagine -> skip=true.
#
# Output: skip=true|false e reason=<cause separate da "; "> su $GITHUB_OUTPUT (se definito),
# riepilogo Markdown su $GITHUB_STEP_SUMMARY (se definito), altrimenti su stdout.
#
# Uso locale (dalla root del repo, con docker e git):
#   TRIVY_DIR=/tmp/trivy scripts/ci/rebuild-check.sh
set -euo pipefail

ORG="${ORG:-comune-di-montesilvano}"
IMAGES="${IMAGES:-backoffice frontoffice db}"
TRIVY_DIR="${TRIVY_DIR:-trivy-latest}"
HEAD_SHA="${HEAD_SHA:-$(git rev-parse HEAD)}"
SUMMARY="${GITHUB_STEP_SUMMARY:-/dev/stdout}"
OUTPUT="${GITHUB_OUTPUT:-/dev/null}"

causes=()
{
  echo "### Rebuild-check di \`latest\` (main @ \`${HEAD_SHA::7}\`)"
  echo
  echo "| Immagine | Revisione latest | Commit nuovi | Pacchetti aggiornabili | HIGH/CRITICAL con fix | PHP |"
  echo "|---|---|---|---|---|---|"
} >> "$SUMMARY"

for img in $IMAGES; do
  ref="ghcr.io/${ORG}/govpay-interaction-layer-${img}:latest"
  rev="-" commits="-" upgradable="-" vulns="-" php="-"

  if ! docker pull -q "$ref" >/dev/null 2>&1; then
    causes+=("${img}: latest assente o non scaricabile")
    echo "| ${img} | assente | - | - | - | - |" >> "$SUMMARY"
    continue
  fi

  # a. Commit su main dopo la revisione da cui e' stato costruito latest.
  rev=$(docker inspect -f '{{ index .Config.Labels "org.opencontainers.image.revision" }}' "$ref" 2>/dev/null || true)
  if [ -z "$rev" ] || [ "$rev" = "<no value>" ]; then
    causes+=("${img}: label revision assente")
    rev="assente"
  elif ! git cat-file -e "${rev}^{commit}" 2>/dev/null; then
    causes+=("${img}: revision ${rev::7} assente dal repository")
  else
    commits=$(git rev-list --count "${rev}..${HEAD_SHA}")
    [ "$commits" -gt 0 ] && causes+=("${img}: ${commits} commit su main dopo ${rev::7}")
  fi

  # b. Aggiornamenti di sistema (Debian per backoffice/frontoffice, Ubuntu per mariadb).
  # apt-get update fallito (mirror bloccato/irraggiungibile) = errore, mai "0 aggiornamenti".
  upgradable=$(docker run --rm --user 0 --entrypoint sh "$ref" -c \
    'apt-get update -qq >/dev/null 2>&1 || exit 3; apt list --upgradable 2>/dev/null | grep -c "\[upgradable from" || true' \
    2>/dev/null) || upgradable="errore"
  if ! [[ "$upgradable" =~ ^[0-9]+$ ]]; then
    causes+=("${img}: controllo apt fallito")
    upgradable="errore"
  elif [ "$upgradable" -gt 0 ]; then
    causes+=("${img}: ${upgradable} pacchetti di sistema aggiornabili")
  fi

  # c. Vulnerabilita' con fix disponibile nel report Trivy.
  report="${TRIVY_DIR}/${img}.json"
  if [ ! -s "$report" ]; then
    causes+=("${img}: report Trivy assente")
    vulns="n/d"
  else
    vulns=$(jq '[.Results[]?.Vulnerabilities[]? | select((.Severity == "HIGH" or .Severity == "CRITICAL") and (.FixedVersion // "") != "")] | length' "$report" 2>/dev/null) || vulns=""
    if ! [[ "$vulns" =~ ^[0-9]+$ ]]; then
      causes+=("${img}: report Trivy illeggibile")
      vulns="n/d"
    elif [ "$vulns" -gt 0 ]; then
      causes+=("${img}: ${vulns} vulnerabilita' HIGH/CRITICAL con fix")
    fi
  fi

  # Solo informativo: versione PHP nell'immagine vs ultima 8.x.y pubblicata su php.net.
  if [ "$img" != db ]; then
    php=$(docker run --rm --entrypoint php "$ref" -r 'echo PHP_VERSION;' 2>/dev/null || echo "?")
    minor="${php%.*}"
    upstream=$(curl -fsSL --retry 2 "https://www.php.net/releases/?json&version=${minor}" 2>/dev/null | jq -r '.version // empty' || true)
    if [ -n "$upstream" ] && [ "$upstream" != "$php" ]; then
      php="${php} (php.net: ${upstream})"
    fi
  fi

  echo "| ${img} | \`${rev::7}\` | ${commits} | ${upgradable} | ${vulns} | ${php} |" >> "$SUMMARY"
done

if [ "${#causes[@]}" -eq 0 ]; then
  skip=true
  reason=""
  echo -e "\nNessuna causa: rebuild saltato, \`latest\` invariato." >> "$SUMMARY"
else
  skip=false
  reason=$(printf '%s; ' "${causes[@]}")
  reason="${reason%; }"
  { echo; echo "Rebuild necessario:"; printf -- '- %s\n' "${causes[@]}"; } >> "$SUMMARY"
fi

{
  echo "skip=${skip}"
  echo "reason=${reason}"
} >> "$OUTPUT"
echo "skip=${skip} reason=${reason}"
