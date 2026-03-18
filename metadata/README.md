# metadata/

Questa cartella contiene gli script per:

- generare manualmente il metadata pubblico SATOSA da inviare ad AgID
- gestire i certificati SPID-compliant in un volume Docker dedicato
- esportare manualmente Entity Configuration e JWKS CIE OIDC

## Struttura

```
metadata/
  setup-sp.sh           ← genera certificati SPID + metadata AgID (prima di docker compose up)
  setup-sp.ps1          ← equivalente PowerShell (Windows)
  setup-cie-oidc.sh     ← genera chiavi JWK CIE OIDC (prima di sync-iam-proxy-italia.sh)
  setup-cie-oidc.ps1    ← equivalente PowerShell (Windows)
  agid/                 ← output metadata pubblico SATOSA per AgID (gitignored)
  export-cieoidc.sh     ← export manuale artifact CIE OIDC (Linux/macOS/WSL)
  export-cieoidc.ps1    ← export manuale artifact CIE OIDC (Windows)
  cieoidc/              ← output CIE OIDC (entity config, jwks, riepilogo) (gitignored)
  cieoidc-keys/         ← chiavi JWK private CIE OIDC generate per deployment (gitignored)

scripts/
  check-cie-oidc-federation-endpoints.sh  ← testa gli endpoint pubblici OIDC Federation
  check-cie-oidc-federation-endpoints.ps1 ← equivalente PowerShell
```

## Utilizzo

### Prima installazione

**Windows (PowerShell):**
```powershell
# 1. Copia e configura le variabili d'ambiente
Copy-Item .env.example .env
# (edita .env: FRONTOFFICE_PUBLIC_BASE_URL, APP_ENTITY_*, SPID_CERT_*, CIE_OIDC_*, ecc.)

# 2. Genera chiavi JWK CIE OIDC (univoche per deployment, bloccate finché non ri-federi)
.\metadata\setup-cie-oidc.ps1

# 3. Genera certificati SPID e metadata pubblico AgID
.\metadata\setup-sp.ps1

# 4. Avvia i servizi
docker compose up -d
```

**Linux / macOS / WSL / Git Bash:**
```bash
cp .env.example .env
# (edita .env)
bash metadata/setup-cie-oidc.sh
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

### Setup chiavi JWK CIE OIDC (da fare UNA VOLTA per deployment)

```powershell
.\metadata\setup-cie-oidc.ps1
```
```bash
bash metadata/setup-cie-oidc.sh
```

Le chiavi vengono salvate in `metadata/cieoidc-keys/` (gitignored) e sono bloccate.
Rieseguire senza `-Force` non fa nulla. Una volta federate, rigenerare le chiavi rompe
la federazione finché l'Entity Statement non è scaduto.

### Export manuale CIE OIDC (per onboarding alla federazione)

```powershell
.\metadata\export-cieoidc.ps1
```

Per esportare direttamente dagli endpoint pubblici:

```powershell
.\metadata\export-cieoidc.ps1 -FromPublic
```

Output generato in `metadata/cieoidc/`:

- `entity-configuration.jwt`
- `entity-configuration.json`
- `jwks-federation-public.json`
- `jwks-rp.json`
- `jwks-rp.jose`
- `component-values.env`

Nel file `component-values.env` trovi anche la scadenza dell'Entity Statement:

- `ENTITY_STATEMENT_EXP_UTC`
- `ENTITY_STATEMENT_EXP_DAYS_REMAINING`

**Nota**: l'export è bloccato se già presente e non scaduto. Usa `-Force` solo
in caso di rinnovo consapevole della federazione.

### Rinnovo chiavi CIE OIDC (solo a scadenza Entity Statement)

```powershell
# ATTENZIONE: eseguire solo quando l'Entity Statement è scaduto o stai rinnovando
.\metadata\setup-cie-oidc.ps1 -Force -IKnowWhatIAmDoing
.\metadata\export-cieoidc.ps1 -Force
```
```bash
bash metadata/setup-cie-oidc.sh --force --i-know-what-i-am-doing
bash metadata/export-cieoidc.sh --force
```

### Onboarding CIE OIDC e Test della Federazione

Dopo aver generato le chiavi e avviato l'ambiente, per abilitare l'autenticazione CIE **è necessario completare il processo di onboarding** sulla Federazione CIE.

1. **Test degli endpoint pubblici**: l'Identity Provider deve poter scaricare l'Entity Configuration esposta dal tuo IAM Proxy.
   ```powershell
   .\scripts\check-cie-oidc-federation-endpoints.ps1 -BaseUrl "https://iltuodominio.it/CieOidcRp"
   ```
   Tutte le chiamate (eccetto forse POST a `trust_mark_status`) dovrebbero restituire HTTP 200.

2. **Scelta dell'ambiente (Collaudo o Produzione)**:
   Modifica nel file `.iam-proxy.env` gli endpoint puntando a **Collaudo** (`preproduzione.oidc.*`) in fase di test, o a **Produzione** per la messa in onda definitiva (vedi config predefinita). Dopo un cambio, riavvia il container `iam-proxy-italia`.

3. **Registrazione Portale Federazione**:
   Recati sul portale CIE per gli sviluppatori ed effettua l'onboarding. Ti sarà richiesto di fornire l'URL del tuo *Client ID* (la rotta root configurata in `CIE_OIDC_CLIENT_ID` senza slash finale).

4. **Tempi di Propagazione**:
   Una volta completata la registrazione nel Registry, l'Identity Provider può impiegare **diverse ore (fino a 24h)** per aggiornare la cache e fidarsi del nuovo Relying Party. Durante questo periodo visualizzerai l'errore: **"L'applicazione a cui hai acceduto non è registrata"**. Questo è il comportamento atteso per la federazione OIDC e bisogna semplicemente attendere.


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
- **`metadata/cieoidc/*`** — artifact locali per onboarding CIE OIDC (Entity Configuration/JWKS).
- **Chiave privata SPID**: resta nel volume Docker certificati, non va inviata ad AGID né inclusa nel metadata.
- **Chiavi JWK CIE OIDC**: salvate in `metadata/cieoidc-keys/` (gitignored). Non condividere, non committare.
- Il metadata interno Frontoffice SP è gestito automaticamente all'avvio e non viene più salvato nel repository.
- I file generati sono ignorati da git (`.gitignore`).
- Su **Windows**: usa Git Bash oppure WSL per eseguire `setup-sp.sh` e `setup-cie-oidc.sh`. Richiede Docker Desktop attivo.
