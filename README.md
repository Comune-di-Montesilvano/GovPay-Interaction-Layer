# GovPay Interaction Layer (GIL)

Piattaforma containerizzata (PHP/Apache + UI) per migliorare il flusso di lavoro degli enti che usano GovPay come soluzione PagoPA.

Include:
- **Backoffice** per operatori (gestione pendenze, flussi di rendicontazione, ricevute pagoPA on-demand)
- **Frontoffice** cittadini/sportello
- (Opzionale) **proxy SPID/CIE** integrabile via profilo Docker Compose

Repository: https://github.com/mirkochipdotcom/GovPay-Interaction-Layer.git  
License: European Union Public Licence v1.2 (EUPL-1.2)

---

## Indice

- [Avvio rapido](#avvio-rapido)
- [Configurazione: .env](#configurazione-env)
- [Integrazioni API esterne](#integrazioni-api-esterne)
- [SPID/CIE (opzionale)](#spidcie-opzionale)
- [Setup produzione](#setup-produzione)
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
git clone https://github.com/mirkochipdotcom/GovPay-Interaction-Layer.git
cd GovPay-Interaction-Layer
```

### 2. Crea il file `.env`

Il file `.env` non è versionato per motivi di sicurezza. Usa il file d'esempio come base:

```bash
cp .env.example .env
```

Compila le variabili secondo le tue esigenze. Il file è commentato sezione per sezione.

### 3. Avvia i container

```bash
# primo avvio (o dopo modifiche a Dockerfile/composer)
docker compose up -d --build

# avvii successivi
docker compose up -d
```

### 4. Primo accesso

- Backoffice: https://localhost:8443
- Frontoffice: https://localhost:8444

Se non hai certificati TLS personalizzati in `ssl/`, vengono generati self-signed automaticamente; il browser mostrerà un avviso (normale in locale). Vedi [ssl/README.md](ssl/README.md).

### 5. Credenziali iniziali

Al primo avvio viene creato un utente `superadmin` con le credenziali configurate in `.env`:

```env
ADMIN_EMAIL=admin@ente.gov.it
ADMIN_PASSWORD=password_sicura
```

Il seed è idempotente: viene eseguito solo se non esiste ancora un superadmin nel DB.

---

## Configurazione: `.env`

Il file `.env` è il punto unico di configurazione per Docker Compose e per tutti i servizi applicativi. Usa `.env.example` come base:

```bash
cp .env.example .env
```

Il file `.env.example` contiene tutte le variabili disponibili con commenti esplicativi sezione per sezione. Le variabili minime obbligatorie per usare le funzioni principali sono:

- `ID_DOMINIO`, `ID_A2A`, `APP_ENTITY_IPA_CODE` — identità ente
- `GOVPAY_*_URL` + `AUTHENTICATION_GOVPAY` + credenziali — connessione a GovPay
- `ADMIN_EMAIL`, `ADMIN_PASSWORD` — superadmin iniziale
- `PAGOPA_CHECKOUT_EC_BASE_URL` + `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY` — pagamenti online
- `BIZ_EVENTS_HOST` + `BIZ_EVENTS_API_KEY` — ricevute on-demand

---

## Integrazioni API esterne

GIL si integra con i seguenti servizi esterni. Tutte le credenziali vanno configurate nel `.env` (vedi `.env.example`).

| Integrazione | Scopo | Variabili `.env` |
|---|---|---|
| **GovPay** | Gestione pendenze, pagamenti, flussi di rendicontazione, backoffice | `GOVPAY_*_URL`, `AUTHENTICATION_GOVPAY` |
| **pagoPA Checkout** | Avvio pagamenti online tramite redirect al portale pagoPA | `PAGOPA_CHECKOUT_EC_BASE_URL`, `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY` |
| **pagoPA Biz Events** | Recupero on-demand delle ricevute di pagamento dal dettaglio flusso | `BIZ_EVENTS_HOST`, `BIZ_EVENTS_API_KEY` |
| **App IO** | Invio messaggi e avvisi di pagamento ai cittadini (con CTA e dati avviso pagoPA) | `APP_IO_FEATURE_LEVEL_TYPE` (opz.); chiave API configurabile per tipologia |
| **SPID/CIE** | Autenticazione federata per il frontoffice cittadini | `.iam-proxy.env` — vedi sezione [SPID/CIE](#spidcie-opzionale) |

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

Il proxy SPID/CIE è basato su **IAM Proxy Italia (SATOSA)** e si avvia con il profilo Docker Compose `iam-proxy`.

> **Stato attuale**: SPID è funzionante. L'integrazione CIE OIDC è in fase di sviluppo/test e non è ancora abilitata nel frontoffice.

### Prerequisiti aggiuntivi

- Porta libera: `9445` (proxy IAM, configurabile con `IAM_PROXY_HTTP_PORT`)
- Certificati SPID nel volume Docker `govpay_spid_certs` (generati automaticamente da `metadata/setup-sp.*` se mancanti)

### 1. Configura `.iam-proxy.env`

Copia l'example e personalizza le variabili essenziali:

```bash
cp .iam-proxy.env.example .iam-proxy.env
```

Variabili essenziali da modificare:

```env
# URL pubblico del proxy, visto dal browser
# DEV: https://127.0.0.1:9445  |  PROD: https://login.ente.gov.it
IAM_PROXY_PUBLIC_BASE_URL=https://127.0.0.1:9445

# Secret di cifratura SATOSA — genera valori random >= 32 caratteri
SATOSA_SALT=cambia_questo_valore_random_32_chars
SATOSA_STATE_ENCRYPTION_KEY=cambia_questo_valore_random_32_chars
SATOSA_ENCRYPTION_KEY=cambia_questo_valore_random_32_chars
SATOSA_USER_ID_HASH_SALT=cambia_questo_valore_random_32_chars

# Dati ente per metadata SPID
SATOSA_ORGANIZATION_DISPLAY_NAME_IT=Nome Ente
SATOSA_ORGANIZATION_IDENTIFIER=PA:IT-CODICEIPA
SATOSA_CONTACT_PERSON_EMAIL_ADDRESS=supporto@ente.gov.it

# Abilita SPID (CIE OIDC non ancora integrato nel frontoffice)
ENABLE_SPID=true
ENABLE_CIE=false
ENABLE_CIE_OIDC=false
```

Il file `.iam-proxy.env.example` contiene tutte le variabili disponibili con commenti.

### 2. Avvia il profilo iam-proxy

```bash
docker compose --profile iam-proxy up -d --build
```

Al primo avvio il servizio `sync-iam-proxy` scarica SATOSA da GitHub e aggiorna la directory di istanza in `iam-proxy/iam-proxy-italia-project/`.

Prima di avviare il profilo `iam-proxy`, genera cert e metadata locali:
```powershell
powershell -ExecutionPolicy Bypass -File .\metadata\setup-sp.ps1
```

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
| **Metadata Frontoffice SP interno** (`frontoffice_sp.xml`) | Automatico all'avvio profilo `iam-proxy` (service `init-frontoffice-sp-metadata` + `refresh-frontoffice-sp-metadata`) | Volume Docker `frontoffice_sp_metadata` (mount in SATOSA `/satosa_proxy/metadata/sp/frontoffice_sp.xml`) | SATOSA per riconoscere il Frontoffice come SP chiamante | ❌ No |
| **Metadata SATOSA IdP interno** (`/Saml2IDP/metadata`) | Runtime SATOSA (dinamico) | `https://<iam-proxy>/Saml2IDP/metadata` | Frontoffice (config `IAM_PROXY_SAML2_IDP_METADATA_URL*`) | ❌ No |
| **Metadata SATOSA SPID pubblico** (`/spidSaml2/metadata`) | Runtime SATOSA (dinamico) | `https://<iam-proxy>/spidSaml2/metadata` | Federazione SPID / AgID / IdP SPID | ✅ **Sì** |

In breve: `frontoffice_sp.xml` serve solo nel canale interno Frontoffice → SATOSA; ad AgID si invia il metadata pubblico esposto da SATOSA a `/spidSaml2/metadata`.

Per esportare una copia locale del metadata pubblico da inviare ad AgID (consigliato):

```powershell
powershell -ExecutionPolicy Bypass -File .\metadata\setup-sp.ps1 -MetadataOnly
```

In produzione usa il dominio pubblico del proxy (es. `https://login.ente.gov.it/spidSaml2/metadata`).

### 5. Metadata Service Provider (frontoffice)

SATOSA ha bisogno dei metadata del frontoffice (Service Provider) per accettare le richieste di autenticazione. Sono generati automaticamente all'avvio e mantenuti in volume Docker interno (`frontoffice_sp_metadata`), senza file nel repository.

Per rigenerarli manualmente (es. dopo aver cambiato `FRONTOFFICE_PUBLIC_BASE_URL`):

```powershell
docker compose --profile iam-proxy up -d --force-recreate init-frontoffice-sp-metadata refresh-frontoffice-sp-metadata iam-proxy-italia
```

> [!WARNING]
> Il metadata interno Frontoffice SP non e' piu' su filesystem di progetto. Qualsiasi rigenerazione interna passa dai servizi Docker `init-frontoffice-sp-metadata` / `refresh-frontoffice-sp-metadata`.

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

E nel `.iam-proxy.env` (se usi SPID):
```env
IAM_PROXY_PUBLIC_BASE_URL=https://login.ente.gov.it
```

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

---

## Processi batch

### Inserimento massivo pendenze

Lo script elabora i lotti caricati via interfaccia web (stato `PENDING`) e li invia a GovPay.

```bash
# Esecuzione manuale
docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
```

**Schedulazione crontab** (ogni 5 minuti):
```cron
*/5 * * * * docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php >> /var/log/gil_cron.log 2>&1
```

**Schedulazione systemd timer** (consigliato in produzione):

`/etc/systemd/system/gil-pendenze.service`:
```ini
[Unit]
Description=GIL Inserimento Massivo Pendenze
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/bin/docker exec govpay-interaction-backoffice php /var/www/html/scripts/cron_pendenze_massive.php
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
docker compose exec govpay-interaction-backoffice bash
```

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
├── Dockerfile
├── .env                      # da creare (non versionato)
├── .iam-proxy.env            # da creare (solo se usi SPID/CIE)
├── .iam-proxy.env.example    # template per .iam-proxy.env
├── backoffice/               # applicazione backoffice (Slim 4 + Twig)
├── frontoffice/              # applicazione frontoffice
├── app/                      # codice PHP condiviso
├── iam-proxy/                # proxy SPID/CIE (SATOSA) — vedi iam-proxy/PERSONALIZZAZIONE.md
├── metadata/                 # setup metadata/cert SPID (setup-sp.ps1/setup-sp.sh)
├── ssl/                      # certificati TLS server (browser → app) — vedi ssl/README.md
├── certificate/              # certificati client GovPay (app → GovPay) — vedi certificate/README.md
├── img/                      # immagini/loghi — vedi img/README.md
├── scripts/                  # script di utilità runtime (cron, sync iam-proxy)
├── migrations/               # migrazioni DB
├── govpay-clients/           # client API GovPay generati
├── pagopa-clients/           # client API pagoPA generati
└── debug/                    # tool debug (montati solo in sviluppo)
```

---

## Contribuire

1. Fork del repository
2. Crea un branch: `git checkout -b feature/nuova-funzionalita`
3. Commit: `git commit -m "feat: descrizione"`
4. Push: `git push origin feature/nuova-funzionalita`
5. Apri una Pull Request

## Supporto

- Issues: https://github.com/mirkochipdotcom/GovPay-Interaction-Layer/issues

