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

## Avvio rapido

### Prerequisiti

- Docker Desktop (o Docker Engine + plugin `docker compose`)
- Git
- Porte libere sul tuo host (default): `8443` (backoffice), `8444` (frontoffice)

### 1. Clona il repository

```bash
git clone https://github.com/Comune-di-Montesilvano/GovPay-Interaction-Layer.git
cd GovPay-Interaction-Layer
```

### 2. Crea il file `.env`

Il file `.env` non è versionato per motivi di sicurezza. Usa il file d'esempio come base:

```bash
cp .env.example .env
```

Compila le variabili secondo le tue esigenze. Il file è commentato sezione per sezione.

### 3. Avvia i container

**Sviluppo** — build locale dalle sorgenti:
```bash
# primo avvio o dopo modifiche a Dockerfile/PHP/asset
docker compose up -d --build

# avvii successivi (nessun rebuild)
docker compose up -d
```

**Produzione** — usa le immagini pre-buildate da GHCR (nessun build richiesto):
```bash
# scarica le immagini pubblicate su GHCR
docker compose pull

# avvia i container
docker compose up -d
```

> Vedi [Rilasci e immagini Docker (GHCR)](#rilasci-e-immagini-docker-ghcr) per i dettagli sulle immagini disponibili.

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

GIL si integra con i seguenti servizi esterni. Tutte le credenziali vanno configurate nel `.env` (vedi `.env.example`).

| Integrazione | Scopo | Variabili `.env` |
|---|---|---|
| **GovPay** | Gestione pendenze, pagamenti, flussi di rendicontazione, backoffice | `GOVPAY_*_URL`, `AUTHENTICATION_GOVPAY` |
| **pagoPA Checkout** | Avvio pagamenti online tramite redirect al portale pagoPA | `PAGOPA_CHECKOUT_EC_BASE_URL`, `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY` |
| **pagoPA Biz Events** | Recupero on-demand delle ricevute di pagamento dal dettaglio flusso | `BIZ_EVENTS_HOST`, `BIZ_EVENTS_API_KEY` |
| **App IO** | Invio messaggi e avvisi di pagamento ai cittadini (con CTA e dati avviso pagoPA) | `APP_IO_FEATURE_LEVEL_TYPE` (opz.); chiave API configurabile per tipologia |
| **SPID/CIE** | Autenticazione federata per il frontoffice cittadini | Backoffice → Impostazioni → Login Proxy — vedi sezione [SPID/CIE](#spidcie-opzionale) |

### Certificati client GovPay (mTLS)

Se `AUTHENTICATION_GOVPAY=ssl` o `sslheader`, GIL autentica le chiamate verso GovPay tramite certificato X.509 client. I file del certificato vanno messi nella cartella `certificate/` nella root del progetto:

```
certificate/
├── certificate.cer    ← certificato client
└── private_key.key    ← chiave privata
```

Il Dockerfile copia automaticamente questa cartella all'interno del container in `/var/www/certificate/`. Imposta i path container nel `.env`:

```env
GOVPAY_TLS_CERT=/var/www/certificate/certificate.cer
GOVPAY_TLS_KEY=/var/www/certificate/private_key.key
```

> [!WARNING]
> I file in `certificate/` vengono **inclusi nell'immagine Docker** al momento della build (non montati come volume). Dopo aver aggiornato i certificati è necessario ricostruire l'immagine con `docker compose up -d --build`. Un certificato scaduto o mancante blocca silenziosamente tutte le chiamate a GovPay.

Vedi [certificate/README.md](certificate/README.md) per dettagli su nomi file accettati e provenienza dei certificati.

---

## SPID/CIE (opzionale)

Il proxy SPID/CIE è basato su **IAM Proxy Italia (SATOSA)**. I container `auth-proxy` e `auth-proxy-nginx` sono sempre inclusi nello stack — SATOSA viene avviato o fermato automaticamente in base alla configurazione nel backoffice.

> **Stato attuale**: SPID è funzionante. L'integrazione CIE OIDC è in fase di sviluppo/test.

### Prerequisiti aggiuntivi

- Porta libera: `9445` (proxy IAM, configurabile con `AUTH_PROXY_PORT` in `.env`)

### 1. Genera certificati e chiavi CIE OIDC

Prima del primo avvio, genera i certificati SPID e le chiavi JWK CIE OIDC:

```bash
docker compose run --rm metadata-builder setup
```

I certificati vengono scritti nel volume Docker `govpay_spid_certs`; le chiavi CIE OIDC nel volume `gil_cieoidc_keys`.

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

### 3. Endpoint esposti da SATOSA

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

Per esportare una copia locale del metadata pubblico da inviare ad AgID:

- da backoffice: Impostazioni → Login Proxy → Fase 3 → "Esporta metadata AgID"
- da CLI:

```bash
docker compose run --rm metadata-builder export-agid
```

In produzione usa il dominio pubblico del proxy (es. `https://login.ente.gov.it/spidSaml2/metadata`).

### 5. Metadata Service Provider (frontoffice)

SATOSA ha bisogno dei metadata del frontoffice (Service Provider) per accettare le richieste di autenticazione. Sono generati automaticamente all'avvio e mantenuti in volume Docker interno (`frontoffice_sp_metadata`), senza file nel repository e senza gestione manuale da UI.

> [!WARNING]
> Il metadata interno Frontoffice SP non è un artifact operativo per l'utente. Se manca o va fuori sync, il runtime IAM proxy lo rigenera automaticamente.

I metadata SP del frontoffice sono **interni a SATOSA** e non vanno inviati ad AgID. Ad AgID si inviano i metadata al path `/spidSaml2/metadata` (esportabili localmente in `metadata/agid/satosa_spid_public_metadata.xml`).

---

## Setup produzione

### URL pubblici

In produzione evita `localhost`/`127.0.0.1` — finiscono nei redirect e nei metadata SAML.

Imposta nel `.env`:
```env
BACKOFFICE_PUBLIC_BASE_URL=https://backoffice.ente.gov.it
FRONTOFFICE_PUBLIC_BASE_URL=https://pagamenti.ente.gov.it
```

L'URL pubblico del proxy IAM (`IAM_PROXY_PUBLIC_BASE_URL`) si imposta in **Backoffice → Impostazioni → Login Proxy → Fase 1**.

### Certificati TLS

Per HTTPS server (browser → applicazione), metti i certificati validi in `ssl/`:
- `ssl/server.crt`
- `ssl/server.key`

Vedi [ssl/README.md](ssl/README.md) per dettagli e troubleshooting permessi.

I certificati in `certificate/` sono distinti: servono per l'autenticazione client verso GovPay (mTLS app → GovPay). Vedi [certificate/README.md](certificate/README.md).

### Reverse proxy

Pattern consigliato: reverse proxy pubblico (porta 443) → container interno.

Header da preservare:
```
Host: <hostname pubblico>
X-Forwarded-Proto: https
X-Forwarded-For: <IP client>
```

Imposta `SSL=false` nel `.env` se il reverse proxy termina TLS e il container riceve HTTP interno.

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

Per un server di produzione sono sufficienti: `.env`, la cartella `ssl/`, `certificate/` e il `docker-compose.yml`. I volumi Docker (`govpay_spid_certs`, `gil_cieoidc_keys`, ecc.) vengono popolati automaticamente da `metadata-builder` e dai container stessi.

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

#### Override per sviluppo locale

Il file `docker-compose.override.yml` viene caricato **automaticamente** da `docker compose` se presente nella stessa directory. Aggiunge il mount `./debug:/var/www/html/public/debug` su backoffice e frontoffice per utility di test live.

> [!WARNING]
> In produzione, rimuovi o non copiare `docker-compose.override.yml`. Per avviare esplicitamente senza override: `docker compose -f docker-compose.yml up -d`.

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

