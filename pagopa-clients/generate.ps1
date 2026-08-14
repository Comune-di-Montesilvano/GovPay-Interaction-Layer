$configFile = "api_config.json"
$outputDir = "generated-clients"

# Ensure output directory exists
if (-not (Test-Path $outputDir)) {
    New-Item -Path $outputDir -ItemType Directory | Out-Null
}

try {
    $content = Get-Content $configFile -Raw -ErrorAction Stop
    $apis = $content | ConvertFrom-Json
} catch {
    Write-Error "Errore nella lettura del file di configurazione $configFile : $_"
    exit 1
}

foreach ($api in $apis) {
    if (-not $api.name) {
        Write-Warning "Skipping invalid API configuration entry"
        continue
    }

    $apiName = $api.name
    $apiVersion = $api.version
    $baseUrl = $api.base_url
    $mainFile = $api.main_file
    $clientDir = $api.client_dir
    $clientNamespace = $api.client_namespace
    $packageName = $api.package_name

    $workingDir = Join-Path $outputDir "$apiName-$apiVersion"
    $bundledFile = "$apiName.bundled.json"

    Write-Host "====================================================================="
    Write-Host "INIZIO PROCESSO: $apiName ($apiVersion) - Pacchetto: $packageName"
    Write-Host "====================================================================="

    if (-not (Test-Path $workingDir)) {
        New-Item -Path $workingDir -ItemType Directory | Out-Null
    }

    $mainFilePath = Join-Path $workingDir $mainFile
    Write-Host "   > Download $mainFile da $baseUrl..."
    try {
        Invoke-WebRequest -Uri "$baseUrl/$mainFile" -OutFile $mainFilePath -ErrorAction Stop
    } catch {
        Write-Error "Errore durante il download di $mainFile : $_"
        continue
    }

    Write-Host "   > Bundling (nessuna dipendenza attesa)..."
    # Docker on Windows needs absolute paths. Resolve paths first.
    $absWorkingDir = Resolve-Path $workingDir | Select-Object -ExpandProperty Path
    # Convert backslashes to forward slashes for docker compatibility if needed, 
    # but usually Windows docker handles standard paths gracefully IF shared.
    # However, sometimes lowercase drive letters work better.
    
    # We will use standard path syntax. If it fails, check Docker Desktop file sharing settings but usually C:\Users is shared.
    
    $dockerCmd1 = "docker run --rm -v `"${absWorkingDir}:/data`" redocly/cli:latest bundle `"/data/$mainFile`" --output `"/data/$bundledFile`""
    Invoke-Expression $dockerCmd1

    Write-Host "   > Generazione Client PHP ($clientDir)..."
    $dockerCmd2 = "docker run --rm -v `"${absWorkingDir}:/local`" openapitools/openapi-generator-cli generate -i `"/local/$bundledFile`" -g php -o `"/local/$clientDir`" --invoker-package `"$clientNamespace`" --additional-properties packageName=`"$clientNamespace`""
    Invoke-Expression $dockerCmd2

    Write-Host "   > Correzione output: GuzzleHttp\Utils::jsonEncode() -> json_encode() nativo"
    $clientDirPath = Join-Path $workingDir $clientDir
    Get-ChildItem -Path $clientDirPath -Filter *.php -Recurse | ForEach-Object {
        (Get-Content $_.FullName -Raw) -replace '\\GuzzleHttp\\Utils::jsonEncode\(', 'json_encode(' | Set-Content $_.FullName -NoNewline
    }

    # Validazione output: proprietà duplicate nello spec (es. PaymentInfo.iur nel Biz Events
    # pagoPA) producono getter/setter duplicati -> PHP Fatal "Cannot redeclare" al primo
    # autoload. Meglio fallire qui che scoprirlo in produzione.
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCmd) {
        Write-Host "   > Verifica sintattica (php -l) dei file generati..."
        $lintFailed = $false
        Get-ChildItem -Path $clientDirPath -Filter *.php -Recurse | ForEach-Object {
            $lintOutput = & php -l $_.FullName 2>&1
            if ($LASTEXITCODE -ne 0) {
                Write-Host "   > ERRORE DI SINTASSI in $($_.FullName):"
                Write-Host $lintOutput
                $lintFailed = $true
            }
        }
        if ($lintFailed) {
            Write-Error "Generazione client $apiName interrotta: uno o più file generati non passano php -l. Probabile causa: proprietà duplicata nello spec OpenAPI sorgente."
            exit 1
        }
    } else {
        Write-Host "   > 'php' non trovato in PATH, salto la verifica sintattica dei file generati."
    }

    $composerFile = Join-Path $workingDir "$clientDir\composer.json"
    if (Test-Path $composerFile) {
        Write-Host "   > Correzione composer.json: iniezione name/autoload"
        $jsonContent = Get-Content $composerFile -Raw | ConvertFrom-Json
        
        # Add 'name' property
        if (-not $jsonContent.PSObject.Properties.Match('name').Count) {
             $jsonContent | Add-Member -MemberType NoteProperty -Name "name" -Value $packageName -Force
        } else {
             $jsonContent.name = $packageName
        }

        # Add PSR-4 namespace to autoload
        $namespaceKey = "$clientNamespace\"
        if (-not $jsonContent.autoload) {
            $jsonContent | Add-Member -MemberType NoteProperty -Name "autoload" -Value @{}
        }
        if (-not $jsonContent.autoload.'psr-4') {
            $jsonContent.autoload | Add-Member -MemberType NoteProperty -Name "psr-4" -Value @{}
        }
        # In PowerShell object notation, adding a dynamic property name is tricky.
        # We convert to hashtable for easier manipulation if needed, or use Add-Member on the inner object.
        # But wait, ConvertFrom-Json returns PSCustomObject.
        # We can add member to .autoload.'psr-4'.
        
        try {
            $jsonContent.autoload.'psr-4' | Add-Member -MemberType NoteProperty -Name $namespaceKey -Value "lib/" -Force -ErrorAction SilentlyContinue
        } catch {
            # Maybe it already exists or type mismatch? usually Force overwrites.
        }

        $jsonContent | ConvertTo-Json -Depth 10 | Set-Content $composerFile
    } else {
        Write-Host "   > ATTENZIONE: composer.json non trovato in $composerFile"
    }

    Write-Host "OK: client $apiName generato in $workingDir\$clientDir"
}

Write-Host "TUTTI I CLIENT PAGOpa GENERATI"
