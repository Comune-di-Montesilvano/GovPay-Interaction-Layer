param(
    [string]$BaseUrl = "http://127.0.0.1:9445/CieOidcRp",
    [string]$Subject = ""
)

if ([string]::IsNullOrWhiteSpace($Subject)) {
    $Subject = $BaseUrl
}

function Test-Endpoint {
    param(
        [string]$Url,
        [string]$Method = "GET",
        [string]$Body = ""
    )

    try {
        if ($Method -eq "POST") {
            $r = Invoke-WebRequest -UseBasicParsing -Uri $Url -Method POST -ContentType "application/x-www-form-urlencoded" -Body $Body -TimeoutSec 20
        } else {
            $r = Invoke-WebRequest -UseBasicParsing -Uri $Url -Method GET -TimeoutSec 20
        }
        "{0,-90} status={1} content-type={2}" -f $Url, $r.StatusCode, $r.Headers["Content-Type"]
    } catch {
        if ($_.Exception.Response) {
            "{0,-90} status={1} content-type={2}" -f $Url, [int]$_.Exception.Response.StatusCode.value__, $_.Exception.Response.Content.Headers.ContentType
        } else {
            "{0,-90} status=ERR content-type=n/a" -f $Url
        }
    }
}

$encSubject = [System.Uri]::EscapeDataString($Subject)
Test-Endpoint "$BaseUrl/.well-known/openid-federation"
Test-Endpoint "$BaseUrl/resolve?sub=$encSubject"
Test-Endpoint "$BaseUrl/fetch?sub=$encSubject"
Test-Endpoint "$BaseUrl/list"
Test-Endpoint "$BaseUrl/trust_mark_status" "POST" "id=https%3A%2F%2Fregistry.agid.gov.it%2Fopenid_relying_party%2Fpublic%2F&sub=$encSubject"
