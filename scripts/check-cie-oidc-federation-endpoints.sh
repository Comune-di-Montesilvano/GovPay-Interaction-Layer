#!/usr/bin/env bash
set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:9445/CieOidcRp}"
SUBJECT="${2:-}"

if [ -z "$SUBJECT" ]; then
  SUBJECT="$BASE_URL"
fi

check() {
  local url="$1"
  local method="${2:-GET}"
  local data="${3:-}"
  if [ "$method" = "POST" ]; then
    curl -sS -o /tmp/cie-fed-body.txt -D /tmp/cie-fed-head.txt -X POST \
      -H "Content-Type: application/x-www-form-urlencoded" \
      --data "$data" "$url"
  else
    curl -sS -o /tmp/cie-fed-body.txt -D /tmp/cie-fed-head.txt "$url"
  fi

  local status
  local ctype
  status="$(awk 'toupper($1) ~ /^HTTP\// {code=$2} END {print code}' /tmp/cie-fed-head.txt)"
  ctype="$(awk 'tolower($1) == "content-type:" {print $2}' /tmp/cie-fed-head.txt | tr -d '\r' | tail -n 1)"

  printf '%-90s  status=%-3s content-type=%s\n' "$url" "$status" "$ctype"
}

enc_subject="$(python3 - <<'PY'
import urllib.parse, os
print(urllib.parse.quote(os.environ['SUBJECT'], safe=''))
PY
)"

check "$BASE_URL/.well-known/openid-federation"
check "$BASE_URL/resolve?sub=$enc_subject"
check "$BASE_URL/fetch?sub=$enc_subject"
check "$BASE_URL/list"
check "$BASE_URL/trust_mark_status" "POST" "id=https%3A%2F%2Fregistry.agid.gov.it%2Fopenid_relying_party%2Fpublic%2F&sub=$enc_subject"
