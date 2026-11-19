# Frontoffice senza accesso al database — design

Data: 2026-11-19. Solo spec: l'implementazione è un lavoro dedicato successivo.

## Perché

Il frontoffice è il servizio esposto ai cittadini ed è un sidecar del backoffice: tutte le
operazioni GovPay/pagoPA passano dalle API interne `/api/frontoffice/*` (Bearer
`MASTER_TOKEN`). Riceve però ancora credenziali MariaDB (utente `govpay_cittadini`, `SELECT`
su tutto lo schema + scrittura su `rate_limit_buckets`) che, dall'analisi del codice, non usa
più. Credenziali inutilizzate sul servizio più esposto allargano la superficie in caso di RCE
(lettura diretta di tutto lo schema) e tengono in vita un utente DB, uno script di init, due
variabili obbligatorie e righe di compose da mantenere.

## Stato attuale (verificato su `main`, 2026-11-19)

**Codice del frontoffice** (`frontoffice/public/index.php`, unico file PHP del servizio):

- L'unico accesso al DB è `frontoffice_build_voci()` (`EntrateRepository::findDetails`, dettagli
  di contabilità della tipologia). La funzione è **definita ma mai chiamata**: le voci delle
  pendenze sono costruite dal backoffice (`POST /api/frontoffice/pendenze`).
- `use App\Database\EntrateRepository` serve solo a quella funzione.
- Nessuna chiamata a `Config::get()` / `SettingsRepository` / `Connection` / `RateLimiter`:
  la configurazione arriva da `GET /api/frontoffice/config` (watchdog + cache su file), il rate
  limit da `POST /api/frontoffice/rate-limit/check`. Le classi `App\` usate (`Config::assetUrl`,
  `getVersionInfo`, `getRepositoryUrl`, `Logger`, `ValidationService`) non toccano il DB.
- `docker-setup.sh` con `APP_SUITE=frontoffice` salta attesa DB e migrazioni.

**Configurazione che ne deriva:**

| Dove | Cosa | Stato |
|---|---|---|
| `docker-compose.yml`, `frontoffice` | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_PASSWORD_CITTADINI` | non letti (il commento "solo per lettura config (settings table)" non è più vero) |
| `docker-compose.yml`, `frontoffice` | `depends_on: db` | ridondante: dipende già da `backoffice` healthy, che dipende da `db` |
| `docker-compose.yml`, `backoffice` | `DB_PASSWORD_CITTADINI` | non letta da nessun codice |
| `docker-compose.yml`, `db` | `DB_USER_CITTADINI`, `DB_PASSWORD_CITTADINI` | usate solo da `docker/db-init/020-grant-cittadini.sh` |
| `docker/db-init/020-grant-cittadini.sh` | crea `govpay_cittadini` + grant | utente senza consumatori |
| `.env.example`, README, CLAUDE.md, `scripts/smoke-stack.sh`, `ci.yml` | `FRONTOFFICE_DB_USER`, `FRONTOFFICE_DB_PASSWORD` (obbligatoria) | da rimuovere |
| `.env.example` | `APACHE_SERVER_NAME` | non usata da nessun file (pulizia correlata) |

## Obiettivo

Il container frontoffice non riceve alcuna credenziale DB e non ha alcun percorso di codice
verso MariaDB. L'utente `govpay_cittadini` non viene più creato.

## Modifiche

1. `frontoffice/public/index.php`: rimuovere `frontoffice_build_voci()` e
   `use App\Database\EntrateRepository`.
2. `docker-compose.yml`:
   - `frontoffice`: rimuovere tutte le variabili `DB_*` e `DB_PASSWORD_CITTADINI`, e
     `depends_on: db`;
   - `backoffice`: rimuovere `DB_PASSWORD_CITTADINI`;
   - `db`: rimuovere `DB_USER_CITTADINI`, `DB_PASSWORD_CITTADINI`.
3. Rimuovere `docker/db-init/020-grant-cittadini.sh` (immagine `db`). Verificare se la
   `CREATE TABLE IF NOT EXISTS rate_limit_buckets` che contiene serve ancora al primo avvio:
   la tabella è creata anche da `migrations/010_rate_limit_buckets.sql`, eseguita dal
   backoffice; lo script la anticipava solo per poter fare il `GRANT` al cittadini.
4. `.env.example`, README (tabella variabili e nota "segreti obbligatori"), CLAUDE.md,
   `scripts/smoke-stack.sh`, `.github/workflows/ci.yml`: togliere `FRONTOFFICE_DB_USER`,
   `FRONTOFFICE_DB_PASSWORD`; `.env.example`: togliere `APACHE_SERVER_NAME`.
5. Test di regressione: un test che fallisce se `frontoffice/` referenzia `App\Database\`,
   `Connection`, `SettingsRepository`, `RateLimiter` o `getenv('DB_` (vincolo architetturale
   esplicito: il frontoffice parla solo con il backoffice).

## Istanze esistenti

- Gli script `db-init` girano solo su datadir vuota: sulle istanze esistenti l'utente
  `govpay_cittadini` resta in MariaDB con i suoi grant, ma nessun container ne conosce più
  la password. Rimozione opzionale, manuale, da console del container `db`:
  `DROP USER 'govpay_cittadini'@'%';` (il backoffice gira come `govpay`, che non ha il
  privilegio `DROP USER`: niente migrazione automatica).
- `FRONTOFFICE_DB_PASSWORD` e `FRONTOFFICE_DB_USER` rimaste nelle variabili dello stack sono
  innocue (non più referenziate) e vanno tolte da Portainer dopo l'aggiornamento: da
  indicare nelle note di rilascio.
- Compose e immagini arrivano insieme (stack Git + `latest`): nessuna finestra in cui il
  compose nuovo gira con un frontoffice vecchio che legge il DB, perché anche le immagini
  attuali non lo leggono.

## Verifica

- PHPUnit + test architetturale del punto 5; PHPStan.
- Smoke stack (`scripts/smoke-stack.sh`) senza variabili `FRONTOFFICE_DB_*`: stack healthy.
- Prova funzionale su stack locale: home, pagamento spontaneo, avviso, carrello, ricevuta,
  rate limit (`pagamenti/rate-limited`), riavvio del backoffice con frontoffice attivo
  (cache config).
- `docker compose exec frontoffice env | grep DB_` vuoto.
