<#
.SYNOPSIS
    Generate SPID certificates and export SATOSA public metadata for AgID.

.DESCRIPTION
    Run once (or with -Force) before docker compose up.

.PARAMETER Force
    Regenerate even if files already exist.

.PARAMETER CertsOnly
    Generate only SPID certificates.

.PARAMETER MetadataOnly
    Export only SATOSA public metadata for AgID.
#>
[CmdletBinding()]
param(
    [switch]$Force,
    [switch]$CertsOnly,
    [switch]$MetadataOnly
)

$ErrorActionPreference = 'Stop'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ProjectRoot = Split-Path -Parent $ScriptDir

$AgidMetadataDir = Join-Path $ScriptDir 'agid'
$AgidMetadataFile = Join-Path $AgidMetadataDir 'satosa_spid_public_metadata.xml'

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
    param(
        [Parameter(Mandatory = $true)][string]$Key,
        [string]$Default = ''
    )
    $val = [System.Environment]::GetEnvironmentVariable($Key)
    if ($null -ne $val -and $val -ne '') { return $val }
    return $Default
}

$SpidCertsVolumeName = Get-Env -Key 'SPID_CERTS_DOCKER_VOLUME' -Default 'govpay_spid_certs'

$FrontofficeBaseUrl = Get-Env -Key 'FRONTOFFICE_PUBLIC_BASE_URL' -Default 'https://127.0.0.1:8444'

$CertCommonName = Get-Env -Key 'SPID_CERT_COMMON_NAME' -Default (Get-Env -Key 'APP_ENTITY_NAME' -Default 'GovPay')
$CertDays = Get-Env -Key 'SPID_CERT_DAYS' -Default '365'
$CertEntityId = Get-Env -Key 'SPID_CERT_ENTITY_ID' -Default "$FrontofficeBaseUrl/saml/sp"
$CertKeySize = Get-Env -Key 'SPID_CERT_KEY_SIZE' -Default '3072'
$CertLocality = Get-Env -Key 'SPID_CERT_LOCALITY_NAME' -Default 'Roma'
$IpaCode = Get-Env -Key 'APP_ENTITY_IPA_CODE' -Default 'c_x000'
$CertOrgId = Get-Env -Key 'SPID_CERT_ORG_ID' -Default "PA:IT-$IpaCode"
$CertOrgName = Get-Env -Key 'SPID_CERT_ORG_NAME' -Default (Get-Env -Key 'APP_ENTITY_NAME' -Default 'GovPay')

$IamProxyHttpPort = Get-Env -Key 'IAM_PROXY_HTTP_PORT' -Default '9445'

$SpidGencertScriptUnix = (Resolve-Path (Join-Path $ScriptDir 'spid-gencert-public.sh')).Path -replace '\\', '/'

New-Item -ItemType Directory -Force -Path $AgidMetadataDir | Out-Null

& docker volume create $SpidCertsVolumeName | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw "Cannot create/use Docker volume $SpidCertsVolumeName"
}

function Test-SpidCertsPresent {
    & docker run --rm -v "${SpidCertsVolumeName}:/certs" alpine:latest sh -c "test -s /certs/cert.pem && test -s /certs/privkey.pem" | Out-Null
    return ($LASTEXITCODE -eq 0)
}

function Write-PrettyXmlFile {
    param(
        [Parameter(Mandatory = $true)][string]$XmlContent,
        [Parameter(Mandatory = $true)][string]$OutputPath
    )

    try {
        $xmlDoc = New-Object System.Xml.XmlDocument
        $xmlDoc.PreserveWhitespace = $false
        $xmlDoc.LoadXml($XmlContent)

        $settings = New-Object System.Xml.XmlWriterSettings
        $settings.Indent = $true
        $settings.IndentChars = '  '
        $settings.NewLineChars = "`n"
        $settings.NewLineHandling = [System.Xml.NewLineHandling]::Replace
        $settings.OmitXmlDeclaration = $false
        $settings.Encoding = [System.Text.UTF8Encoding]::new($false)

        $writer = [System.Xml.XmlWriter]::Create($OutputPath, $settings)
        try {
            $xmlDoc.Save($writer)
        }
        finally {
            $writer.Close()
        }
    }
    catch {
        Write-Warning "Cannot pretty-format XML metadata: $($_.Exception.Message). Saving raw content."
        Set-Content -Path $OutputPath -Value $XmlContent -Encoding UTF8
    }
}

Write-Host '========================================================'
Write-Host '  GovPay Interaction Layer - Setup SP SPID'
Write-Host '========================================================'
Write-Host "  Project root:  $ProjectRoot"
Write-Host "  Certs volume:  $SpidCertsVolumeName"
Write-Host "  AgID dir:      $AgidMetadataDir"
Write-Host "  EntityID:      $FrontofficeBaseUrl/saml/sp"
Write-Host '========================================================'
Write-Host ''

if (-not $MetadataOnly) {
    $certExists = Test-SpidCertsPresent
    if ($certExists -and -not $Force) {
        Write-Host '[INFO] SPID certs already present - skip. Use -Force to regenerate.'
    }
    else {
        Write-Host '[INFO] Generating SPID certificates...'

        $dockerArgs = @(
            'run', '--rm',
            '-v', "${SpidCertsVolumeName}:/certs",
            '-v', "${SpidGencertScriptUnix}:/scripts/spid-gencert-public.sh:ro",
            '-e', "COMMON_NAME=$CertCommonName",
            '-e', "DAYS=$CertDays",
            '-e', "ENTITY_ID=$CertEntityId",
            '-e', "KEY_LEN=$CertKeySize",
            '-e', "LOCALITY_NAME=$CertLocality",
            '-e', "ORGANIZATION_IDENTIFIER=$CertOrgId",
            '-e', "ORGANIZATION_NAME=$CertOrgName",
            '-e', 'MD_ALG=sha256',
            'alpine:latest',
            'sh', '-c',
            'apk add --no-cache bash openssl curl jq >/dev/null 2>&1 && cd /certs && bash /scripts/spid-gencert-public.sh && cp /certs/crt.pem /certs/cert.pem && cp /certs/key.pem /certs/privkey.pem && chmod 644 /certs/*.pem && rm -f /certs/crt.pem /certs/key.pem /certs/csr.pem'
        )
        & docker @dockerArgs
        if ($LASTEXITCODE -ne 0) {
            throw "SPID cert generation failed (exit $LASTEXITCODE)"
        }

        Write-Host '[OK] Certificates generated:'
        Write-Host '       /certs/cert.pem (docker volume)'
        Write-Host '       /certs/privkey.pem (docker volume)'
        Write-Host ''
    }
}

if (-not $CertsOnly) {
    if (-not (Test-SpidCertsPresent)) {
        throw "SPID certs missing in Docker volume '$SpidCertsVolumeName' (/certs/cert.pem, /certs/privkey.pem). Run metadata/setup-sp.ps1 without -MetadataOnly (or with -Force) to generate them first."
    }

    Write-Host '[INFO] Sync progetto iam-proxy-italia...'
    & docker compose --profile iam-proxy run --rm sync-iam-proxy
    if ($LASTEXITCODE -ne 0) {
        throw 'sync-iam-proxy failed'
    }

    Write-Host '[INFO] Generazione/refresh metadata interno Frontoffice SP (volume Docker)...'
    & docker compose --profile iam-proxy run --rm init-frontoffice-sp-metadata
    if ($LASTEXITCODE -ne 0) {
        throw 'init-frontoffice-sp-metadata failed'
    }

    Write-Host '[INFO] Avvio SATOSA/NGINX per export metadata pubblico...'
    & docker compose --profile iam-proxy up -d --force-recreate satosa-mongo iam-proxy-italia satosa-nginx refresh-frontoffice-sp-metadata
    if ($LASTEXITCODE -ne 0) {
        throw 'IAM stack startup failed'
    }

    $maxRetries = 40
    $attempt = 0
    $metadata = $null
    $publicMetadataUrl = "http://127.0.0.1:$IamProxyHttpPort/spidSaml2/metadata"
    while ($attempt -lt $maxRetries) {
        $attempt++
        try {
            $resp = Invoke-WebRequest -UseBasicParsing -Uri $publicMetadataUrl -TimeoutSec 8
            if ($resp.StatusCode -eq 200 -and $resp.Content -match '<EntityDescriptor|<md:EntityDescriptor') {
                $metadata = $resp.Content
                break
            }
        }
        catch {
            # SATOSA/nginx may still be warming up; retry.
        }
        Start-Sleep -Seconds 3
    }

    if (-not $metadata -or $metadata -notmatch '<EntityDescriptor|<md:EntityDescriptor') {
        throw "Cannot fetch SATOSA public metadata from $publicMetadataUrl after $maxRetries attempts"
    }

    Write-PrettyXmlFile -XmlContent $metadata -OutputPath $AgidMetadataFile

    Write-Host ''
    Write-Host '[OK] Public metadata exported for AgID:'
    Write-Host "       $AgidMetadataFile"
    Write-Host ''
}

Write-Host '========================================================'
Write-Host '  Setup completed. You can now run:'
Write-Host '    docker compose up -d'
Write-Host '========================================================'
