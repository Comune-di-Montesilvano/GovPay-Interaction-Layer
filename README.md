# GovPay Interaction Layer (GIL)

Piattaforma containerizzata (PHP/Apache + UI) per migliorare il flusso di lavoro degli enti che usano GovPay come soluzione PagoPA.
Include:
- **Backoffice** per operatori (gestione pendenze, flussi di rendicontazione, ricevute pagoPA on-demand)
- **Frontoffice** cittadini/sportello
- (Opzionale) **proxy SPID/CIE** integrabile via profilo Docker Compose

Repository: https://github.com/mirkochipdotcom/GovPay-Interaction-Layer.git

License: European Union Public Licence v1.2 (EUPL-1.2)
SPDX-License-Identifier: EUPL-1.2

---

## Indice

- [Cos’è e a cosa serve](#cosè-e-a-cosa-serve)
- [Avvio rapido (primo utilizzo)](#avvio-rapido-primo-utilizzo)
- [Configurazione: file .env](#configurazione-file-env)
- [SPID/CIE](#spidcie)
- [IAM Proxy Italia (SATOSA)](#iam-proxy-italia-satosa)
- [Metadata SPID/CIE (freeze + next)](#metadata-spidcie-freeze--next)
- [Setup produzione](#setup-produzione)
- [Funzionalità Backoffice](#funzionalità-backoffice)
- [Workflow di sviluppo](#workflow-di-sviluppo)
- [Troubleshooting](#troubleshooting)
- [Struttura del progetto](#struttura-del-progetto)

---

## Cos’è e a cosa serve

GovPay Interaction Layer (GIL) funge da livello intermedio tra GovPay e gli operatori dell’ente, fornendo un portale più “operativo” rispetto alla GUI standard.
In pratica offre:

- **Backoffice unificato** per creare/modificare pendenze, consultare flussi di rendicontazione e recuperare ricevute pagoPA on-demand.
- **Strumenti di controllo** (ricerche/filtri/viste dedicate) per individuare rapidamente flussi in errore, stand‑in o pagamenti non riconciliati.
- **Integrazione Biz Events** per il recupero on-demand delle ricevute di pagamento pagoPA (debitore, pagatore, PSP, trasferimenti) direttamente dal dettaglio flusso.
- **Frontoffice semplificato** per cittadini o sportelli, con possibilità di reindirizzare alcune tipologie verso portali esterni.
- **Gestione integrazione** (certificati e configurazione) per isolare la complessità di GovPay.

Opzionalmente include un **proxy SPID/CIE** (interno o esterno) per gestire login/logout e la pubblicazione/rotazione dei metadata.

## Avvio rapido (primo utilizzo)

### 0) Prerequisiti

- Docker Desktop (o Docker Engine + plugin `docker compose`)
- Git
- Porte libere sul tuo host (default):
  - Backoffice: `8443`
  - Frontoffice: `8444`
  - Proxy SPID (se abilitato): `9445`

### 1) Clona il repository

```bash
git clone https://github.com/mirkochipdotcom/GovPay-Interaction-Layer.git
cd GovPay-Interaction-Layer
```

### 2) Crea i file di configurazione

Questo repository usa file separati per rendere la configurazione più chiara:

```bash
cp .env.example .env
cp .iam-proxy.env.example .iam-proxy.env
```

Note:
- `.env` è sempre necessario (compose + valori condivisi).
- `.iam-proxy.env` è necessario se abiliti SPID/CIE.

#### Dati minimi necessari (GovPay e PagoPA Checkout)

L'app si avvia anche lasciando gli example, ma per usare davvero le funzioni principali servono endpoint e credenziali reali.

GovPay:

- Identità ente/app: `ID_DOMINIO` (CF/P.IVA ente creditore) e `ID_A2A` (id applicazione).
- Endpoint API: compila i `GOVPAY_*_URL` nel `.env` (pendenze/pagamenti/ragioneria/backoffice).
- Autenticazione: scegli `AUTHENTICATION_GOVPAY` e configura di conseguenza:
   - `basic` / `form`: imposta `GOVPAY_USER` e `GOVPAY_PASSWORD`.
   - `ssl` / `sslheader`: monta i certificati client in `certificate/` e imposta `GOVPAY_TLS_CERT` e `GOVPAY_TLS_KEY` (opzionale `GOVPAY_TLS_KEY_PASSWORD`).

PagoPA Checkout (EC API):

- `PAGOPA_CHECKOUT_EC_BASE_URL` (DEV/PROD).
- `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY` (header `Ocp-Apim-Subscription-Key`, deve restare server-side).
- `PAGOPA_CHECKOUT_COMPANY_NAME` (nome ente mostrato nel checkout).
- (Opzionale) `PAGOPA_CHECKOUT_RETURN_OK_URL`, `PAGOPA_CHECKOUT_RETURN_CANCEL_URL`, `PAGOPA_CHECKOUT_RETURN_ERROR_URL`.

pagoPA Biz Events (Ricevute EC):

- `BIZ_EVENTS_HOST`: Base URL dell'API Biz Events Service.
  - DEV: `https://api.dev.platform.pagopa.it/bizevents/service/v1`
  - PROD: `https://api.platform.pagopa.it/bizevents/service/v1`
- `BIZ_EVENTS_API_KEY`: Subscription key per il servizio Biz Events (header `Ocp-Apim-Subscription-Key`).

Queste variabili sono necessarie per la funzionalità di recupero ricevute on-demand nel dettaglio flusso del Backoffice.

Approfondimenti:
- certificati GovPay: `certificate/README.md`
- configurazione Checkout e Biz Events: `pagopa-clients/README.md`

### 3) Avvia i container

```bash
# primo avvio o quando hai cambiato Dockerfile/composer/asset
docker compose up -d --build

# avvii successivi senza rebuild
docker compose up -d
```

### 4) Primo accesso

- Backoffice: https://localhost:8443
- Frontoffice: https://localhost:8444

Se non fornisci certificati personalizzati in `ssl/`, verranno generati certificati self-signed: il browser mostrerà un warning.

### 5) Superadmin (seed)

Al primo avvio uno script crea lo schema utenti e inserisce un utente `superadmin` usando:

```env
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=una_password_sicura
```

Il seed è idempotente: viene creato solo se non esiste già un superadmin nel DB.

---

## Configurazione: file .env

### `.env` (compose + condivisi)

Contiene:
- porte esposte (`BACKOFFICE_HTTPS_PORT`, `FRONTOFFICE_HTTPS_PORT`, …)
- profili Docker Compose (`COMPOSE_PROFILES`)
- configurazione DB (`DB_*`)
- configurazione GovPay (URL, auth, certificati)

### `.env.frontoffice`

Contiene:
- base URL pubblico del frontoffice (`FRONTOFFICE_PUBLIC_BASE_URL`)
- parametri SAML SP (certificati, callback)
- dati DB dedicati ai cittadini

### `.env.backoffice`

Contiene:
- porta HTTPS
- dati DB backoffice

### `.iam-proxy.env`

Contiene:
- URL pubblici e metadata SAML2 del proxy IAM
- impostazioni SATOSA (UI, metadata, chiavi)
- abilitazioni UI (SPID/CIE)
- configurazione MongoDB

---

## SPID/CIE

Il sistema di autenticazione usa **IAM Proxy Italia (SATOSA)** come componente standard.

### Configurazione IAM Proxy

**Variabile principale**:
- `IAM_PROXY_PUBLIC_BASE_URL`: URL pubblico usato dal browser per accedere al proxy SPID/CIE
   - Esempio DEV: `https://127.0.0.1:9445`
   - Esempio PROD: `https://login.comune.it` (senza porta se è standard 443)

**Variabili interne** (non modificare a meno di rinominare i servizi docker):
- `IAM_PROXY_SAML2_IDP_METADATA_URL_INTERNAL`: `https://satosa-nginx:443/Saml2IDP/metadata`

**Altri parametri** (UI/metadata/chiavi) sono in [.iam-proxy.env](.iam-proxy.env) e sono già valorizzati negli example.

### Configurazione IAM Proxy SATOSA
- `iam-proxy-italia` (SATOSA uWSGI)
- `satosa-nginx` (TLS + static + reverse uWSGI)

La directory di istanza di SATOSA viene generata in **`.local/iam-proxy-italia-project/`** (fuori dal repository) per mantenere il repository pulito.

Nota importante: **questa parte è pensata per avvio/validazione dello stack**. L'integrazione del frontoffice (che oggi parla con il proxy PHP via `/proxy-home.php` e `/proxy.php`) richiede un adattamento applicativo per usare i nuovi endpoint SATOSA.

### Avvio rapido (locale)

1) **Primo avvio**: La directory di istanza viene generata automaticamente al primo `docker compose up -d`:

- Windows PowerShell:
   ```powershell
   docker compose --profile iam-proxy up -d --build
   ```
   
   Al primo avvio, il servizio `iam-proxy-init` scarica l'archivio da GitHub, estrae in `.local/iam-proxy-italia-project/` e prepara i file di istanza.
   Se mancano i certificati in `ssl/`, uno script di init NGINX (`iam-proxy/nginx/entrypoint.d/10-generate-certs.sh`) genera automaticamente un certificato self-signed per `satosa-nginx`.

2) Oppure, se preferisci inizializzare manualmente (per debug):

- Windows (PowerShell):
   - task VS Code: `init-iam-proxy-italia-force`
   - oppure: `powershell -ExecutionPolicy Bypass -File .\scripts\init-iam-proxy-italia.ps1 -Force`

2) Avvia i container:

- task VS Code: `up-iam-proxy`
- oppure: `docker compose --profile iam-proxy up -d`

3) Verifica endpoint principali:

### Endpoint esposti da SATOSA

> [!IMPORTANT]
> SATOSA espone **due set di metadata distinti** con scopi completamente diversi. Usare l'endpoint sbagliato è
> la fonte più comune di errori di configurazione e validazione.

| Endpoint | A cosa serve | Chi lo usa | Va inviato ad AgID? |
|---|---|---|:---:|
| `/Saml2IDP/metadata` | Metadata IdP di SATOSA verso il **frontoffice** | Il frontoffice (SP interno) per sapere dove inviare le richieste SAML | ❌ No |
| `/spidSaml2/metadata` | Metadata **SP di SATOSA verso SPID** | Gli IdP SPID/CIE (Poste, Aruba...) per identificare SATOSA come SP | ✅ **Sì** |
| `/static/disco.html` | Pagina di discovery (scelta IdP) | Browser utente | ❌ No |

**Esempi URL locali:**
```
https://localhost:9445/Saml2IDP/metadata      ← uso interno
https://localhost:9445/spidSaml2/metadata     ← da inviare ad AgID
https://localhost:9445/static/disco.html      ← pagina discovery
```

> [!WARNING]
> L'endpoint `/spSaml2/metadata` (senza "id") **non esiste** e restituisce errore 302.
> Il path corretto è `/spidSaml2/metadata`.

**Per la validazione AgID** usa sempre `/spidSaml2/metadata`.
Esempio produzione: `https://pagopa-prx.comune.montesilvano.pe.it/spidSaml2/metadata`

### Dove si configurano i file

- Istanza SATOSA: directory `iam-proxy/iam-proxy-italia-project/` (popolata dallo script; ignorata da git)
- Static NGINX: directory `iam-proxy/nginx/html/static/` (popolata dallo script; ignorata da git)

### Metadata Service Provider (frontoffice)

SATOSA deve conoscere i metadata del Service Provider (frontoffice) per accettare le richieste di autenticazione.
I metadata SP sono generati dinamicamente in base all'ambiente e **non sono versionati** nel repository:

- **Directory**: `iam-proxy/metadata-sp/` (ignorata da git)
- **File**: `frontoffice_sp.xml` e `frontoffice_sp` (senza estensione)
- **Entity ID**: Dipende da `FRONTOFFICE_PUBLIC_BASE_URL` (es. `https://127.0.0.1:8444/saml/sp` per dev locale)
- **Assertion Consumer Service**: `{FRONTOFFICE_PUBLIC_BASE_URL}/spid/callback`

#### Generazione dei metadata SP

I metadata SP vengono generati automaticamente al primo avvio se non esistono. Per rigenerarli manualmente:

**Opzione 1: Usando il container frontoffice (raccomandato)**

```bash
# 1. Genera i metadata usando le variabili d'ambiente del container
docker compose exec govpay-interaction-frontoffice php -r '
require_once "/var/www/html/vendor/autoload.php";
use OneLogin\Saml2\Settings;

$frontofficeBaseUrl = getenv("FRONTOFFICE_PUBLIC_BASE_URL") ?: "https://127.0.0.1:8444";
$spEntityId = rtrim($frontofficeBaseUrl, "/") . "/saml/sp";
$acsUrl = rtrim($frontofficeBaseUrl, "/") . "/spid/callback";

$settings = [
    "strict" => false,
    "sp" => [
        "entityId" => $spEntityId,
        "assertionConsumerService" => ["url" => $acsUrl, "binding" => "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"],
        "singleLogoutService" => ["url" => rtrim($frontofficeBaseUrl, "/") . "/spid/logout", "binding" => "urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"],
        "NameIDFormat" => "urn:oasis:names:tc:SAML:2.0:nameid-format:transient"
    ],
    "idp" => ["entityId" => "http://placeholder", "singleSignOnService" => ["url" => "http://placeholder"], "x509cert" => "placeholder"],
    "security" => ["authnRequestsSigned" => false, "wantAssertionsSigned" => true, "wantMessagesSigned" => true]
];

$settingsObj = new Settings($settings);
echo $settingsObj->getSPMetadata();
' > iam-proxy/metadata-sp/frontoffice_sp.xml

# 2. Crea anche la versione senza estensione .xml (pysaml2 legge entrambe)
cp iam-proxy/metadata-sp/frontoffice_sp.xml iam-proxy/metadata-sp/frontoffice_sp

# 3. Riavvia SATOSA per caricare i nuovi metadata
docker compose --profile iam-proxy restart iam-proxy-italia
```

**Opzione 2: Modifica manuale del file XML**

Edita direttamente `iam-proxy/metadata-sp/frontoffice_sp.xml`:

```xml
<?xml version="1.0"?>
<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata"
                     validUntil="2027-02-03T15:14:13Z"
                     cacheDuration="PT604800S"
                     entityID="https://TUO_DOMINIO/saml/sp">
    <md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" 
                        protocolSupportEnumeration="urn:oasis:names:tc:SAML:2.0:protocol">
        <md:SingleLogoutService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect"
                                Location="https://TUO_DOMINIO/spid/logout" />
        <md:NameIDFormat>urn:oasis:names:tc:SAML:2.0:nameid-format:transient</md:NameIDFormat>
        <md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST"
                                     Location="https://TUO_DOMINIO/spid/callback"
                                     index="1" />
    </md:SPSSODescriptor>
</md:EntityDescriptor>
```

Poi:
1. Copia il file anche come `frontoffice_sp` (senza estensione): `cp iam-proxy/metadata-sp/frontoffice_sp.xml iam-proxy/metadata-sp/frontoffice_sp`
2. Riavvia `iam-proxy-italia`: `docker compose --profile iam-proxy restart iam-proxy-italia`

#### Note importanti per AgID

- I metadata SP del frontoffice **NON** vanno inviati ad AgID (sono interni a SATOSA)
- Ad AgID vanno inviati i **metadata IdP** di SATOSA (servizio Saml2IDP)
- Per i metadata IdP di SATOSA, vedi la sezione "Metadata SPID/CIE (freeze + next)" sotto

---

## Metadata SPID/CIE

I metadata IdP di SATOSA sono esposti da:

- `https://<IAM_PROXY_PUBLIC_BASE_URL>/Saml2IDP/metadata`

Per SPID/CIE ufficiali, questi metadata sono quelli da fornire in fase di attestazione.

---

## Setup produzione

Questa sezione è una checklist operativa per portare GIL in un ambiente reale (dominio pubblico, reverse proxy, certificati validi) e per ridurre i problemi tipici di SPID/CIE.

### Domini e URL pubblici

Definisci chiaramente gli URL pubblici (quelli che useranno gli utenti e/o AgID):

- Backoffice (es. `https://backoffice.ente.it`)
- Frontoffice (es. `https://pagamenti.ente.it`)
- Proxy SPID/CIE (es. `https://login.ente.it`)

Poi imposta:
- in `.iam-proxy.env`:
   - `IAM_PROXY_PUBLIC_BASE_URL=https://login.ente.it`
- in `.env.frontoffice`:
   - `FRONTOFFICE_PUBLIC_BASE_URL=https://pagamenti.ente.it`

Evita `localhost`/`127.0.0.1` in produzione: finiscono nei redirect e/o nei metadata.

### TLS / certificati

- Per HTTPS lato browser → applicazione, usa certificati validi (non self-signed) in `ssl/`:
   - `ssl/server.key`
   - `ssl/server.crt`
- I certificati per le API GovPay (client certificate) restano in `certificate/`.

Se usi un reverse proxy esterno (Nginx/Traefik/Apache), puoi terminare TLS lì e pubblicare i container su rete interna: l’importante è che gli URL “pubblici” in env siano coerenti con ciò che vede l’utente.

### Reverse proxy (raccomandato)

Pattern tipico:
- reverse proxy pubblico (porta 443) → inoltra a `localhost:8443/8444/8445` (o alle porte interne dei container)
- header standard preservati:
   - `Host`
   - `X-Forwarded-Proto=https`
   - `X-Forwarded-For`

Obiettivo: far sì che i redirect generati puntino sempre agli URL pubblici corretti.

### SPID/CIE: checklist “non rompere il login”

- `FRONTOFFICE_PUBLIC_BASE_URL` e `IAM_PROXY_PUBLIC_BASE_URL` devono essere coerenti con gli URL pubblici reali.
- Se cambi host/porta, rigenera i metadata SP del frontoffice (vedi sezione dedicata).

---

## Processi batch (Cron Job)

GIL utilizza degli script PHP via CLI per gestire elaborazioni asincrone o massive. Questi script devono essere schedulati sull'host tramite `crontab` o `systemd timers`.

### 1. Inserimento Massivo Pendenze
Lo script recupera i lotti caricati via Web (in stato `PENDING`) e li invia alle API di GovPay.

- **Percorso**: `/var/www/html/scripts/cron_pendenze_massive.php` (all'interno del container backoffice)
- **Comando manuale**:
  ```bash
  docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
  ```

### 2. Rendicontazione e Webhook (In arrivo)
Il sistema di rendicontazione utilizzerà un processo simile per scansionare i flussi, inviare mail e notificare sistemi terzi via webhook.

### Esempio Schedulazione (Host)

#### Opzione A: Crontab
Per l'elaborazione massiva ogni 5 minuti:
```cron
*/5 * * * * docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php >> /var/log/gil_cron.log 2>&1
```

#### Opzione B: Systemd Timer (Consigliato per Podman/Produzione)
Crea un file `/etc/systemd/system/gil-cron.service`:
```ini
[Unit]
Description=Run GIL Massive Pendenze Cron
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
```

E un file `/etc/systemd/system/gil-cron.timer`:
```ini
[Unit]
Description=Run GIL Massive Pendenze every 5 minutes

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
```

---

## Funzionalità Backoffice

### Gestione Pendenze

- Ricerca, inserimento singolo e massivo di pendenze.
- Dettaglio pendenza con azioni (annullamento, stralcio, riattivazione).
- Storico modifiche registrato in `datiAllegati`.
- Origine (Spontaneo / GIL-Backoffice) e operatore tracciati.

### Flussi di Rendicontazione

- **Ricerca flussi**: ricerca per data, PSP, stato, con paginazione e filtri.
- **Dettaglio flusso**: mostra i pagamenti rendicontati con informazioni IUV, causale, importo, esito.
- **Ricevute on-demand (Biz Events)**: per i pagamenti "orfani" (privi di dati GovPay locali), un pulsante 🔍 permette di caricare la ricevuta pagoPA tramite AJAX. Si apre un modale con:
  - Dati debitore e pagatore (nome, codice fiscale, tipo).
  - Intermediario PSP (nome, canale, metodo di pagamento).
  - Dettagli del pagamento (descrizione, importo totale, data, esito, numero avviso, ID ricevuta).
  - Tabella **trasferimenti** con l'elenco delle singole voci (importo, beneficiario, IBAN).
- Gestione errori: rate limit (429), ricevuta non trovata (404), errori di rete.

### Statistiche

- Dashboard con grafici e indicatori.

---

## Workflow di sviluppo

### Modifiche al codice

- Backoffice: cartella `backoffice/`
- Frontoffice: cartella `frontoffice/`
- Libreria/app PHP condivisa: cartella `app/`
- Template Twig: cartelle `templates/` (root) e `frontoffice/templates/`

In generale:

```bash
# rebuild quando cambi PHP/composer o asset
docker compose up -d --build
```

### Log e debug

```bash
docker compose logs -f
docker compose exec govpay-interaction-backoffice bash
docker compose restart govpay-interaction-backoffice
```

---

## Troubleshooting

### Variabili “annidate” nei file env

Docker Compose **non espande** variabili del tipo `FOO="${BAR}"` dentro gli `env_file`.
Se vuoi un fallback, lascia la variabile vuota oppure valorizzala esplicitamente.

### Script `.sh` e line ending

Gli script `.sh` devono essere in LF (non CRLF) per evitare errori su Linux/Docker.
Questo repository forza i line ending via `.gitattributes`.

---

## Struttura del progetto

```
GovPay-Interaction-Layer/
├── docker-compose.yml
├── Dockerfile
├── backoffice/
├── frontoffice/
├── iam-proxy/                # proxy SPID/CIE (SATOSA)
├── app/                      # codice PHP condiviso
├── templates/                # template backoffice/shared
├── debug/                    # tool debug (montati solo nel backoffice)
├── govpay-clients/           # client API generati
├── pagopa-clients/           # client API generati
├── migrations/
├── ssl/                      # cert HTTPS del server (browser → app)
├── certificate/              # cert client GovPay (app → GovPay)
├── .env                      # da creare (base)
├── .iam-proxy.env            # da creare (SPID/CIE)
├── .env.frontoffice          # da creare
└── .env.backoffice           # da creare
```

---

## Contribuire

1) Fork del repository
2) Crea un branch: `git checkout -b feature/nuova-funzionalita`
3) Commit: `git commit -m "Aggiunge nuova funzionalità"`
4) Push: `git push origin feature/nuova-funzionalita`
5) Apri una Pull Request

## Supporto

- Issues: https://github.com/mirkochipdotcom/GovPay-Interaction-Layer/issues
