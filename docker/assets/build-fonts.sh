#!/bin/sh
# Font self-hosted (stage asset_builder del Dockerfile): da node_modules/@fontsource genera
# $OUT/fonts.css + i woff2 usati, al posto del vecchio @import da fonts.googleapis.com (ogni
# pagina faceva contattare Google dal browser dei cittadini). Stessi pesi del vecchio import,
# solo i sottoinsiemi latin + latin-ext (le lingue del portale) e solo woff2.
# Uso: build-fonts.sh <node_modules/@fontsource> <dir di output>
set -eu
SRC=$1
OUT=$2
mkdir -p "$OUT"
: > "$OUT/fonts.css"
for spec in \
  "titillium-web:300 400 600 700 900" \
  "lora:400 600 400-italic" \
  "jetbrains-mono:400 500" \
  "figtree:400 500 600 700"; do
  pkg=${spec%%:*}
  mkdir -p "$OUT/$pkg"
  for w in ${spec#*:}; do
    for sub in latin-ext latin; do
      css="$SRC/$pkg/$sub-$w.css"
      [ -f "$css" ] || { echo "build-fonts: manca $css" >&2; exit 1; }
      # url(./files/x.woff2) -> url(./<pkg>/x.woff2), fallback woff rimosso.
      sed -E -e "s#url\(\./files/#url(./$pkg/#g" \
             -e "s#, url\([^)]*\.woff\) format\('woff'\)##g" "$css" >> "$OUT/fonts.css"
      for f in $(grep -oE "[a-z0-9-]+\.woff2" "$css"); do
        cp "$SRC/$pkg/files/$f" "$OUT/$pkg/"
      done
    done
  done
done
if grep -qE "\.woff\)|files/" "$OUT/fonts.css"; then
  echo "build-fonts: riferimenti non riscritti in fonts.css" >&2; exit 1
fi
