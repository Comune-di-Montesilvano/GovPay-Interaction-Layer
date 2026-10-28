## Descrizione

<!-- Cosa cambia e perche'. Collegare issue se presente (Closes #N). -->

## Checklist

- [ ] Test aggiunti/aggiornati (ogni fix ha un test di regressione) e `php vendor/bin/phpunit` verde
- [ ] PHPStan livello 3 senza errori nuovi
- [ ] Sicurezza: nessun segreto nel diff, input validati, nessuna PII (codice fiscale, IBAN, nominativi) in log/Sentry/messaggi d'errore
- [ ] Migrazioni DB: file SQL numerato in `migrations/`, backup del DB previsto prima dell'applicazione in produzione (oppure: nessuna migrazione)
- [ ] `CLAUDE.md` / documentazione aggiornati se cambiano architettura, convenzioni o gotcha
