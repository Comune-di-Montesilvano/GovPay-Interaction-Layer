#!/bin/sh
# Script che viene eseguito prima di docker compose up per generare i metadata SP
set -e

# In container usa path assoluto, altrimenti calcola da script location
if [ -d "/metadata/sp" ]; then
    METADATA_SP_DIR="/metadata/sp"
else
    SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
    PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
    METADATA_SP_DIR="${PROJECT_ROOT}/.local/frontoffice-sp-metadata"
fi
if [ -d "/var/www/html/spid-certs" ]; then
    SPID_CERTS_DIR="/var/www/html/spid-certs"
else
    SPID_CERTS_DIR="${PROJECT_ROOT}/.local/spid-certs"
fi
FRONTOFFICE_PUBLIC_BASE_URL="${FRONTOFFICE_PUBLIC_BASE_URL:-https://127.0.0.1:8444}"
FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS="${FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS:-365}"
FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS="${FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS:-604800}"
DEFAULT_SP_CERT_PATH="${SPID_CERTS_DIR}/cert.pem"
DEFAULT_SP_KEY_PATH="${SPID_CERTS_DIR}/privkey.pem"
FRONTOFFICE_SAML_SP_X509CERT="${FRONTOFFICE_SAML_SP_X509CERT:-$DEFAULT_SP_CERT_PATH}"
FRONTOFFICE_SAML_SP_PRIVATEKEY="${FRONTOFFICE_SAML_SP_PRIVATEKEY:-$DEFAULT_SP_KEY_PATH}"
METADATA_BASENAME="frontoffice_sp.xml"
FORCE_OVERWRITE=0
MODE_NEW=0

while [ "$#" -gt 0 ]; do
    case "$1" in
        --new)
            MODE_NEW=1
            ;;
        --force)
            FORCE_OVERWRITE=1
            ;;
        --output)
            shift
            if [ -n "$1" ]; then
                METADATA_BASENAME="$1"
            fi
            ;;
    esac
    shift
done

if [ "$MODE_NEW" -eq 1 ]; then
    METADATA_BASENAME="frontoffice_sp-new.xml"
fi

# Se il proxy non è SAML2 non c'è nulla da generare
AUTH_PROXY_TYPE="${FRONTOFFICE_AUTH_PROXY_TYPE:-auth-proxy-saml2}"
if [ "$AUTH_PROXY_TYPE" != "auth-proxy-saml2" ]; then
    echo "[INFO] Auth proxy type '$AUTH_PROXY_TYPE': generazione metadata SP non necessaria."
    exit 0
fi

METADATA_FILE="${METADATA_SP_DIR}/${METADATA_BASENAME}"

metadata_is_expired() {
    _file="$1"
    if [ ! -f "$_file" ]; then
        return 0
    fi

    php -r '
        $f = $argv[1];
        $baseUrl = rtrim($argv[2], "/");
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_file($f);
        if ($xml === false) {
            exit(2);
        }
        $attrs = $xml->attributes();
        $validUntil = isset($attrs["validUntil"]) ? (string)$attrs["validUntil"] : "";
        if ($validUntil === "") {
            exit(2);
        }
        $validTs = strtotime($validUntil);
        if ($validTs === false) {
            exit(2);
        }
        if ($validTs <= time()) {
            exit(0);
        }

        $entityId = isset($xml['entityID']) ? rtrim((string)$xml['entityID'], '/') : '';
        $expectedEntityId = $baseUrl !== '' ? ($baseUrl . '/saml/sp') : '';
        if ($expectedEntityId !== '' && $entityId !== $expectedEntityId) {
            exit(0);
        }

        $namespaces = $xml->getDocNamespaces(true);
        $mdNs = $namespaces['md'] ?? 'urn:oasis:names:tc:SAML:2.0:metadata';
        $acsNodes = $xml->xpath('//*[local-name()="AssertionConsumerService"]');
        if (!$acsNodes || !isset($acsNodes[0])) {
            exit(2);
        }
        $acsAttrs = $acsNodes[0]->attributes();
        $acsLocation = isset($acsAttrs['Location']) ? rtrim((string)$acsAttrs['Location'], '/') : '';
        $expectedAcs = $baseUrl !== '' ? ($baseUrl . '/spid/callback') : '';
        if ($expectedAcs !== '' && $acsLocation !== $expectedAcs) {
            exit(0);
        }

        exit(1);
    ' "$_file" "$FRONTOFFICE_PUBLIC_BASE_URL"
    _status=$?

    # 0=expired, 1=valid, 2=parse error => treat as expired to force regeneration
    if [ "$_status" -eq 1 ]; then
        return 1
    fi
    return 0
}

if [ "$MODE_NEW" -eq 1 ] && [ -f "$METADATA_FILE" ]; then
    TS="$(date +%Y%m%d%H%M%S)"
    METADATA_FILE="${METADATA_SP_DIR}/frontoffice_sp-new-${TS}.xml"
fi

# Crea la directory se non esiste
mkdir -p "$METADATA_SP_DIR"

is_inline_pem() {
    echo "$1" | grep -q "BEGIN "
}

normalize_cert_path() {
    local value="$1"
    if is_inline_pem "$value"; then
        echo "$value"
        return
    fi
    case "$value" in
        /var/www/html/metadata-sp/*)
            echo "${METADATA_SP_DIR}/${value#/var/www/html/metadata-sp/}"
            ;;
        /var/www/html/spid-certs/*)
            echo "${SPID_CERTS_DIR}/${value#/var/www/html/spid-certs/}"
            ;;
        /metadata/sp/*)
            echo "${METADATA_SP_DIR}/${value#/metadata/sp/}"
            ;;
        *)
            echo "$value"
            ;;
    esac
}

FRONTOFFICE_SAML_SP_X509CERT="$(normalize_cert_path "$FRONTOFFICE_SAML_SP_X509CERT")"
FRONTOFFICE_SAML_SP_PRIVATEKEY="$(normalize_cert_path "$FRONTOFFICE_SAML_SP_PRIVATEKEY")"

export FRONTOFFICE_SAML_SP_X509CERT
export FRONTOFFICE_SAML_SP_PRIVATEKEY
export FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS
export FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS

# Se il file esiste già, non rigenerare salvo che sia scaduto
if [ -f "$METADATA_FILE" ] && [ "$FORCE_OVERWRITE" -ne 1 ]; then
    if metadata_is_expired "$METADATA_FILE"; then
        echo "[WARN] Metadata SP presenti ma scaduti/corrotti: rigenerazione automatica"
    else
        echo "[INFO] Metadata SP già presente"
        exit 0
    fi
fi

echo "[INFO] Generando metadata SP per: $FRONTOFFICE_PUBLIC_BASE_URL"

# Genera i metadata usando uno script inline (scrivi su file temp poi esegui)
cat > /tmp/generate-metadata.php << 'PHPEOF'
<?php
use OneLogin\Saml2\Settings;

// Carica l'autoloader di Composer se disponibile
$autoloaderPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../frontoffice/vendor/autoload.php',
    '/var/www/html/vendor/autoload.php',
];

foreach ($autoloaderPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

// Se OneLogin non è disponibile, lo script non può generare i metadata
if (!class_exists('OneLogin\Saml2\Settings')) {
    // Fallback: crea un metadata minimo senza firma
    $frontofficeBaseUrl = isset($argv[1]) ? $argv[1] : 'https://127.0.0.1:8444';
    $spEntityId = rtrim($frontofficeBaseUrl, '/') . '/saml/sp';
    $acsUrl = rtrim($frontofficeBaseUrl, '/') . '/spid/callback';
    $sloUrl = rtrim($frontofficeBaseUrl, '/') . '/logout';
    $orgName = getenv('APP_ENTITY_NAME') ?: 'GovPay';
    $orgSuffix = getenv('APP_ENTITY_SUFFIX') ?: '';
    $orgDisplay = trim($orgName . ($orgSuffix !== '' ? ' - ' . $orgSuffix : '')) ?: $orgName;
    $orgUrl = getenv('APP_ENTITY_URL') ?: rtrim($frontofficeBaseUrl, '/');
    $supportEmail = getenv('APP_SUPPORT_EMAIL');
    if (!$supportEmail) {
        $domain = preg_replace('/[^a-z0-9]+/', '', strtolower($orgName)) ?: 'ente';
        $supportEmail = 'support@' . $domain . '.it';
    }
    $supportPhone = getenv('APP_SUPPORT_PHONE') ?: '+390000000000';
    $fiscalCode = getenv('ID_DOMINIO') ?: '00000000000';
    $ipaCode = getenv('APP_ENTITY_IPA_CODE') ?: 'c_x000';
    
    $validityDays = (int)(getenv('FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS') ?: '365');
    if ($validityDays < 1) {
        $validityDays = 1;
    }
    $cacheSeconds = (int)(getenv('FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS') ?: '604800');
    if ($cacheSeconds < 60) {
        $cacheSeconds = 60;
    }
    $validUntil = gmdate('Y-m-d\TH:i:s\Z', time() + ($validityDays * 86400));

    $metadata = <<<XML
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
                     validUntil="$validUntil"
                     cacheDuration="PT{$cacheSeconds}S"
                     entityID="$spEntityId">
    <md:SPSSODescriptor AuthnRequestsSigned="true" WantAssertionsSigned="true" protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                Location="$sloUrl" />
        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>
        <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
                         Location="$acsUrl"
                         index="0"
                         isDefault="true" />
        <md:AttributeConsumingService index="0">
            <md:ServiceName xml:lang="it">$orgDisplay</md:ServiceName>
            <md:RequestedAttribute Name="spidCode" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic" isRequired="true"/>
            <md:RequestedAttribute Name="name" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic" isRequired="true"/>
            <md:RequestedAttribute Name="familyName" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic" isRequired="true"/>
            <md:RequestedAttribute Name="fiscalNumber" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic" isRequired="true"/>
            <md:RequestedAttribute Name="email" NameFormat="urn:oasis:names:tc:SAML:2.0:attrname-format:basic" isRequired="true"/>
        </md:AttributeConsumingService>
    </md:SPSSODescriptor>
    <md:Organization>
        <md:OrganizationName xml:lang="it">$orgName</md:OrganizationName>
        <md:OrganizationName xml:lang="en">$orgName</md:OrganizationName>
        <md:OrganizationDisplayName xml:lang="it">$orgDisplay</md:OrganizationDisplayName>
        <md:OrganizationDisplayName xml:lang="en">$orgDisplay</md:OrganizationDisplayName>
        <md:OrganizationURL xml:lang="it">$orgUrl</md:OrganizationURL>
        <md:OrganizationURL xml:lang="en">$orgUrl</md:OrganizationURL>
    </md:Organization>
    <md:ContactPerson contactType="other">
        <md:Extensions>
            <spid:IPACode>$ipaCode</spid:IPACode>
            <spid:Public/>
            <spid:FiscalCode>$fiscalCode</spid:FiscalCode>
        </md:Extensions>
        <md:EmailAddress>$supportEmail</md:EmailAddress>
        <md:TelephoneNumber>$supportPhone</md:TelephoneNumber>
    </md:ContactPerson>
</md:EntityDescriptor>
XML;
    
    echo $metadata;
    exit(0);
}

$frontofficeBaseUrl = isset($argv[1]) ? $argv[1] : 'https://127.0.0.1:8444';
$spEntityId = rtrim($frontofficeBaseUrl, '/') . '/saml/sp';
$acsUrl = rtrim($frontofficeBaseUrl, '/') . '/spid/callback';
$validityDays = (int)(getenv('FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS') ?: '365');
if ($validityDays < 1) {
    $validityDays = 1;
}
$cacheSeconds = (int)(getenv('FRONTOFFICE_SAML_SP_METADATA_CACHE_DURATION_SECONDS') ?: '604800');
if ($cacheSeconds < 60) {
    $cacheSeconds = 60;
}
$validUntil = gmdate('Y-m-d\\TH:i:s\\Z', time() + ($validityDays * 86400));
$validUntilTs = time() + ($validityDays * 86400);

$orgName = getenv('APP_ENTITY_NAME') ?: 'GovPay';
$orgSuffix = getenv('APP_ENTITY_SUFFIX') ?: '';
$orgDisplay = trim($orgName . ($orgSuffix !== '' ? ' - ' . $orgSuffix : '')) ?: $orgName;
$orgUrl = getenv('APP_ENTITY_URL') ?: rtrim($frontofficeBaseUrl, '/');
$supportEmail = getenv('APP_SUPPORT_EMAIL');
if (!$supportEmail) {
    $domain = preg_replace('/[^a-z0-9]+/', '', strtolower($orgName)) ?: 'ente';
    $supportEmail = 'support@' . $domain . '.it';
}
$supportPhone = getenv('APP_SUPPORT_PHONE') ?: '+390000000000';
$fiscalCode = getenv('ID_DOMINIO') ?: '00000000000';

$ipaCode = getenv('APP_ENTITY_IPA_CODE');
if (!$ipaCode) {
    $ipaCode = getenv('SATOSA_CONTACT_PERSON_IPA_CODE');
}
if (!$ipaCode) {
    $ipaCode = 'c_x000';
}

$spCert = getenv('FRONTOFFICE_SAML_SP_X509CERT') ?: '';
$spKey = getenv('FRONTOFFICE_SAML_SP_PRIVATEKEY') ?: '';

$loadPem = static function (string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (str_contains($trimmed, 'BEGIN ')) {
        return $trimmed;
    }
    if (is_file($trimmed)) {
        $content = @file_get_contents($trimmed);
        return $content !== false ? trim($content) : '';
    }
    return $trimmed;
};

$spCert = $loadPem($spCert);
$spKey = $loadPem($spKey);
$signMetadata = ($spCert !== '' && $spKey !== '');

if ($signMetadata) {
    $pkey = @openssl_pkey_get_private($spKey);
    if ($pkey === false) {
        fwrite(STDERR, "Invalid SP private key: unable to load private key\n");
        exit(1);
    }
    if (@openssl_x509_parse($spCert) === false) {
        fwrite(STDERR, "Invalid SP certificate: unable to parse X509\n");
        exit(1);
    }
}

$authnRequestsSigned = $signMetadata;

$settings = [
    'strict' => false,
    'debug' => false,
    'sp' => [
        'entityId' => $spEntityId,
        'assertionConsumerService' => [
            'url' => $acsUrl,
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
            'index' => 0,
            'isDefault' => true,
        ],
        'singleLogoutService' => [
            'url' => rtrim($frontofficeBaseUrl, '/') . '/logout',
            'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
        ],
        'NameIDFormat' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
        'x509cert' => $spCert,
        'privateKey' => $spKey,
        'attributeConsumingService' => [
            'serviceName' => 'Set 0',
            'serviceDescription' => 'Set 0',
            'requestedAttributes' => [
                ['name' => 'gender', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'companyName', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'registeredOffice', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'fiscalNumber', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'ivaCode', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'idCard', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'spidCode', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'name', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'familyName', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'placeOfBirth', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'countyOfBirth', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'dateOfBirth', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'mobilePhone', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'email', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'address', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'expirationDate', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
                ['name' => 'digitalAddress', 'nameFormat' => 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic', 'isRequired' => false],
            ],
        ],
    ],
    'idp' => [
        'entityId' => 'http://placeholder',
        'singleSignOnService' => ['url' => 'http://placeholder'],
        'x509cert' => 'placeholder',
    ],
    'organization' => [
        'en' => [
            'name' => $orgName,
            'displayname' => $orgDisplay,
            'url' => $orgUrl,
        ],
        'it' => [
            'name' => $orgName,
            'displayname' => $orgDisplay,
            'url' => $orgUrl,
        ],
    ],
    'contactPerson' => [
        'other' => [
            'givenName' => $orgName,
            'emailAddress' => $supportEmail,
        ],
    ],
    'security' => [
        'authnRequestsSigned' => $authnRequestsSigned,
        'wantAssertionsSigned' => true,
        'wantMessagesSigned' => true,
        'wantNameId' => false,
        'wantNameIdEncrypted' => false,
        'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'signMetadata' => $signMetadata,
        'signMetadataAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
    ],
];

try {
    $settingsObj = new Settings($settings);
    $spData = $settingsObj->getSPData();
    $securityData = $settingsObj->getSecurityData();
    $contacts = $settingsObj->getContacts();
    $organization = $settingsObj->getOrganization();
    $metadata = \OneLogin\Saml2\Metadata::builder(
        $spData,
        (bool)($securityData['authnRequestsSigned'] ?? false),
        (bool)($securityData['wantAssertionsSigned'] ?? false),
        $validUntilTs,
        $cacheSeconds,
        $contacts,
        $organization
    );
    if (strpos($metadata, 'xmlns:spid=') === false) {
        $metadata = preg_replace('/<md:EntityDescriptor\b/', '<md:EntityDescriptor xmlns:spid="https://spid.gov.it/saml-extensions"', $metadata, 1);
    }
    $metadata = preg_replace('/(<md:ContactPerson[^>]*contactType="other"[^>]*>)/', '$1' . "\n        <md:Extensions>\n            <spid:IPACode>{$ipaCode}</spid:IPACode>\n            <spid:Public />\n            <spid:FiscalCode>{$fiscalCode}</spid:FiscalCode>\n        </md:Extensions>", $metadata, 1);
    
    // Replace telephone if valid
    if ($supportPhone && $supportPhone !== '+390000000000') {
         $metadata = preg_replace('/<\/md:ContactPerson>/', "\n        <md:TelephoneNumber>{$supportPhone}</md:TelephoneNumber>\n    </md:ContactPerson>", $metadata, 1);
    }
    
    $metadata = preg_replace('/<md:AssertionConsumerService([^>]*?)index=\"\d+\"([^>]*?)\/>/', '<md:AssertionConsumerService$1index="0"$2 isDefault="true" />' . "\n        " . '<md:AssertionConsumerService$1index="1"$2 />' . "\n        " . '<md:AssertionConsumerService$1index="2"$2 />', $metadata);
    $metadata = preg_replace('/<md:AttributeConsumingService index=\"\d+\">/', '<md:AttributeConsumingService index="0">', $metadata);

    // Append sets 1 and 2
    $sets1and2 = <<<XML
        <md:AttributeConsumingService index="1">
            <md:ServiceName xml:lang="it">Set 1</md:ServiceName>       
            <md:RequestedAttribute Name="fiscalNumber"/>
            <md:RequestedAttribute Name="spidCode"/>
        </md:AttributeConsumingService>
        <md:AttributeConsumingService index="2">
            <md:ServiceName xml:lang="it">Set 2</md:ServiceName>       
            <md:RequestedAttribute Name="gender"/>
            <md:RequestedAttribute Name="companyName"/>
            <md:RequestedAttribute Name="registeredOffice"/>
            <md:RequestedAttribute Name="fiscalNumber"/>
            <md:RequestedAttribute Name="ivaCode"/>
            <md:RequestedAttribute Name="idCard"/>
            <md:RequestedAttribute Name="spidCode"/>
            <md:RequestedAttribute Name="name"/>
            <md:RequestedAttribute Name="familyName"/>
            <md:RequestedAttribute Name="placeOfBirth"/>
            <md:RequestedAttribute Name="countyOfBirth"/>
            <md:RequestedAttribute Name="dateOfBirth"/>
            <md:RequestedAttribute Name="mobilePhone"/>
            <md:RequestedAttribute Name="email"/>
            <md:RequestedAttribute Name="address"/>
            <md:RequestedAttribute Name="expirationDate"/>
            <md:RequestedAttribute Name="digitalAddress"/>
        </md:AttributeConsumingService>
XML;
    $metadata = preg_replace('/(<\/md:AttributeConsumingService>)/', "$1\n$sets1and2", $metadata, 1);
    if ($signMetadata) {
        $metadata = \OneLogin\Saml2\Metadata::addX509KeyDescriptors($metadata, $spCert, false);
        $metadata = \OneLogin\Saml2\Metadata::signMetadata(
            $metadata,
            $spKey,
            $spCert,
            $securityData['signMetadataAlgorithm'] ?? null,
            $securityData['digestAlgorithm'] ?? null
        );
    }
    $errors = $settingsObj->validateMetadata($metadata);
    
    if (!empty($errors)) {
        fwrite(STDERR, "Metadata validation errors: " . implode(", ", $errors) . "\n");
        exit(1);
    }
    
    echo $metadata;
} catch (\Exception $e) {
    fwrite(STDERR, "ERROR generating metadata: " . $e->getMessage() . "\n");
    exit(1);
}
PHPEOF

php /tmp/generate-metadata.php "$FRONTOFFICE_PUBLIC_BASE_URL" > "$METADATA_FILE"

if [ $? -ne 0 ]; then
    echo "[ERROR] Fallito il caricamento dei metadata SP"
    rm -f "$METADATA_FILE"
    exit 1
fi

# Crea anche la versione senza estensione .xml
cp "$METADATA_FILE" "${METADATA_FILE%.xml}"

echo "[OK] Metadata SP generati con successo"
echo "[INFO] Metadata file: $METADATA_FILE"
