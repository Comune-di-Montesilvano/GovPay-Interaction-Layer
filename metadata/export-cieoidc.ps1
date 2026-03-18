[CmdletBinding()]
param(
    [switch]$FromPublic,
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir
$OutputDir = Join-Path $ScriptDir 'cieoidc'

$EnvFiles = @(
    (Join-Path $ProjectRoot '.env'),
    (Join-Path $ProjectRoot '.iam-proxy.env')
)
foreach ($EnvFile in $EnvFiles) {
    if (-not (Test-Path $EnvFile)) { continue }
    Get-Content $EnvFile | ForEach-Object {
        $line = $_.Trim() -replace "`r", ''
        if ($line -match '^\s*#' -or $line -eq '') { return }
        if ($line -match '^([^=]+)=(.*)$') {
            $key = $Matches[1].Trim()
            $value = $Matches[2].Trim() -replace '^"(.*)"$', '$1' -replace "^'(.*)'$", '$1'
            [System.Environment]::SetEnvironmentVariable($key, $value, 'Process')
        }
    }
}

function Get-Env {
    param([string]$Key, [string]$Default = '')
    $val = [System.Environment]::GetEnvironmentVariable($Key)
    if ($null -ne $val -and $val -ne '') { return $val }
    return $Default
}

function Decode-JwtPayloadJson {
    param([Parameter(Mandatory = $true)][string]$Jwt)

    $parts = $Jwt.Split('.')
    if ($parts.Length -lt 2) { throw 'Entity statement non valido: JWT malformato' }

    $payload = $parts[1].Replace('-', '+').Replace('_', '/')
    switch ($payload.Length % 4) {
        2 { $payload += '==' }
        3 { $payload += '=' }
    }

    $bytes = [Convert]::FromBase64String($payload)
    return [System.Text.Encoding]::UTF8.GetString($bytes)
}

function Convert-HttpContentToString {
    param([Parameter(Mandatory = $true)]$Content)

    if ($Content -is [byte[]]) {
        return [System.Text.Encoding]::UTF8.GetString($Content)
    }
    return [string]$Content
}

$httpPort = Get-Env -Key 'IAM_PROXY_HTTP_PORT' -Default '9445'
$publicBase = Get-Env -Key 'IAM_PROXY_PUBLIC_BASE_URL' -Default ''
$clientId = Get-Env -Key 'CIE_OIDC_CLIENT_ID' -Default ''

if ($clientId -eq '') {
    if ($publicBase -ne '') {
        $clientId = ("{0}/CieOidcRp" -f $publicBase.TrimEnd('/'))
    }
    else {
        $clientId = "http://127.0.0.1:$httpPort/CieOidcRp"
    }
}

$componentIdentifier = "http://127.0.0.1:$httpPort/CieOidcRp"
if ($FromPublic) {
    $componentIdentifier = $clientId.TrimEnd('/')
}

$publicComponentIdentifier = $clientId.TrimEnd('/')
$publicEntityConfigUrl = "$publicComponentIdentifier/.well-known/openid-federation"
$publicJwksRpJsonUrl = "$publicComponentIdentifier/openid_relying_party/jwks.json"
$publicJwksRpJoseUrl = "$publicComponentIdentifier/openid_relying_party/jwks.jose"

$entityConfigUrl = "$componentIdentifier/.well-known/openid-federation"
$jwksRpJsonUrl = "$componentIdentifier/openid_relying_party/jwks.json"
$jwksRpJoseUrl = "$componentIdentifier/openid_relying_party/jwks.jose"

$resolveEndpoint = Get-Env -Key 'CIE_OIDC_FEDERATION_RESOLVE_ENDPOINT' -Default "$componentIdentifier/resolve"
$fetchEndpoint = Get-Env -Key 'CIE_OIDC_FEDERATION_FETCH_ENDPOINT' -Default "$componentIdentifier/fetch"
$trustMarkStatusEndpoint = Get-Env -Key 'CIE_OIDC_FEDERATION_TRUST_MARK_STATUS_ENDPOINT' -Default "$componentIdentifier/trust_mark_status"
$listEndpoint = Get-Env -Key 'CIE_OIDC_FEDERATION_LIST_ENDPOINT' -Default "$componentIdentifier/list"

New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null

# ---------------------------------------------------------------------------
# Guard: export esistente e non scaduto -> rifiuta senza -Force
# ---------------------------------------------------------------------------
$prevComponentValues = Join-Path $OutputDir 'component-values.env'
if ((Test-Path $prevComponentValues) -and -not $Force) {
    $prevExpEpoch = 0
    $prevExpUtc   = ''
    $prevDays     = ''

    Get-Content $prevComponentValues | ForEach-Object {
        if ($_ -match '^ENTITY_STATEMENT_EXP_EPOCH=(.+)$')         { $prevExpEpoch = [int64]$Matches[1].Trim() }
        if ($_ -match '^ENTITY_STATEMENT_EXP_UTC=(.+)$')           { $prevExpUtc   = $Matches[1].Trim() }
        if ($_ -match '^ENTITY_STATEMENT_EXP_DAYS_REMAINING=(.+)$') { $prevDays     = $Matches[1].Trim() }
    }

    if ($prevExpEpoch -gt 0) {
        $nowEpoch = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        if ($prevExpEpoch -gt $nowEpoch) {
            Write-Error @"
[ERROR] Export CIE OIDC gia' presente e non scaduto.
        Scadenza: $prevExpUtc ($prevDays giorni residui)

        Le chiavi federate NON devono cambiare finche' l'Entity Statement e' valido.
        Usa -Force solo se stai rinnovando consapevolmente la federazione
        (dopo aver rigenerato le chiavi con setup-cie-oidc.ps1 -Force).
"@
            exit 1
        }
    }
}

Write-Host "[INFO] Export CIE OIDC metadata da: $componentIdentifier"

$tmpEntityJwt = Join-Path $env:TEMP 'govpay-cieoidc-entity.jwt'
$tmpJwksRpJson = Join-Path $env:TEMP 'govpay-cieoidc-jwks-rp.json'
$tmpJwksRpJose = Join-Path $env:TEMP 'govpay-cieoidc-jwks-rp.jose'

Invoke-WebRequest -UseBasicParsing -Uri $entityConfigUrl -TimeoutSec 20 -OutFile $tmpEntityJwt | Out-Null
$entityJwt = Get-Content -Path $tmpEntityJwt -Raw -Encoding UTF8
if ([string]::IsNullOrWhiteSpace($entityJwt)) {
    throw "Entity Configuration vuota: $entityConfigUrl"
}
$entityJson = Decode-JwtPayloadJson -Jwt $entityJwt
$entityObj = $entityJson | ConvertFrom-Json

$jwksFedKeys = $entityObj.jwks.keys
if ($null -eq $jwksFedKeys -or $jwksFedKeys.Count -lt 1) {
    throw 'Nessuna chiave federation pubblica trovata nel campo jwks.keys'
}

Invoke-WebRequest -UseBasicParsing -Uri $jwksRpJsonUrl -TimeoutSec 20 -OutFile $tmpJwksRpJson | Out-Null

Invoke-WebRequest -UseBasicParsing -Uri $jwksRpJoseUrl -TimeoutSec 20 -OutFile $tmpJwksRpJose | Out-Null

$entityJwtFile = Join-Path $OutputDir 'entity-configuration.jwt'
$entityJsonFile = Join-Path $OutputDir 'entity-configuration.json'
$jwksFedFile = Join-Path $OutputDir 'jwks-federation-public.json'
$jwksRpJsonFile = Join-Path $OutputDir 'jwks-rp.json'
$jwksRpJoseFile = Join-Path $OutputDir 'jwks-rp.jose'
$componentValuesFile = Join-Path $OutputDir 'component-values.env'

Set-Content -Path $entityJwtFile -Value $entityJwt -Encoding UTF8
Set-Content -Path $entityJsonFile -Value ($entityObj | ConvertTo-Json -Depth 30) -Encoding UTF8
Set-Content -Path $jwksFedFile -Value ([pscustomobject]@{ keys = $jwksFedKeys } | ConvertTo-Json -Depth 10) -Encoding UTF8
Set-Content -Path $jwksRpJsonFile -Value (Get-Content -Path $tmpJwksRpJson -Raw -Encoding UTF8) -Encoding UTF8
Set-Content -Path $jwksRpJoseFile -Value (Get-Content -Path $tmpJwksRpJose -Raw -Encoding UTF8) -Encoding UTF8

$expEpoch = 0
$expIso = ''
$daysRemaining = ''
if ($null -ne $entityObj.exp) {
    $expEpoch = [int64]$entityObj.exp
    $expDate = [DateTimeOffset]::FromUnixTimeSeconds($expEpoch).ToUniversalTime()
    $expIso = $expDate.ToString('yyyy-MM-ddTHH:mm:ssZ')
    $daysRemaining = [math]::Floor(($expDate - [DateTimeOffset]::UtcNow).TotalDays)
}

$componentValues = @(
    "COMPONENT_IDENTIFIER=$componentIdentifier"
    "PUBLIC_COMPONENT_IDENTIFIER=$publicComponentIdentifier"
    "ENTITY_CONFIG_URL=$entityConfigUrl"
    "PUBLIC_ENTITY_CONFIG_URL=$publicEntityConfigUrl"
    "FEDERATION_RESOLVE_ENDPOINT=$resolveEndpoint"
    "FEDERATION_FETCH_ENDPOINT=$fetchEndpoint"
    "FEDERATION_TRUST_MARK_STATUS_ENDPOINT=$trustMarkStatusEndpoint"
    "FEDERATION_LIST_ENDPOINT=$listEndpoint"
    "JWKS_FEDERATION_PUBLIC_FILE=metadata/cieoidc/jwks-federation-public.json"
    "JWKS_RP_JSON_URL=$jwksRpJsonUrl"
    "JWKS_RP_JOSE_URL=$jwksRpJoseUrl"
    "PUBLIC_JWKS_RP_JSON_URL=$publicJwksRpJsonUrl"
    "PUBLIC_JWKS_RP_JOSE_URL=$publicJwksRpJoseUrl"
    "ENTITY_STATEMENT_EXP_EPOCH=$expEpoch"
    "ENTITY_STATEMENT_EXP_UTC=$expIso"
    "ENTITY_STATEMENT_EXP_DAYS_REMAINING=$daysRemaining"
)
Set-Content -Path $componentValuesFile -Value ($componentValues -join "`n") -Encoding UTF8

Write-Host '[OK] Export CIE OIDC completato:'
Write-Host "  - $entityJwtFile"
Write-Host "  - $entityJsonFile"
Write-Host "  - $jwksFedFile"
Write-Host "  - $jwksRpJsonFile"
Write-Host "  - $jwksRpJoseFile"
Write-Host "  - $componentValuesFile"
if ($expIso -ne '') {
    Write-Host "[INFO] Entity Statement exp: $expIso ($daysRemaining giorni residui)"
}
Write-Host ''
Write-Host '========================================================'
Write-Host '  Per il portale CIE OIDC usare:'
Write-Host "    File JWT : $entityJwtFile"
Write-Host "    JWKS fed : $jwksFedFile"
Write-Host "    Entity ID: $publicComponentIdentifier"
Write-Host '  IMPORTANTE: nel form del portale inserire l''Entity ID'
Write-Host '  nel campo "sub" / "Identificativo Soggetto".'
Write-Host '========================================================'
