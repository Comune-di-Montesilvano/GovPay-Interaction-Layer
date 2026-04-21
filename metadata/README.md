# metadata/

Questa cartella contiene gli script per:

- generare il metadata pubblico SATOSA da inviare ad AgID
- gestire i certificati SPID-compliant in un volume Docker dedicato
- esportare Entity Configuration e JWKS CIE OIDC per l'onboarding

## Struttura

```
metadata/
  builder/              ← container Docker per tutti i comandi metadata
  spid-gencert-public.sh ← generazione certificati SPID (usato internamente dal builder)
  agid/                 ← output metadata pubblico SATOSA per AgID (gitignored)
  cieoidc/              ← output CIE OIDC (entity config, jwks, riepilogo) (gitignored)
  cieoidc-keys/         ← chiavi JWK private CIE OIDC generate per deployment (gitignored)

scripts/
  check-cie-oidc-federation-endpoints.sh  ← testa gli endpoint pubblici OIDC Federation
```

---

## Prima installazione

**Prerequisiti**: Docker Desktop attivo, file `.auth-proxy.env` configurato.
Tutte le variabili `SPID_CERT_*`, `SATOSA_*`, `CIE_OIDC_*` sono in `.auth-proxy.env`
(copia `.auth-proxy.env.example` e compila i valori). Il file `.env` contiene le
variabili generali dell'applicazione.

```bash
# 1. Configura i file di ambiente
cp .env.example .env
cp .auth-proxy.env.example .auth-proxy.env
# Edita .auth-proxy.env:
#   SPID_CERT_COMMON_NAME, SPID_CERT_ORG_NAME, SPID_CERT_ORG_ID,
#   SATOSA_ORGANIZATION_*, SATOSA_CONTACT_PERSON_*, CIE_OIDC_*,
#   IAM_PROXY_PUBLIC_BASE_URL

# 2. Genera certificati SPID + chiavi JWK CIE OIDC
docker compose run --rm metadata-builder setup

# 3. Avvia i container
docker compose --profile auth-proxy up -d

# 4. Esporta il metadata pubblico per AgID
docker compose run --rm metadata-builder export-agid
# → metadata/agid/satosa_spid_public_metadata.xml

# 5. Esporta gli artifact CIE OIDC per l'onboarding
docker compose run --rm metadata-builder export-cieoidc
# → metadata/cieoidc/ (entity-configuration.jwt, jwks-federation-public.json, ...)

# 6. Invia satosa_spid_public_metadata.xml ad AgID
# 7. Completa l'onboarding CIE OIDC (vedi sezione dedicata)
# 8. Dopo la federazione: esegui subito un backup
docker compose run --rm metadata-builder backup
```

---

## Stato e scadenze

```bash
docker compose run --rm metadata-builder status
```

Mostra:
- Data di scadenza del certificato SPID (da volume `govpay_spid_certs`)
- `validUntil` del SP metadata Frontoffice (da volume `frontoffice_sp_metadata`)
- Giorni restanti dell'Entity Statement CIE OIDC

---

## Backup e ripristino

### Backup

Eseguire il backup **subito dopo la prima federazione** e dopo ogni rinnovo.

```bash
# Backup in backup/ (creata automaticamente)
docker compose run --rm metadata-builder backup

# Backup in directory personalizzata
docker compose run --rm metadata-builder backup /mnt/nas/govpay
```

Il backup crea tre archivi con timestamp:
- `spid_certs_YYYYMMDD_HHMMSS.tar.gz` — volume Docker `govpay_spid_certs` (cert.pem + privkey.pem)
- `frontoffice_sp_metadata_YYYYMMDD_HHMMSS.tar.gz` — volume Docker `frontoffice_sp_metadata`
- `metadata_local_YYYYMMDD_HHMMSS.tar.gz` — `metadata/cieoidc-keys/`, `metadata/agid/`, `metadata/cieoidc/`

### Ripristino

```bash
docker compose run --rm metadata-builder restore backup/spid_certs_20250101_120000.tar.gz
docker compose run --rm metadata-builder restore backup/frontoffice_sp_metadata_20250101_120000.tar.gz
docker compose run --rm metadata-builder restore backup/metadata_local_20250101_120000.tar.gz
```

Il tipo di ripristino viene rilevato automaticamente dal nome del file. Ogni comando chiede conferma prima di sovrascrivere.

Dopo il ripristino:

```bash
docker compose --profile auth-proxy restart auth-proxy
```

---

## Rinnovo metadata SPID

### Pre-generare il nuovo metadata (senza interrompere la federazione)

Il metadata SP viene auto-rinnovato ogni 6 ore dal servizio `refresh-sp-metadata`.
Quando scade entro 7 giorni, `auth-proxy` registra un WARNING nei log.

Per generare in anticipo un nuovo metadata senza toccare quello attivo:

```bash
docker exec govpay-interaction-frontoffice bash /scripts/ensure-sp-metadata.sh --new
```

Genera un file `frontoffice_sp-new-{timestamp}.xml` nel volume `frontoffice_sp_metadata`.
La federazione rimane attiva. Per attivarlo:

```bash
docker exec govpay-interaction-frontoffice bash /scripts/ensure-sp-metadata.sh --force
docker compose --profile auth-proxy restart auth-proxy
```

### Rinnovo certificati SPID (alla scadenza)

> **Attenzione**: rompe la federazione con AgID. Dopo il rinnovo è necessario re-inviare il metadata.

```bash
docker compose run --rm metadata-builder renew-spid
```

Lo script rigenera cert.pem + privkey.pem nel volume Docker. Segui le istruzioni a schermo
per i passi successivi (esporta con `export-agid`, invia ad AgID, riavvia, backup).

---

## Setup chiavi JWK CIE OIDC

Da eseguire **una sola volta per deployment** tramite il comando `setup` o singolarmente:

```bash
docker compose run --rm metadata-builder setup-cieoidc
```

Le chiavi vengono salvate in `metadata/cieoidc-keys/` (gitignored). Una volta federate,
rigenerare le chiavi rompe la federazione finché l'Entity Statement non è scaduto.

---

## Export artifact CIE OIDC (per onboarding)

```bash
docker compose run --rm metadata-builder export-cieoidc
```

Richiede che il profilo `auth-proxy` sia avviato. Curla gli endpoint interni di `auth-proxy-nginx`.

Output generato in `metadata/cieoidc/`:

- `entity-configuration.jwt`
- `entity-configuration.json`
- `jwks-federation-public.json`
- `jwks-rp.json`
- `jwks-rp.jose`
- `component-values.env` — contiene `ENTITY_STATEMENT_EXP_UTC` e `ENTITY_STATEMENT_EXP_DAYS_REMAINING`

> L'export è bloccato se già presente e non scaduto. Usa `FORCE=1` solo in caso di rinnovo:
> ```bash
> docker compose run --rm -e FORCE=1 metadata-builder export-cieoidc
> ```

---

## Rinnovo chiavi CIE OIDC (solo a scadenza Entity Statement)

```bash
docker compose run --rm metadata-builder renew-cieoidc
```

Richiede di digitare `SI VOGLIO RINNOVARE` come conferma esplicita.
Lo script rigenera le chiavi e mostra i passi successivi (export-cieoidc, onboarding portale CIE,
restart container, attesa propagazione fino a 24h, backup).

---

## Onboarding CIE OIDC e Test della Federazione

Dopo aver generato le chiavi e avviato l'ambiente, per abilitare l'autenticazione CIE **è necessario completare il processo di onboarding** sulla Federazione CIE.

1. **Test degli endpoint pubblici**: l'Identity Provider deve poter scaricare l'Entity Configuration esposta dal tuo Auth Proxy.
   ```bash
   bash scripts/check-cie-oidc-federation-endpoints.sh -b "https://iltuodominio.it/CieOidcRp"
   ```
   Tutte le chiamate (eccetto forse POST a `trust_mark_status`) dovrebbero restituire HTTP 200.

2. **Scelta dell'ambiente (Collaudo o Produzione)**:
   Modifica nel file `.auth-proxy.env` gli endpoint puntando a **Collaudo** (`preproduzione.oidc.*`) in fase di test, o a **Produzione** per la messa in onda definitiva. Dopo un cambio, riavvia il container `auth-proxy`.

3. **Registrazione Portale Federazione**:
   Recati sul portale CIE per gli sviluppatori ed effettua l'onboarding. Ti sarà richiesto di fornire l'URL del tuo *Client ID* (la rotta root configurata in `CIE_OIDC_CLIENT_ID` senza slash finale).

4. **Tempi di Propagazione**:
   Una volta completata la registrazione nel Registry, l'Identity Provider può impiegare **diverse ore (fino a 24h)** per aggiornare la cache e fidarsi del nuovo Relying Party. Durante questo periodo visualizzerai l'errore: **"L'applicazione a cui hai acceduto non è registrata"**. Questo è il comportamento atteso.

---

## Distinzione fondamentale

- `metadata/agid/satosa_spid_public_metadata.xml` — metadata pubblico SATOSA (`/spidSaml2/metadata`) da inviare ad AgID.
- Metadata interno Frontoffice SP — gestito automaticamente nel volume Docker `frontoffice_sp_metadata` (auto-rinnovato ogni 6h), non esposto né gestito dalla UI.
- Certificati SPID — nel volume Docker `govpay_spid_certs` (o nel volume indicato da `SPID_CERTS_DOCKER_VOLUME`).

## Variabili rilevanti in `.auth-proxy.env`

| Variabile | Descrizione |
|---|---|
| `IAM_PROXY_PUBLIC_BASE_URL` | URL pubblico del proxy (usato come base per CIE_OIDC_CLIENT_ID) |
| `SPID_CERT_COMMON_NAME` | Common Name del certificato SPID |
| `SPID_CERT_DAYS` | Durata del certificato in giorni |
| `SPID_CERT_ENTITY_ID` | EntityID da incidere nel cert (default: `$FRONTOFFICE_PUBLIC_BASE_URL/saml/sp`) |
| `SPID_CERT_KEY_SIZE` | Dimensione chiave RSA (2048/3072/4096, default 3072) |
| `SPID_CERT_LOCALITY_NAME` | Comune per il certificato |
| `SPID_CERT_ORG_ID` | Organization Identifier (`PA:IT-<codice_IPA>`) |
| `SPID_CERT_ORG_NAME` | Organization Name |
| `SPID_CERTS_DOCKER_VOLUME` | Nome del volume Docker per i certificati (default: `govpay_spid_certs`) |
| `SATOSA_ORGANIZATION_*` | Nome e URL dell'organizzazione nel metadata SATOSA |
| `SATOSA_CONTACT_PERSON_*` | Dati del contatto tecnico nel metadata |
| `CIE_OIDC_CLIENT_ID` | Client ID per la federazione CIE OIDC |
| `CIE_OIDC_CLIENT_NAME` | Nome del Relying Party CIE OIDC |
| `FRONTOFFICE_SAML_SP_METADATA_VALIDITY_DAYS` | Durata validità metadata (default 365) |

In `.env`:

| Variabile | Descrizione |
|---|---|
| `FRONTOFFICE_PUBLIC_BASE_URL` | URL pubblico del Frontoffice (usato come fallback in setup-sp.sh) |
| `APP_ENTITY_NAME` | Nome dell'ente (usato come fallback per SPID_CERT_COMMON_NAME) |
| `APP_ENTITY_IPA_CODE` | Codice IPA (usato come fallback per SPID_CERT_ORG_ID) |

## Note

- **Chiave privata SPID**: resta nel volume Docker, non va inviata ad AgID né inclusa nel metadata.
- **Chiavi JWK CIE OIDC**: in `metadata/cieoidc-keys/` (gitignored). Non condividere, non committare.
- I file generati sono ignorati da git (`.gitignore`).
- `metadata/spid-gencert-public.sh` è usato internamente dal container `metadata-builder` — non eseguire direttamente.
