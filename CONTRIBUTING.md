# Contribuire a GovPay Interaction Layer

Grazie per l'interesse a contribuire a GovPay-Interaction-Layer.

Questo progetto è rilasciato sotto la European Union Public Licence v1.2 (EUPL-1.2). Contribuendo accetti che le tue modifiche siano rilasciate sotto la stessa licenza.

Per feature importanti apri prima una issue per discuterne.

## Flusso di lavoro

1. Crea un branch dal tip di `main` (`feat/...`, `fix/...`, `chore/...`): `main` e' protetto,
   niente push diretti.
2. Apri una Pull Request verso `main` compilando il template (checklist test, sicurezza/PII,
   migrazioni, documentazione).
3. I check obbligatori (`build-test`, `trivy-fs`, `semgrep`, `dependency-review`,
   `trivy-image (backoffice)`, `trivy-image (frontoffice)`, `trivy-image (db)`) devono essere verdi e i thread di review
   risolti. Il merge avviene solo in **squash**; il branch viene cancellato al merge.
4. Messaggi di commit in [Conventional Commits](https://www.conventionalcommits.org/)
   (`feat:`, `fix:`, `refactor:`, `chore:`...), soggetto imperativo, max 72 caratteri.

## Regole

- **Ogni fix ha un test di regressione** in `tests/` che fallisce senza la correzione.
- Nessun segreto nel codice o nei log; nessuna PII (codice fiscale, IBAN, nominativi) verso
  Sentry/GlitchTip o messaggi d'errore pubblici.
- PHPStan livello 3 senza errori; le soppressioni (`@phpstan-ignore-next-line`,
  `nosemgrep:`) vanno sulla riga immediatamente precedente, con motivazione.
- I client in `govpay-clients/` e `pagopa-clients/` sono generati: non modificarli a mano,
  rigenerarli dalla spec OpenAPI.
- Migrazioni DB: nuovo file SQL numerato in `migrations/`; prevedere il backup prima
  dell'applicazione in produzione.

## Comandi utili

`composer` non va installato localmente: si usa via Docker (su Windows/git-bash serve
`MSYS_NO_PATHCONV=1` per non riscrivere i path Linux).

```bash
# Dipendenze PHP
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd):/app" -w //app composer:2 install --prefer-dist --no-progress

# Audit sicurezza dipendenze (bloccante in CI)
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd):/app" -w //app composer:2 audit --no-dev

# Test PHPUnit (PHP 8.5 locale, con vendor installato)
php vendor/bin/phpunit

# Analisi statica
php vendor/bin/phpstan analyse --memory-limit=1G

# Test nello stack Docker (come in CI)
docker compose -f docker-compose.yml -f docker-compose.ci.yml up --build --abort-on-container-exit

# Ambiente di sviluppo locale
cp .env.example .env
docker compose up -d --build
```

## Segnalazione di vulnerabilita'

Non aprire issue pubbliche per problemi di sicurezza: usa la segnalazione privata
(tab **Security → Report a vulnerability** del repository).
