# GovPay Interaction Layer (GIL)

Piattaforma containerizzata (PHP/Apache + UI) per migliorare il flusso di lavoro degli enti che usano GovPay come soluzione PagoPA.

Include:
- **Backoffice** per operatori (gestione pendenze, flussi di rendicontazione, ricevute pagoPA on-demand)
- **Frontoffice** cittadini/sportello
- (Opzionale) **proxy SPID/CIE** configurabile dal backoffice (Impostazioni → Login Proxy)

Repository: https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer.git  
License: European Union Public Licence v1.2 (EUPL-1.2)

---

## Indice

- [Ambiente consigliato](#ambiente-consigliato)
- [Avvio rapido](#avvio-rapido)
- [Configurazione: .env](#configurazione-env)
- [Integrazioni API esterne](#integrazioni-api-esterne)
- [SPID/CIE (opzionale)](#spidcie-opzionale)
- [Setup produzione](#setup-produzione)
- [Rilasci e immagini Docker (GHCR)](#rilasci-e-immagini-docker-ghcr)
- [Processi batch](#processi-batch)
- [Funzionalità Backoffice](#funzionalità-backoffice)
- [Workflow di sviluppo](#workflow-di-sviluppo)
- [Troubleshooting](#troubleshooting)
- [Struttura del progetto](#struttura-del-progetto)

---

## Ambiente consigliato

L'ambiente di deploy **predefinito e consigliato** è uno **Stack Portainer**.

Portainer semplifica la gestione del ciclo di vita dello stack (deploy, aggiornamenti, rollback, log, variabili d'ambiente) senza richiedere accesso SSH diretto al server.

| Scenario | Strumento | Note |
|---|---|---|
| **Produzione** | **Portainer Stack** (Podman rootless) | Raccomandato — vedi [Setup produzione](#setup-produzione) |
| Sviluppo locale | Docker Compose CLI | `docker compose up -d --build` |
| CI/CD | GitHub Actions | Build + test automatici — vedi `.github/workflows/` |

> [!NOTE]
> In Portainer le variabili d'ambiente dello stack si inseriscono direttamente nell'editor dello Stack (sezione *Environment variables*), senza necessità di copiare il file `.env` sul server.

---

## Avvio rapido

> [!NOTE]
> Le immagini Docker sono **già pubblicate su GHCR**. Non è necessario clonare il repository né avere Git installato per un deploy di produzione.

### Prerequisiti

- Docker Engine + plugin `docker compose` (oppure Portainer con Podman rootless — [ambiente consigliato](#ambiente-consigliato))
- Porte libere sul tuo host (default): `8443` (backoffice), `8444` (frontoffice)

### 1. Scarica il file `docker-compose.yml`

```bash
curl -O https://raw.githubusercontent.com/Comune-di-Montesilvano/GovPay-Interaction-Layer/main/docker-compose.yml
```

In **Portainer**: usa *Stacks → Add stack → Repository* puntando al repository — non serve scaricare nulla manualmente. Vedi [Setup produzione](#setup-produzione).

### 2. Configura le variabili d'ambiente

Scarica il file d'esempio come riferimento:

```bash
curl -O https://raw.githubusercontent.com/Comune-di-Montesilvano/GovPay-Interaction-Layer/main/.env.example
cp .env.example .env
```

Il file contiene già **tutti i valori di default preimpostati**. Modifica solo le password e le chiavi crittografiche (indicate dai commenti). Tutto il resto — URL pubblici, integrazioni GovPay/pagoPA, branding — si configura dopo il primo avvio dal Backoffice → Impostazioni.

> [!NOTE]
> In **Portainer**: non serve il file `.env`. Inserisci le variabili direttamente in *Stacks → Editor → Environment variables*, usando `.env.example` come riferimento.

### 3. Avvia i container

```bash
# scarica le immagini pubblicate su GHCR e avvia
docker compose pull && docker compose up -d
```

> Vedi [Rilasci e immagini Docker (GHCR)](#rilasci-e-immagini-docker-ghcr) per i dettagli sulle immagini disponibili.

---

> **Sviluppo locale** — per lavorare sulle sorgenti è necessario clonare il repository:
> ```bash
> git clone https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer.git
> cd GovPay-Interaction-Layer
> docker compose up -d --build
> ```
> Vedi [Workflow di sviluppo](#workflow-di-sviluppo) per dettagli.

### 4. Primo accesso

- Backoffice: https://localhost:8443
- Frontoffice: https://localhost:8444

Se non hai certificati TLS personalizzati in `ssl/`, vengono generati self-signed automaticamente; il browser mostrerà un avviso (normale in locale). Vedi [ssl/README.md](ssl/README.md).

### 5. Credenziali iniziali

Al primo accesso al backoffice viene avviata automaticamente la **procedura di setup guidata** per creare il primo superadmin e configurare le impostazioni di base.

In alternativa, è possibile pre-seeding il superadmin via variabili d'ambiente aggiungendo al `.env` (opzionale):

```env
ADMIN_EMAIL=admin@ente.gov.it
ADMIN_PASSWORD=password_sicura
```

Il seed è idempotente: viene eseguito solo se non esiste ancora un superadmin nel DB.

---

## Configurazione: `.env`

Il file `.env` contiene **solo le variabili di bootstrap** necessarie all'avvio dei container. Tutto il resto (GovPay, pagoPA, SPID/CIE, branding, App IO, ecc.) si configura dall'interfaccia **Backoffice → Impostazioni** e viene salvato nel database.

```bash
cp .env.example .env
```

Le variabili obbligatorie prima del primo avvio:

| Variabile | Scopo |
|---|---|
| `DB_ROOT_PASSWORD`, `BACKOFFICE_DB_PASSWORD`, `FRONTOFFICE_DB_PASSWORD` | Credenziali database |
| `APP_ENCRYPTION_KEY` | Chiave cifratura segreti in DB — esattamente 32 caratteri (`openssl rand -hex 16`) |
| `MASTER_TOKEN` | Token Bearer per comunicazione interna backoffice ↔ auth-proxy (`openssl rand -hex 24`) |
| `MONGODB_USERNAME`, `MONGODB_PASSWORD` | Credenziali MongoDB (backend CIE OIDC) |

---

## Integrazioni API esterne

GIL si integra con i seguenti servizi esterni. Le variabili infrastrutturali/bootstrap stanno nel `.env`; la configurazione operativa di SPID/CIE viene invece salvata dal backoffice in tabella `settings`.

| Integrazione | Scopo | Variabili `.env` |
|---|---|---|
| **GovPay** | Gestione pendenze, pagamenti, flussi di rendicontazione, backoffice | `GOVPAY_*_URL`, `AUTHENTICATION_GOVPAY` |
| **pagoPA Checkout** | Avvio pagamenti online tramite redirect al portale pagoPA | `PAGOPA_CHECKOUT_EC_BASE_URL`, `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY` |
| **pagoPA Biz Events** | Recupero on-demand delle ricevute di pagamento dal dettaglio flusso | `BIZ_EVENTS_HOST`, `BIZ_EVENTS_API_KEY` |
| **App IO** | Invio messaggi e avvisi di pagamento ai cittadini (con CTA e dati avviso pagoPA) | `APP_IO_FEATURE_LEVEL_TYPE` (opz.); chiave API configurabile per tipologia |
| **SPID/CIE** | Autenticazione federata per il frontoffice cittadini | Backoffice → Impostazioni → Login Proxy — vedi sezione [SPID/CIE](#spidcie-opzionale) |

### Certificati client GovPay (mTLS)

Se `AUTHENTICATION_GOVPAY=ssl` o `sslheader`, GIL autentica le chiamate verso GovPay tramite certificato X.509 client.

Il flusso operativo corrente e' **UI first**:

- carica certificato e chiave dal backoffice durante il setup guidato oppure da **Impostazioni**
- il backoffice salva i file nel volume Docker `gil_certs`
- i path applicativi vengono registrati nel DB come `/var/www/certificate/govpay-cert.pem` e `/var/www/certificate/govpay-key.pem`

I path runtime attesi sono:

```env
GOVPAY_TLS_CERT=/var/www/certificate/govpay-cert.pem
GOVPAY_TLS_KEY=/var/www/certificate/govpay-key.pem
```

> [!WARNING]
> In deploy normali i certificati GovPay non vanno gestiti copiando file nel repository: vengono mantenuti nel volume `gil_certs` e aggiornati dalla UI. La cartella `certificate/` nella root ha senso solo come supporto locale/storico, non come flusso operativo principale.

Se la chiave privata e' protetta da password, valorizza anche `GOVPAY_TLS_KEY_PASSWORD`. In assenza dei file o con certificato scaduto le chiamate a GovPay falliscono a runtime.

Vedi [certificate/README.md](certificate/README.md) per dettagli su nomi file accettati e provenienza dei certificati.

---

## SPID/CIE (opzionale)

Il proxy SPID/CIE è basato su **IAM Proxy Italia (SATOSA)**. I container `auth-proxy` e `auth-proxy-nginx` sono sempre inclusi nello stack — SATOSA viene avviato o fermato automaticamente in base alla configurazione nel backoffice.

> **Stato attuale**: SPID è funzionante. L'integrazione CIE OIDC è in fase di sviluppo/test.

### Prerequisiti aggiuntivi

- Porta libera: `9445` (proxy IAM, configurabile con `AUTH_PROXY_PORT` in `.env`)

### 1. Bootstrap artifact SPID/CIE

Prima dell'onboarding genera gli artifact iniziali per il deployment: certificati SPID nel volume `govpay_spid_certs` e chiavi JWK CIE OIDC nel volume `gil_cieoidc_keys`.

```bash
docker compose run --rm metadata-builder setup
```

In alternativa, dal backoffice puoi generare gli stessi artifact da **Impostazioni → Login Proxy**:

- card **Certificato SPID** → `Genera`
- card **Chiavi CIE OIDC** → `Genera`

Rigenerare certificati SPID o chiavi CIE dopo l'onboarding rompe la federazione finche' non completi nuovamente i passi di registrazione lato AgID/CIE.

### 2. Configura dal backoffice

Avvia lo stack normalmente:

```bash
docker compose up -d
```

Poi apri **Backoffice → Impostazioni → Login Proxy** e compila la procedura guidata:

- **Fase 1** — dati ente per metadata SPID/CIE (nome, IPA code, contatti, URL pubblico proxy)
- **Fase 2** — secret di cifratura SATOSA (generati automaticamente o inseriti manualmente)
- **Fase 3** — abilitazione SPID / CIE OIDC, IdP demo/validator, export metadata AgID
- **Salva** — `auth-proxy` rileva la modifica entro pochi secondi e riavvia SATOSA

> Il watchdog di `auth-proxy` effettua polling della configurazione ogni `AUTH_PROXY_WATCHDOG_INTERVAL` secondi (default: 60). Al salvataggio dal backoffice viene inviato un trigger immediato via API interna (porta 9191) per applicare le modifiche in ~2 secondi.

### 3. Endpoint SPID esposti da SATOSA

> [!IMPORTANT]
> SATOSA espone due set di metadata con scopi distinti. Usare l'endpoint sbagliato è la causa più comune di errori di configurazione.

| Endpoint | A cosa serve | Va inviato ad AgID? |
|---|---|:---:|
| `/Saml2IDP/metadata` | Metadata IdP lato frontoffice (uso interno) | ❌ No |
| `/spidSaml2/metadata` | Metadata SP verso gli IdP SPID | ✅ **Sì** |
| `/static/disco.html` | Pagina di discovery (scelta IdP) | ❌ No |

**Esempi in locale:**
```
https://localhost:9445/Saml2IDP/metadata    ← uso interno (frontoffice → SATOSA)
https://localhost:9445/spidSaml2/metadata   ← da inviare ad AgID per attestazione SPID
https://localhost:9445/static/disco.html    ← pagina di scelta IdP
```

> [!WARNING]
> L'path `/spSaml2/metadata` (senza "id") non esiste e restituisce 302. Il path corretto è `/spidSaml2/metadata`.

### 4. Mappa metadata (distinzione operativa)

| Tipo metadata | Dove si genera | Dove lo trovi | Chi lo usa | Va inviato ad AgID? |
|---|---|---|---|:---:|
| **Metadata Frontoffice SP interno** (`frontoffice_sp.xml`) | Automatico all'avvio di `auth-proxy` (generato da `startup.sh`) | Volume Docker `frontoffice_sp_metadata` (mount in SATOSA `/satosa_proxy/metadata/sp/frontoffice_sp.xml`) | SATOSA per riconoscere il Frontoffice come SP chiamante | ❌ No |
| **Metadata SATOSA IdP interno** (`/Saml2IDP/metadata`) | Runtime SATOSA (dinamico) | `https://<auth-proxy>/Saml2IDP/metadata` | Frontoffice (config `IAM_PROXY_SAML2_IDP_METADATA_URL*`) | ❌ No |
| **Metadata SATOSA SPID pubblico** (`/spidSaml2/metadata`) | Runtime SATOSA (dinamico) | `https://<auth-proxy>/spidSaml2/metadata` | Federazione SPID / AgID / IdP SPID | ✅ **Sì** |

In breve: `frontoffice_sp.xml` serve solo nel canale interno Frontoffice → SATOSA, si autogenera e si riallinea senza interventi da UI; ad AgID si invia il metadata pubblico esposto da SATOSA a `/spidSaml2/metadata`.

Per esportare una copia locale del metadata pubblico da inviare ad AgID usa il backoffice:

- Impostazioni → Login Proxy → Fase 3 → "Esporta metadata AgID"

L'export AgID non e' piu' un passaggio operativo da eseguire manualmente via CLI.

In produzione usa il dominio pubblico del proxy (es. `https://login.ente.gov.it/spidSaml2/metadata`).

### 5. Endpoint pubblici CIE OIDC

Gli endpoint CIE OIDC pubblici sono esposti sotto il path `/CieOidcRp`, che coincide con il `client_id` derivato dal backoffice.

| Endpoint pubblico | Uso |
|---|---|
| `/CieOidcRp/.well-known/openid-federation` | Entity Configuration del relying party da usare per onboarding e verifiche |
| `/.well-known/openid-federation` | Alias root riscritto internamente verso `/CieOidcRp/.well-known/openid-federation` |
| `/CieOidcRp/openid_relying_party/jwks.json` | JWKS pubblico JSON |
| `/CieOidcRp/openid_relying_party/jwks.jose` | JWKS firmato |
| `/CieOidcRp/resolve` | Federation resolve endpoint |
| `/CieOidcRp/fetch` | Federation fetch endpoint |
| `/CieOidcRp/list` | Federation list endpoint |
| `/CieOidcRp/trust_mark_status` | Trust mark status endpoint |
| `/CieOidcRp/oidc/callback` | Redirect URI da registrare lato CIE |

**Esempio base URL pubblico:**

```text
https://login.ente.gov.it/CieOidcRp
```

Da questa base derivano in automatico:

- `client_id`: `https://login.ente.gov.it/CieOidcRp`
- `redirect_uri`: `https://login.ente.gov.it/CieOidcRp/oidc/callback`
- `jwks_uri`: `https://login.ente.gov.it/CieOidcRp/openid_relying_party/jwks.json`
- `signed_jwks_uri`: `https://login.ente.gov.it/CieOidcRp/openid_relying_party/jwks.jose`

Per verificare l'esposizione degli endpoint dal tuo dominio pubblico:

```bash
bash scripts/check-cie-oidc-federation-endpoints.sh -b "https://login.ente.gov.it/CieOidcRp"
```

### 6. Metadata Service Provider (frontoffice)

SATOSA ha bisogno dei metadata del frontoffice (Service Provider) per accettare le richieste di autenticazione. Sono generati automaticamente all'avvio e mantenuti in volume Docker interno (`frontoffice_sp_metadata`), senza file nel repository e senza gestione manuale da UI.

> [!WARNING]
> Il metadata interno Frontoffice SP non è un artifact operativo per l'utente. Se manca o va fuori sync, il runtime IAM proxy lo rigenera automaticamente.

I metadata SP del frontoffice sono **interni a SATOSA** e non vanno inviati ad AgID. Ad AgID si inviano i metadata al path `/spidSaml2/metadata` (esportabili localmente in `metadata/agid/satosa_spid_public_metadata.xml`).

---

## Setup produzione

### Ambiente di produzione: Podman rootless + Portainer

> [!IMPORTANT]
> L'ambiente di produzione ufficiale è **Podman rootless gestito tramite Portainer**.
> Podman rootless garantisce isolamento dei container senza privilegi root sul sistema host, riducendo la superficie d'attacco.

**Requisiti sul server:**

```bash
# Podman + plugin docker-compose compatibile
podman --version          # >= 4.x
podman-compose --version  # oppure docker-compose con DOCKER_HOST=unix:///run/user/<uid>/podman/podman.sock

# Portainer Agent (o Portainer CE/BE già installato)
```

**Deploy tramite Portainer:**

1. In Portainer: *Stacks → Add stack → Repository*
2. URL repository: `https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer.git`
3. Compose path: `docker-compose.yml`  
   (**non** selezionare `docker-compose.override.yml` — vedi sotto)
4. Inserisci le variabili d'ambiente nella sezione *Environment variables* dell'editor  
   (usa `.env.example` come riferimento)
5. Deploy

Per aggiornare lo stack a una nuova versione: modifica `APP_VERSION` nelle *Environment variables* → *Update the stack*.

> [!NOTE]
> In Podman rootless il DNS resolver della rete interna differisce da Docker. Se nei log di `auth-proxy-nginx` compare `resolver 127.0.0.11`, imposta la variabile:
> ```env
> NGINX_DNS_RESOLVER=10.89.0.1
> ```
> Il valore esatto dipende dalla configurazione di rete Podman (`podman network inspect <network-name>` → campo `dns_enabled`/gateway).

---

### Volumi: regola aurea in produzione

> [!WARNING]
> **In produzione usare esclusivamente named volumes. I bind mount sono vietati.**
>
> Il `docker-compose.override.yml` presente nella root del repository aggiunge bind mount locali (`./debug:/var/www/html/public/debug`) pensati **esclusivamente per il debug in sviluppo locale**. In produzione questo file **non deve mai essere incluso nello stack**.

Il `docker-compose.yml` ufficiale usa già solo named volumes per tutti i dati persistenti:

| Volume | Contenuto | Persistenza |
|---|---|---|
| `gil_db_data` | Dati MariaDB | ⚠️ Critico — mai eliminare |
| `gil_ssl_certs` | Certificati TLS server | Da popolare prima del primo avvio |
| `gil_certs` | Certificati mTLS GovPay | Da popolare prima del primo avvio |
| `spid_certs` | Certificati/chiavi SPID | Generati da `metadata-builder` |
| `gil_cieoidc_keys` | Chiavi JWK CIE OIDC | Generati da `metadata-builder` |
| `frontoffice_sp_metadata` | Metadata SP frontoffice | Auto-generati da `auth-proxy` |
| `gil_images` | Immagini/loghi personalizzati | Caricati via Backoffice UI |
| `gil_backups` | Backup DB | Prodotti da script di backup |

**Backup dei volumi:** prima di qualsiasi aggiornamento dello stack esegui un backup dei volumi critici, in particolare `gil_db_data`.

```bash
# Esempio backup volume DB (da adattare all'ambiente Podman)
podman run --rm -v gil_db_data:/data -v $(pwd):/backup alpine \
  tar czf /backup/gil_db_data_$(date +%Y%m%d).tar.gz -C /data .
```

> [!CAUTION]
> **Non usare `docker-compose.override.yml` in produzione.** In Portainer, assicurati che il campo *Compose path* punti **solo** a `docker-compose.yml`. Se viene accidentalmente incluso l'override, i bind mount montano percorsi inesistenti sul server e causano errori di avvio.

---

### URL pubblici

In produzione evita `localhost`/`127.0.0.1` — finiscono nei redirect e nei metadata SAML.

Imposta nel `.env` (o nelle *Environment variables* di Portainer):
```env
BACKOFFICE_PUBLIC_BASE_URL=https://backoffice.ente.gov.it
FRONTOFFICE_PUBLIC_BASE_URL=https://pagamenti.ente.gov.it
```

L'URL pubblico del proxy IAM (`IAM_PROXY_PUBLIC_BASE_URL`) si imposta in **Backoffice → Impostazioni → Login Proxy → Fase 1**.

### Certificati TLS

Per HTTPS server (browser → applicazione), i certificati validi vanno nel volume `gil_ssl_certs`:
- `server.crt`
- `server.key`

In Portainer puoi popolare il volume prima del deploy tramite un container helper o `podman volume import`. Vedi [ssl/README.md](ssl/README.md) per dettagli e troubleshooting permessi.

I certificati in `gil_certs` sono distinti: servono per l'autenticazione client verso GovPay (mTLS app → GovPay). Vedi [certificate/README.md](certificate/README.md).

### Reverse proxy

Pattern consigliato: reverse proxy pubblico (porta 443) → container interno.

Header da preservare:
```
Host: <hostname pubblico>
X-Forwarded-Proto: https
X-Forwarded-For: <IP client>
```

Imposta `SSL=off` nel `.env` se il reverse proxy termina TLS e il container riceve HTTP interno.

### Immagini Docker pre-buildate

In produzione non è necessario clonare il repository completo né avere un ambiente Node.js o Composer installato. Usa direttamente le immagini pubblicate su GHCR:

```bash
docker compose pull
docker compose up -d
```

Le immagini sono pubblicate automaticamente a ogni tag `vX.Y.Z` su:
- `ghcr.io/comune-di-montesilvano/govpay-interaction-layer-backoffice`
- `ghcr.io/comune-di-montesilvano/govpay-interaction-layer-frontoffice`
- `ghcr.io/comune-di-montesilvano/govpay-interaction-layer-auth-proxy-italia`
- `ghcr.io/comune-di-montesilvano/govpay-interaction-layer-auth-proxy-nginx`
- `ghcr.io/comune-di-montesilvano/govpay-interaction-layer-db`

Per un server di produzione sono sufficienti: il `docker-compose.yml` e le variabili d'ambiente (tramite `.env` o Portainer). I volumi Docker vengono popolati automaticamente da `metadata-builder` e dai container stessi.

---

## Rilasci e immagini Docker (GHCR)

### Flusso di rilascio

Ogni release è associata a un tag Git `vX.Y.Z`. Al push del tag, il workflow GitHub Actions `.github/workflows/docker-publish.yml` builda e pubblica automaticamente le immagini Docker su GHCR con i tag `:vX.Y.Z`, `:X.Y` e `:latest`.

```bash
# Avanzare di versione
echo "vX.Y.Z" > VERSION
git add VERSION
git commit -m "chore: release vX.Y.Z"
git tag vX.Y.Z
git push origin main --tags
```

Il workflow parte automaticamente. Puoi seguire l'avanzamento su GitHub → Actions → "Docker Publish".

### Immagini disponibili

| Immagine | Scopo |
|---|---|
| `govpay-interaction-layer-backoffice` | Applicazione backoffice (PHP/Apache) |
| `govpay-interaction-layer-frontoffice` | Applicazione frontoffice (PHP/Apache) |
| `govpay-interaction-layer-auth-proxy-italia` | Proxy SATOSA SPID/CIE |
| `govpay-interaction-layer-auth-proxy-nginx` | Reverse proxy SATOSA (Nginx) |
| `govpay-interaction-layer-db` | Database MariaDB con schema iniziale |
| `govpay-interaction-layer-metadata-builder` | Generazione certificati e metadata SPID/CIE |

Registry: `ghcr.io/comune-di-montesilvano/`

### Sviluppo vs produzione

| | Sviluppo | Produzione |
|---|---|---|
| **Avvio** | `docker compose up -d --build` | `docker compose pull && docker compose up -d` |
| **Immagini** | Build locali da Dockerfile | Pre-buildate da GHCR |
| **Debug mount** | `docker-compose.override.yml` caricato automaticamente | Non presente (o da rimuovere) |
| **Certificati TLS** | Self-signed (auto-generati) | Validi in `ssl/` |

---

## Processi batch

### Inserimento massivo pendenze

Lo script elabora i lotti caricati via interfaccia web (stato `PENDING`) e li invia a GovPay.

```bash
# Esecuzione manuale
docker exec gil-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
```

**Schedulazione crontab** (ogni 5 minuti):
```cron
*/5 * * * * docker exec gil-backoffice php /var/www/html/scripts/cron_pendenze_massive.php >> /var/log/gil_cron.log 2>&1
```

**Schedulazione systemd timer** (consigliato in produzione):

`/etc/systemd/system/gil-pendenze.service`:
```ini
[Unit]
Description=GIL Inserimento Massivo Pendenze
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/docker exec gil-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
```

`/etc/systemd/system/gil-pendenze.timer`:
```ini
[Unit]
Description=GIL Pendenze ogni 5 minuti

[Timer]
OnBootSec=1min
OnUnitActiveSec=5min

[Install]
WantedBy=timers.target
```

---

## Funzionalità Backoffice

### Gestione Pendenze

- Ricerca, inserimento singolo e massivo.
- Dettaglio con azioni: annullamento, stralcio, riattivazione.
- Storico modifiche in `datiAllegati`; origine e operatore tracciati.

### Flussi di Rendicontazione

- Ricerca per data, PSP, stato con paginazione e filtri.
- Dettaglio flusso con IUV, causale, importo, esito.
- **Ricevute on-demand (Biz Events)**: per pagamenti "orfani" (senza dati GovPay locali), un pulsante carica la ricevuta pagoPA via AJAX mostrando debitore, pagatore, PSP, importi e trasferimenti.

### Statistiche

Dashboard con grafici e indicatori.

---

## Workflow di sviluppo

```bash
# Rebuild dopo modifiche a PHP/composer/asset
docker compose up -d --build

# Log in tempo reale
docker compose logs -f

# Shell nel container backoffice
docker compose exec backoffice bash
```

#### Override per sviluppo locale (solo dev)

Il file `docker-compose.override.yml` viene caricato **automaticamente** da `docker compose` se presente nella stessa directory. Aggiunge bind mount locali (`./debug:/var/www/html/public/debug`) su backoffice e frontoffice per utility di test live.

> [!WARNING]
> **`docker-compose.override.yml` è esclusivamente per sviluppo locale.** Non copiarlo né includerlo in produzione. Per avviare esplicitamente senza override:
> ```bash
> docker compose -f docker-compose.yml up -d
> ```
> In Portainer, il campo *Compose path* deve puntare **solo** a `docker-compose.yml`.

Struttura codice:
- `backoffice/` — applicazione backoffice
- `frontoffice/` — applicazione frontoffice
- `app/` — librerie PHP condivise
- `backoffice/templates/` e `frontoffice/templates/` — template Twig

---

## Troubleshooting

### Variabili "annidate" nei file env

Docker Compose **non espande** variabili del tipo `FOO="${BAR}"` negli `env_file`. Usa valori espliciti.

### Script `.sh` e line ending

Gli script devono usare LF (non CRLF). Questo repository forza i line ending via `.gitattributes`.

### Container backoffice non parte

Controlla i log: `docker compose logs govpay-interaction-backoffice`

Cause comuni:
- `.env` mancante o con variabili obbligatorie vuote
- certificati in `ssl/` non leggibili dal container (vedi [ssl/README.md](ssl/README.md))
- database non ancora pronto (il container riprova automaticamente)

---

## Struttura del progetto

```
GovPay-Interaction-Layer/
├── docker-compose.yml
├── docker-compose.override.yml   # override sviluppo (debug mount — non usare in prod)
├── Dockerfile
├── .env                          # da creare (non versionato)
├── .env.example                  # template per .env
├── .github/workflows/
│   ├── ci.yml                    # CI: test PHP su push/PR
│   └── docker-publish.yml        # CD: build e push immagini GHCR su tag vX.Y.Z
├── backoffice/               # applicazione backoffice (Slim 4 + Twig)
├── frontoffice/              # applicazione frontoffice
├── app/                      # codice PHP condiviso
├── auth-proxy/               # proxy SPID/CIE (SATOSA)
│   ├── Dockerfile            # immagine auth-proxy-italia (wrapper con progetto SATOSA baked-in)
│   ├── startup.sh            # inizializzazione container: fetch config backoffice, envsubst, JWK, patch SATOSA, watchdog
│   └── nginx/Dockerfile      # immagine auth-proxy-nginx
├── docker/
│   └── db/Dockerfile         # immagine govpay-db
├── metadata/                 # setup metadata/cert SPID (setup-sp.ps1/setup-sp.sh)
├── ssl/                      # certificati TLS server (browser → app) — vedi ssl/README.md
├── certificate/              # certificati client GovPay (app → GovPay) — vedi certificate/README.md
├── img/                      # immagini/loghi — vedi img/README.md
├── scripts/                  # script di utilità runtime (cron)
├── migrations/               # migrazioni DB
├── govpay-clients/           # client API GovPay generati
├── pagopa-clients/           # client API pagoPA generati
└── debug/                    # tool debug (montati solo in sviluppo via override)
```

---

## Contribuire

1. Fork del repository
2. Crea un branch: `git checkout -b feature/nuova-funzionalita`
3. Commit: `git commit -m "feat: descrizione"`
4. Push: `git push origin feature/nuova-funzionalita`
5. Apri una Pull Request

## Supporto

- Issues: https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer/issues

