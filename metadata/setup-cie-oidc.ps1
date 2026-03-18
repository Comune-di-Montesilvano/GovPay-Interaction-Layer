<#
.SYNOPSIS
    Genera le chiavi JWK RSA per CIE OIDC e le salva in metadata\cieoidc-keys\.

.DESCRIPTION
    Da eseguire UNA VOLTA (o con -Force) PRIMA di scripts\sync-iam-proxy-italia.sh.

    Le chiavi generate sono:
      metadata\cieoidc-keys\jwk-federation.json  chiave firma federation
      metadata\cieoidc-keys\jwk-core-sig.json    chiave firma core
      metadata\cieoidc-keys\jwk-core-enc.json    chiave encryption core (RSA-OAEP)
      metadata\cieoidc-keys\GENERATED_AT         timestamp generazione (file lock)

    ATTENZIONE: una volta federati su CIE OIDC, NON rigenerare le chiavi finche'
    l'Entity Statement non e' scaduto. Rigenerare rompe la federazione.

.PARAMETER Force
    Rigenera anche se le chiavi esistono.

.PARAMETER IKnowWhatIAmDoing
    Richiesto insieme a -Force se l'export federazione esiste e non e' scaduto.

.EXAMPLE
    .\metadata\setup-cie-oidc.ps1
    .\metadata\setup-cie-oidc.ps1 -Force -IKnowWhatIAmDoing
#>
[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$IKnowWhatIAmDoing
)

$ErrorActionPreference = 'Stop'

$ScriptDir   = Split-Path -Parent $MyInvocation.MyCommand.Path
$KeysDir     = Join-Path $ScriptDir 'cieoidc-keys'
$LockFile    = Join-Path $KeysDir 'GENERATED_AT'
$ExportCheck = Join-Path $ScriptDir 'cieoidc\component-values.env'

Write-Host "========================================================"
Write-Host "  GovPay Interaction Layer - Setup chiavi CIE OIDC"
Write-Host "========================================================"
Write-Host "  Keys dir:  $KeysDir"
Write-Host "========================================================"
Write-Host ""

# ---------------------------------------------------------------------------
# Guard: chiavi gia' presenti
# ---------------------------------------------------------------------------
if ((Test-Path $LockFile) -and -not $Force) {
    $generatedAt = (Get-Content $LockFile -Raw).Trim()
    Write-Host "[INFO] Chiavi CIE OIDC gia' presenti (generate il $generatedAt) -- skip."
    Write-Host "       Usa -Force per rigenerare (solo se sai cosa stai facendo)."
    exit 0
}

# ---------------------------------------------------------------------------
# Guard: chiavi gia' federate
# ---------------------------------------------------------------------------
if ($Force -and (Test-Path $ExportCheck)) {
    $expEpoch = 0
    $expUtc   = ''
    $days     = ''

    Get-Content $ExportCheck | ForEach-Object {
        if ($_ -match '^ENTITY_STATEMENT_EXP_EPOCH=(.+)$')         { $expEpoch = [int64]$Matches[1].Trim() }
        if ($_ -match '^ENTITY_STATEMENT_EXP_UTC=(.+)$')           { $expUtc   = $Matches[1].Trim() }
        if ($_ -match '^ENTITY_STATEMENT_EXP_DAYS_REMAINING=(.+)$') { $days     = $Matches[1].Trim() }
    }

    $notExpired = $false
    if ($expEpoch -gt 0) {
        $nowEpoch = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        if ($expEpoch -gt $nowEpoch) { $notExpired = $true }
    }

    if ($notExpired -and -not $IKnowWhatIAmDoing) {
        Write-Error @"
[ERROR] Le chiavi CIE OIDC risultano FEDERATE e l'Entity Statement non e' scaduto.
        Scadenza: $expUtc ($days giorni residui)

        Rigenerare le chiavi ORA rompera' la federazione CIE OIDC.
        Se sei consapevole delle conseguenze e hai un piano di rinnovo,
        usa: -Force -IKnowWhatIAmDoing
"@
        exit 1
    }
}

# ---------------------------------------------------------------------------
# Generazione chiavi JWK tramite Docker (python:3-slim, senza dipendenze host)
# ---------------------------------------------------------------------------
Write-Host "[INFO] Generazione chiavi JWK CIE OIDC (RSA 2048-bit, via Docker)..."
New-Item -ItemType Directory -Force -Path $KeysDir | Out-Null

$pythonScript = @'
import sys
import json
import base64
import hashlib
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.backends import default_backend

OUTPUT_DIR = Path(sys.argv[1])

def int_to_b64url(n):
    length = (n.bit_length() + 7) // 8
    return base64.urlsafe_b64encode(n.to_bytes(length, "big")).rstrip(b"=").decode()

def rfc7638_kid(e_b64, n_b64):
    canonical = json.dumps({"e": e_b64, "kty": "RSA", "n": n_b64},
                           separators=(",", ":"), sort_keys=True)
    digest = hashlib.sha256(canonical.encode()).digest()
    return base64.urlsafe_b64encode(digest).rstrip(b"=").decode()

def gen_rsa_jwk(extra_fields=None):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048, backend=default_backend())
    priv = key.private_numbers()
    pub = priv.public_numbers
    e_b64 = int_to_b64url(pub.e)
    n_b64 = int_to_b64url(pub.n)
    jwk = {
        "kty": "RSA",
        "kid": rfc7638_kid(e_b64, n_b64),
        "e": e_b64,
        "n": n_b64,
        "d": int_to_b64url(priv.d),
        "p": int_to_b64url(priv.p),
        "q": int_to_b64url(priv.q),
    }
    if extra_fields:
        jwk = {**extra_fields, **jwk}
    return jwk

jwk_fed = gen_rsa_jwk()
(OUTPUT_DIR / "jwk-federation.json").write_text(json.dumps(jwk_fed, indent=2), encoding="utf-8")
print(f"[OK] jwk-federation.json  kid={jwk_fed['kid']}")

jwk_sig = gen_rsa_jwk({"use": "sig"})
(OUTPUT_DIR / "jwk-core-sig.json").write_text(json.dumps(jwk_sig, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-sig.json    kid={jwk_sig['kid']}")

jwk_enc = gen_rsa_jwk({"use": "enc", "alg": "RSA-OAEP"})
(OUTPUT_DIR / "jwk-core-enc.json").write_text(json.dumps(jwk_enc, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-enc.json    kid={jwk_enc['kid']}")
'@

# Pipe lo script Python via stdin (-i): evita problemi di mount path su Windows.
# Docker Desktop gestisce il mount del volume Windows nativo direttamente.
$pythonScript | docker run --rm -i `
    -v "${KeysDir}:/keys" `
    python:3-slim `
    sh -c 'pip install cryptography --quiet && python3 - /keys'

if ($LASTEXITCODE -ne 0) {
    throw "[ERROR] Generazione chiavi JWK fallita (docker run exit $LASTEXITCODE)"
}

# Scrivi il file lock
$generatedNow = [DateTime]::UtcNow.ToString('yyyy-MM-ddTHH:mm:ssZ')
Set-Content -Path $LockFile -Value $generatedNow -Encoding UTF8

Write-Host ""
Write-Host "[OK] Chiavi CIE OIDC generate e salvate in: $KeysDir"
Write-Host ""
Write-Host "========================================================"
Write-Host "  IMPORTANTE - Le chiavi sono ora bloccate."
Write-Host "  NON eseguire -Force dopo la federazione CIE OIDC."
Write-Host "  Prossimi step:"
Write-Host "    1. bash scripts/sync-iam-proxy-italia.sh"
Write-Host "    2. docker compose up -d"
Write-Host "    3. .\metadata\export-cieoidc.ps1"
Write-Host "       (esporta per onboarding al portale CIE OIDC)"
Write-Host "========================================================"
