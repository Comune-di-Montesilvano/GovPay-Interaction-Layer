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

Vedi [certificate/README.md](certificate/README.md) per i certificati client GovPay (`ssl`/`sslheader`).

---

## SPID/CIE (opzionale)

Il proxy SPID/CIE è basato su **IAM Proxy Italia (SATOSA)** e si avvia con il profilo Docker Compose `iam-proxy`.

> **Stato attuale**: SPID è funzionante. L'integrazione CIE OIDC è in fase di sviluppo/test e non è ancora abilitata nel frontoffice.

### Prerequisiti aggiuntivi

- Porta libera: `9445` (proxy IAM, configurabile con `IAM_PROXY_HTTP_PORT`)
- Certificati SPID in `iam-proxy/spid-certs/` (generati automaticamente al primo avvio se mancanti)

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

Al primo avvio il servizio `iam-proxy-init` scarica SATOSA da GitHub e genera la directory di istanza in `.local/iam-proxy-italia-project/` (ignorata da git).

Per inizializzare manualmente (es. debug su Windows):
```powershell
# Task VS Code: "init-iam-proxy-italia-force"
powershell -ExecutionPolicy Bypass -File .\scripts\init-iam-proxy-italia.ps1 -Force
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

### 4. Metadata Service Provider (frontoffice)

SATOSA ha bisogno dei metadata del frontoffice (Service Provider) per accettare le richieste di autenticazione. Vengono generati automaticamente in `iam-proxy/metadata-sp/` al primo avvio.

Per rigenerarli manualmente (es. dopo aver cambiato `FRONTOFFICE_PUBLIC_BASE_URL`):

```bash
docker compose --profile iam-proxy run --rm init-sp-metadata
docker compose --profile iam-proxy restart iam-proxy-italia
```

> [!WARNING]
> **Non modificare manualmente** i file in `iam-proxy/metadata-sp/`. Sono firmati digitalmente: qualsiasi modifica manuale invalida la firma e SATOSA li rifiuterà. Modifica le variabili d'ambiente e rigenera sempre tramite il container.

I metadata SP del frontoffice sono **interni a SATOSA** e non vanno inviati ad AgID. Ad AgID si inviano i metadata al path `/spidSaml2/metadata`.

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
├── ssl/                      # certificati TLS server (browser → app) — vedi ssl/README.md
├── certificate/              # certificati client GovPay (app → GovPay) — vedi certificate/README.md
├── img/                      # immagini/loghi — vedi img/README.md
├── scripts/                  # script di utilità (cron, init)
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

Questo repository richiede due file di configurazione:

```bash
# File di configurazione principale (compose + applicativo)
touch .env

# File di configurazione IAM Proxy (solo se abiliti SPID/CIE)
cp .iam-proxy.env.example .iam-proxy.env
```

**Note**:
- `.env` contiene tutte le variabili necessarie a compose e ai servizi applicativi (backoffice, frontoffice, database). Questo file **non è versionato** per motivi di sicurezza: devi crearlo manualmente nel tuo ambiente configurando i valori secondo le tue esigenze.
- `.iam-proxy.env` ha un file example; copialo se abiliti il profilo `iam-proxy` (vedi sotto).

#### Dati minimi necessari (GovPay e PagoPA Checkout)

L'app si avvia anche lasciando i valori di default, ma per usare effettivamente le funzioni principale servono endpoint e credenziali reali.

**Obbligatorio**: Compila almeno:
- `ID_DOMINIO`, `ID_A2A`, `APP_ENTITY_IPA_CODE` (identità ente)
- URL API GovPay (`GOVPAY_*_URL`) e autenticazione (`AUTHENTICATION_GOVPAY` + credenziali)
- Credenziali superadmin iniziale (`ADMIN_EMAIL`, `ADMIN_PASSWORD`)

**Per pagamenti online** (Checkout): `PAGOPA_CHECKOUT_EC_BASE_URL` + `PAGOPA_CHECKOUT_SUBSCRIPTION_KEY`

**Per ricevute on-demand** (Biz Events nel dettaglio flusso): `BIZ_EVENTS_HOST` + `BIZ_EVENTS_API_KEY`

Dettagli completi: vedi tabella sopra e file di commenti nel docker-compose.

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

### Variabili essenziali del `.env`

Il file `.env` è il punto unico di configurazione per compose e per tutti i servizi applicativi.

#### Porte e routing

```env
# Porta HTTPS per backoffice (default 8443)
BACKOFFICE_HTTP_PORT=8443

# Porta HTTPS per frontoffice (default 8444)
FRONTOFFICE_HTTP_PORT=8444

# URL pubblici (frontend accede a questi URL)
BACKOFFICE_PUBLIC_BASE_URL=https://localhost:8443
FRONTOFFICE_PUBLIC_BASE_URL=https://localhost:8444
```

#### Database locale (MariaDB)

```env
DB_ROOT_PASSWORD=root_password_sicura
DB_NAME=govpay_backoffice
DB_USER=govpay_bo
DB_PASSWORD=password_sicura
DB_USER_CITTADINI=govpay_fo
DB_PASSWORD_CITTADINI=password_sicura
```

#### Ente e configurazione applicativa

```env
# Identificatives ente
ID_DOMINIO=codice_fiscale_ente           # PA / P.IVA dell'ente
ID_A2A=id_app_govpay                     # ID applicazione su GovPay
APP_ENTITY_IPA_CODE=d_x000               # Codice IPA ente (trovabile su indicePA)

# Dati organizzazione (mostrati nell'interfaccia)
APP_ENTITY_NAME=Nome Ente Creditore
APP_ENTITY_SUFFIX=(Provincia)
APP_ENTITY_GOVERNMENT=true # se è PA
APP_ENTITY_URL=https://ente.gov.it
APP_LOGO_SRC=https://ente.gov.it/logo.png
APP_LOGO_TYPE=image/png

# Contatti supporto
APP_SUPPORT_EMAIL=supporto@ente.gov.it
APP_SUPPORT_PHONE=+39 XXXXXXXXXX
APP_SUPPORT_HOURS=Lun-Ven 09:00-17:00
APP_SUPPORT_LOCATION=Via esempio, NN, CAP Città
```

#### GovPay: credenziali e endpoint

```env
# Tipo di autenticazione verso GovPay
AUTHENTICATION_GOVPAY=basic              # opzioni: basic, form, ssl, sslheader

# Se AUTHENTICATION_GOVPAY=basic o form:
GOVPAY_USER=username_su_govpay
GOVPAY_PASSWORD=password_govpay

# Se AUTHENTICATION_GOVPAY=ssl o sslheader:
GOVPAY_TLS_CERT=certificate/client.crt
GOVPAY_TLS_KEY=certificate/client.key
GOVPAY_TLS_KEY_PASSWORD=                 # opzionale se chiave non è cifrata

# URL API GovPay (completa con il tuo host/porta)
GOVPAY_PENDENZE_URL=https://govpay.example.org/govpay/api/v1/pendenze
GOVPAY_PAGAMENTI_URL=https://govpay.example.org/govpay/api/v1/pagamenti
GOVPAY_RAGIONERIA_URL=https://govpay.example.org/govpay/api/v1/ragioneria
GOVPAY_BACKOFFICE_URL=https://govpay.example.org/govpay/api/v1/backoffice
GOVPAY_PENDENZE_PATCH_URL=https://govpay.example.org/govpay/api/v1/pendenze
```

#### PagoPA Checkout e Biz Events (ricevute on-demand)

```env
# pagoPA Checkout EC API
PAGOPA_CHECKOUT_EC_BASE_URL=https://api.dev.platform.pagopa.it/checkout/ec/v1
PAGOPA_CHECKOUT_SUBSCRIPTION_KEY=subscription-key-acquistata
PAGOPA_CHECKOUT_COMPANY_NAME=Nome Ente

# URL di return (opzionali, usati dal modulo Checkout)
PAGOPA_CHECKOUT_RETURN_OK_URL=https://localhost:8444/pagamento-ok
PAGOPA_CHECKOUT_RETURN_CANCEL_URL=https://localhost:8444/pagamento-annullato
PAGOPA_CHECKOUT_RETURN_ERROR_URL=https://localhost:8444/pagamento-errore

# pagoPA Biz Events API (per ricevute)
BIZ_EVENTS_HOST=https://api.dev.platform.pagopa.it/bizevents/service/v1
BIZ_EVENTS_API_KEY=subscription-key-bizevents
```

#### Email (notifiche backoffice)

```env
BACKOFFICE_MAILER_DSN=smtp://user:pass@smtp.example.org:587?encryption=tls
BACKOFFICE_MAILER_FROM_ADDRESS=noreply@ente.gov.it
BACKOFFICE_MAILER_FROM_NAME=GovPay Backoffice
```

#### Credenziali iniziali (superadmin al primo avvio)

```env
ADMIN_EMAIL=admin@ente.gov.it
ADMIN_PASSWORD=password_superadmin_sicura_>=12_chars
```

#### Debug (opzionale, solo development)

```env
APP_DEBUG=false           # Mostra stack trace su errori (mai true in prod!)
ENABLE_DEBUG_TOOL=false   # Abilita toolbar debug al backoffice
SSL=false                 # Se con reverse proxy HTTP interno
```

---

## Configurazione: file `.iam-proxy.env`

---

## SPID/CIE

Il sistema di autenticazione usa **IAM Proxy Italia (SATOSA)** come componente standard.

### Configurazione minima IAM Proxy

Se usi il profilo Docker Compose `iam-proxy`, compila nel `.iam-proxy.env`:

```env
# URL pubblico del proxy SPID/CIE (visto dal browser)
# Esempi:
# - DEV locale: https://127.0.0.1:9445
# - PROD: https://login.ente.gov.it (senza porta se 443)
IAM_PROXY_PUBLIC_BASE_URL=https://127.0.0.1:9445

# Secret per SATOSA (genera random string >= 32 chars)
SATOSA_SALT=CHANGE_THIS_RANDOM_STRING_32_CHARS_LONG
SATOSA_STATE_ENCRYPTION_KEY=CHANGE_THIS_RANDOM_STRING_32_CHARS_LONG
SATOSA_ENCRYPTION_KEY=CHANGE_THIS_RANDOM_STRING_32_CHARS_LONG
SATOSA_USER_ID_HASH_SALT=CHANGE_THIS_RANDOM_STRING_32_CHARS_LONG

# Ente / contact person (per metadata SPID)
SATOSA_ORGANIZATION_DISPLAY_NAME_IT=Nome Ente
SATOSA_ORGANIZATION_IDENTIFIER=PA:IT-XXXX  # PA:IT-<codice IPA>
SATOSA_CONTACT_PERSON_EMAIL_ADDRESS=supporto@ente.gov.it

# Abilitazioni (true/false)
ENABLE_SPID=true
ENABLE_CIE=true
ENABLE_CIE_OIDC=false  # Non ancora integrato nel frontoffice
```

Copia il file example se non esiste già:
```bash
cp .iam-proxy.env.example .iam-proxy.env
```

### Avvio IAM Proxy

Con profilo `iam-proxy`:

```bash
docker compose --profile iam-proxy up -d --build
```

Prime volte, il servizio `iam-proxy-init` scarica SATOSA da GitHub e genera i file di istanza in `.local/iam-proxy-italia-project/` (cartella riservata allo strumento, ignorata da git).

### Configurazione IAM Proxy
- `iam-proxy-italia` (SATOSA uWSGI)
- `satosa-nginx` (TLS + static + reverse uWSGI)

La directory di istanza di SATOSA viene generata in **`.local/iam-proxy-italia-project/`** (fuori dal repository) per mantenere il repository pulito.

Nota importante: L'integrazione del frontoffice con il proxy PHP via `/proxy-home.php` è legacy. Per nuove integrazioni, utilizza gli endpoint SATOSA direttamente secondo gli example disponibili.

### Avvio rapido SPID/CIE (locale)

1) **Configure il `.iam-proxy.env`** (vedi sezione "Configurazione minima IAM Proxy" sopra)

2) **Avvia con profilo iam-proxy**:

   ```bash
   docker compose --profile iam-proxy up -d --build
   ```

3) **Endpoint principali esposti da SATOSA**:

   | Endpoint | Scopo | Chi lo usa |  
   |---|---|---|  
   | `/Saml2IDP/metadata` | Metadata IdP di SATOSA | Il frontoffice (SP interno) |  
   | `/spidSaml2/metadata` | Metadata SP di SATOSA verso SPID | IdP SPID/CIE, aggiungere al registro SPID |  
   | `/static/disco.html` | Pagina discovery (scelta IdP) | Browser utente |

   **Esempio su localhost**: `https://localhost:9445/spidSaml2/metadata` ← **invia ad AgID per attestazione SPID**

4) **Metadata Service Provider (frontoffice)**: I metadata SP del frontoffice vengono generati automaticamente in `iam-proxy/metadata-sp/` basandosi su `FRONTOFFICE_PUBLIC_BASE_URL` e `ID_DOMINIO`. Se cambiano gli URL pubblici, rigenerare con:

   ```bash
   docker compose --profile iam-proxy run --rm init-sp-metadata
   ```

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

#### Rigenerazione dei metadata SP

I metadata SP vengono generati automaticamente al primo avvio via il servizio `init-sp-metadata`. Per rigenerarli manualmente (ad esempio dopo aver cambiato `FRONTOFFICE_PUBLIC_BASE_URL`):

```bash
# Rigenera i metadata usando le variabili d'ambiente del container
docker compose --profile iam-proxy run --rm init-sp-metadata

# Poi riavvia SATOSA per caricare i nuovi metadata
docker compose --profile iam-proxy restart iam-proxy-italia
```

> [!WARNING]
> **Non modificare manualmente** i file XML in `iam-proxy/metadata-sp/`. I metadata SP sono firmati digitalmente: qualsiasi modifica manuale invalida la firma e SATOSA rifiuterà i metadata corrotti. Modifica sempre le variabili d'ambiente e rigenera via container.

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
