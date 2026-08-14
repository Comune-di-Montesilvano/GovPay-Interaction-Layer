# GovPay Interaction Layer — TODO

Ultima revisione: 2026-08-14 (verificato contro stato codice/CLAUDE.md, item completati rimossi).

## UI Backoffice
- [ ] `tab-conf-all.html.twig`: verificare se è un duplicato di un altro tab — da valutare se spezzettare
- [ ] Rendere l'importo del template pendenza non obbligatorio
- [ ] Verificare se l'associazione/dissociazione template utente + creazione nuovi template è completa (esiste già gestione template-utente in `ConfigurazioneController`, ma "crea nuovi template" da profilo utente non confermato)
- [ ] Tassonomie API: valutare se accorpare in Tassonomie Ufficiale (funzionalità ridondante secondo revisione precedente)

## UI Frontoffice
- [ ] Bottone "Invia per email" post-creazione pendenza spontanea (accanto a "Paga ora"/"Stampa") — non presente
- [ ] Stato pendenze non localizzato in lingue diverse dall'italiano
- [ ] Tabella "le mie pendenze" non ottimizzata per mobile
- [ ] Limite 5 pendenze non sempre rispettato
- [ ] Ottimizzazione navigazione mobile, ridondanza informazioni

(Nota: alcuni miglioramenti mobile/frontoffice generali sono già stati fatti — vedi CLAUDE.md "Ottimizzazione Mobile & UI Frontoffice, Maggio 2026" — ma non coprono i 4 punti sopra, verificati ancora aperti.)

## Sistema di Rendicontazione — webhook agnostici
- [ ] Meccanismo di notifica verso sistemi terzi basato su regole configurabili per tipologia pendenza (nessuna traccia in codice — non implementato)

(Nota: cron scansione flussi, notifiche email, rendicontazione automatica al completamento e smarcatura manuale da UI sono **già implementati** — vedi CLAUDE.md "Motore Rendicontazione GovPay, Luglio 2026" / `cron_rendicontazione_govpay.php` / `/rendicontazione/da-confermare`. Rimossi da questa lista.)

## Pulizia Repository
- [ ] Rimozione script di migrazione orfani in `migrations/`
- [ ] Cleanup finale e modernizzazione struttura repository

(Nota: cartella `debug/` già rimossa dal repo — confermato, non più in TODO.)

## Integrazioni esterne
- [PAUSED] PagoPA Checkout per pagamenti non generati da GovPay (simulazione portale checkout.pagopa.it) — probabilmente non fattibile, non riprendere senza verificare prima con pagoPA

## Debito tecnico noto (da sessioni recenti)
- [ ] Bump `guzzlehttp/guzzle` a `^8.0` — bloccato da `Utils::jsonEncode()` nei client OpenAPI generati (rimosso in Guzzle 8, già patchato lato client) + richiede `guzzlehttp/psr7 ^3.0`/`guzzlehttp/promises ^3.0`, compatibilità da verificare. Vedi CLAUDE.md sezione "Generazione API client".
