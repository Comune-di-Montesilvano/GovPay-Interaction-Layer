# metadata/

Questa cartella contiene gli script per:

- generare manualmente il metadata pubblico SATOSA da inviare ad AgID
- gestire i certificati SPID-compliant in un volume Docker dedicato

## Struttura

```
metadata/
  setup-sp.sh        ← script da eseguire prima di docker compose up
  setup-sp.ps1       ← equivalente PowerShell (Windows)
  agid/              ← output metadata pubblico SATOSA per AgID (gitignored)
```

## Utilizzo

### Prima installazione

**Windows (PowerShell):**
```powershell
# 1. Copia e configura le variabili d'ambiente
Copy-Item .env.example .env
# (edita .env: FRONTOFFICE_PUBLIC_BASE_URL, APP_ENTITY_*, SPID_CERT_*, ecc.)

# 2. Genera certificati e metadata pubblico AgID
.\metadata\setup-sp.ps1

# 3. Avvia i servizi
docker compose up -d
```

**Linux / macOS / WSL / Git Bash:**
```bash
cp .env.example .env
bash metadata/setup-sp.sh
docker compose up -d
```

### Rinnovo certificati (es. alla scadenza)

```powershell
.\metadata\setup-sp.ps1 -Force
docker compose up -d --force-recreate iam-proxy-italia
```

### Solo certificati

```powershell
.\metadata\setup-sp.ps1 -CertsOnly
```

### Solo metadata SP (dopo aver già il cert)

```powershell
.\metadata\setup-sp.ps1 -MetadataOnly
```

## Distinzione fondamentale

- `metadata/agid/satosa_spid_public_metadata.xml`: metadata pubblico SATOSA (`/spidSaml2/metadata`) da inviare ad AgID.
- Metadata interno Frontoffice SP: non viene salvato nella cartella del progetto; viene gestito automaticamente in un volume Docker interno (`frontoffice_sp_metadata`) durante l'avvio del profilo `iam-proxy`.
- Certificati SPID: non vengono salvati nella cartella del progetto; sono nel volume Docker `govpay_spid_certs` (o nel volume indicato da `SPID_CERTS_DOCKER_VOLUME`).

## Variabili rilevanti in `.env`

| Variabile | Descrizione |
|---|---|
| `FRONTOFFICE_PUBLIC_BASE_URL` | URL pubblico del Frontoffice (entityID SAML = `$URL/saml/sp`) |
| `SPID_CERT_COMMON_NAME` | Common Name del certificato SPID |
| `SPID_CERT_DAYS` | Durata del certificato in giorni |
| `SPID_CERT_ENTITY_ID` | EntityID da incidere nel cert (default: `$FRONTOFFICE_PUBLIC_BASE_URL/saml/sp`) |
| `SPID_CERT_KEY_SIZE` | Dimensione chiave RSA (2048/3072/4096, default 3072) |
| `SPID_CERT_LOCALITY_NAME` | Comune per il certificato |
| `SPID_CERT_ORG_ID` | Organization Identifier (`PA:IT-<codice_IPA>`) |
| `SPID_CERT_ORG_NAME` | Organization Name |
| `APP_ENTITY_IPA_CODE` | Codice IPA dell'ente |
| `FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS` | Durata validità metadata (default 365) |

## Note

- **`metadata/agid/satosa_spid_public_metadata.xml`** — file locale pronto da inviare ad AgID.
- **Chiave privata SPID**: resta nel volume Docker certificati, non va inviata ad AGID né inclusa nel metadata.
- Il metadata interno Frontoffice SP è gestito automaticamente all'avvio e non viene più salvato nel repository.
- I file generati sono ignorati da git (`.gitignore`).
- Su **Windows**: usa Git Bash oppure WSL per eseguire `setup-sp.sh`. Richiede Docker Desktop attivo.
