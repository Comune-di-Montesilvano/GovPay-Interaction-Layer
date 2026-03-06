## Plan: App IO, Tipologie, Frontoffice Links

Implementare in modo coordinato: salvataggio massivo associazioni tipologia-servizio IO nel backoffice, nuove route frontoffice per PDF/ricevuta/checkout da link esterni, completamento payload App IO con `payment_data` e link PDF, e revisione email (oggetto, contenuti, logo). Approccio consigliato: riuso delle funzioni già presenti in `frontoffice/public/index.php` e servizi attuali, aggiungendo solo il minimo necessario (bulk endpoint e firma URL stateless con scadenza 2 anni).

**Steps**
1. Fase 1 - Backoffice Tipologie Bulk Save
2. In `backoffice/templates/configurazione.html.twig`, spostare la select IO service in alto per ogni card tipologia e racchiudere tutte le select del tab in un unico form con un solo pulsante `Salva configurazioni` in alto nel tab tipologie.
3. In `backoffice/src/Controllers/ConfigurazioneController.php`, aggiungere un metodo bulk (es. `bulkSetTipologieIoService`) che riceve un array `io_service_id[idEntrata]`, valida i permessi superadmin, applica tutte le associazioni e produce un flash unico con conteggio successi/errori.
4. In `backoffice/routes/web.php`, sostituire/affiancare la route puntuale con una route bulk POST dedicata al salvataggio massivo. *depends on 3*
5. In `app/Database/IoServiceRepository.php`, riusare `setTipologiaService` in loop dal controller bulk; se necessario aggiungere supporto transazionale nel controller per evitare salvataggi parziali. *depends on 3*
6. Fase 2 - Nuove Route Frontoffice (PDF, Ricevuta, Checkout)
7. In `frontoffice/public/index.php`, aggiungere una utility per firma URL stateless (HMAC) con validazione `expires` a 2 anni, senza storage DB/sessione, usando secret applicativo (fallback robusto se env mancante).
8. Aggiungere route download PDF pendenza tramite `codicefiscale + iuv` con validazione firma/token, lookup pendenza via helper esistenti, verifica ownership CF, e stream PDF riusando le funzioni già presenti. *depends on 7*
9. Aggiungere route ricevuta tramite `iuv + iur` con validazione firma/token e stream ricevuta PDF usando helper esistente; gestione errori con codici HTTP coerenti e log sanitizzato. *depends on 7*
10. Aggiungere route checkout immediato tramite `codicefiscale + iuv` con validazione firma/token, verifica ownership, controlli stato pagabile, e redirect a sessione checkout pagoPA riusando `frontoffice_build_cart_request` e client checkout esistenti. *depends on 7*
11. Fase 3 - App IO Message Payload
12. In `app/Services/AppIoService.php`, estendere `sendMessage` per supportare i campi necessari a `payment_data` (notice number/amount/due-date policy) mantenendo backward compatibility per chiamate esistenti. *parallel with 13 if signature remains compatible*
13. In `backoffice/src/Controllers/PendenzeController.php`, popolare `payment_data` con dati pendenza (IUV/numero avviso/importo) e arricchire markdown con link al download PDF della pendenza (nuova route del punto 8, già firmata). *depends on 8*
14. In `backoffice/src/Controllers/PendenzeController.php`, allineare oggetto App IO a `Pendenza PagoPA - {Causale}` come da requisito mail, con fallback sicuro su causale assente.
15. In `backoffice/src/Controllers/PendenzeController.php`, salvare anche l'esito notifica App IO in `datiAllegati.notifiche` (riuso `addNotificationToPendenza`) oltre alla mail già tracciata.
16. Fase 4 - Anteprima Invii Notifica
17. In `backoffice/src/Controllers/PendenzeController.php` metodo `preview()`, calcolare i canali notificabili attesi: `email` se presente indirizzo valido; `app_io` se CF/tipo soggetto compatibile e servizio IO associato alla tipologia (o default disponibile).
18. In `backoffice/templates/pendenze/conferma.html.twig`, mostrare un box riepilogo "Notifiche che verranno inviate" con indicatori espliciti (Mail/App IO) e motivazione in caso di esclusione.
19. Fase 5 - Email Notification Fixes
20. In `backoffice/src/Controllers/PendenzeController.php`, sostituire dati mail da identificativo interno `GIL-...` a IUV, aggiungere URL download PDF (nuova route firmata), e passare tutti i campi utili al mailer. *depends on 8*
21. In `app/Services/MailerService.php`, aggiornare oggetto a `Pendenza Pagopa - "{Causale}"`, contenuto HTML/TXT con IUV e link PDF, e correggere risoluzione/embedding logo con percorso affidabile nel container e fallback quando il file non esiste.
22. Fase 6 - Coerenza Link e Hardening
23. Uniformare la generazione link (IO + email) usando una funzione condivisa nel backoffice controller/service per evitare discrepanze tra route, querystring e token.
24. Aggiungere logging tecnico non sensibile per failure di token/ownership/stream checkout senza esporre CF completo.
25. Verifica finale e regressione
26. Validare sintassi PHP dei file toccati e rotte nuove con smoke test manuale da browser/curl su casi validi/non validi (token scaduto, token alterato, CF mismatch, pendenza non pagabile).
27. Eseguire test end-to-end: creazione pendenza con invio IO e mail, verifica presenza bottone `Paga ora` in App IO, oggetto `Pendenza PagoPA - {Causale}`, link PDF funzionante, notifica App IO tracciata in `datiAllegati`, anteprima canali corretta, e checkout immediato funzionante.

**Relevant files**
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\backoffice\templates\configurazione.html.twig` — riorganizzazione UI tipologie, form unico, submit unico.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\backoffice\src\Controllers\ConfigurazioneController.php` — nuovo handler bulk save associazioni tipologie-servizi IO.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\backoffice\routes\web.php` — nuova route POST bulk.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\app\Database\IoServiceRepository.php` — riuso metodo associazione e possibile supporto transazione coordinata.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\frontoffice\public\index.php` — nuove route GET (pdf pendenza, ricevuta, checkout immediato), validazione token, riuso helper checkout/pdf.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\app\Services\AppIoService.php` — estensione payload `payment_data` per API App IO.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\backoffice\src\Controllers\PendenzeController.php` — costruzione link firmati, payload IO, oggetto App IO, tracking notifiche in `datiAllegati`, dati mail aggiornati, calcolo canali in anteprima.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\backoffice\templates\pendenze\conferma.html.twig` — box anteprima canali notifica (Mail/App IO) con motivazioni.
- `c:\Users\mirko.daddiego\Documents\GovPay-Interaction-Layer\app\Services\MailerService.php` — oggetto mail, body con IUV/link PDF, fix logo embed.

**Verification**
1. Eseguire `php -l` su: `app/Services/AppIoService.php`, `app/Services/MailerService.php`, `backoffice/src/Controllers/ConfigurazioneController.php`, `backoffice/src/Controllers/PendenzeController.php`, `frontoffice/public/index.php`.
2. Aprire backoffice `Configurazione > Tipologie`: modificare più select IO, premere un solo `Salva configurazioni`, ricaricare pagina e verificare persistenza totale.
3. Testare route PDF pendenza con URL firmata valida (CF+IUV): atteso download PDF; con token alterato/scaduto: 403/errore coerente.
4. Testare route ricevuta con URL firmata valida (IUV+IUR): atteso download ricevuta; con IUR errato: 404/503 coerente.
5. Testare checkout immediato con URL firmata valida (CF+IUV): atteso redirect pagoPA; con pendenza non pagabile: errore 400 coerente.
6. Creare nuova pendenza con IO attivo: verificare in App IO presenza CTA `Paga ora` (payment_data) e link `Scarica PDF`.
7. Verificare email ricevuta: oggetto `Pendenza Pagopa - "{Causale}"`, presenza IUV (no GIL), link PDF cliccabile, logo visibile.

**Decisions**
- Sicurezza link pubblici: usare token firmato stateless (nessuna memorizzazione server), con `expires` a 2 anni.
- Parametri richiesti utente mantenuti: CF+IUV per PDF pendenza e checkout immediato, IUV+IUR per ricevuta.
- Scope incluso: UI tipologie bulk, 3 route frontoffice, payload App IO, contenuto mail e logo.
- Scope escluso: refactoring architetturale del frontoffice monolitico e introduzione nuovi microservizi.

**Further Considerations**
1. Secret firma URL: raccomandato env dedicato (`FRONTOFFICE_LINK_SIGNING_KEY`) con fallback solo in dev; in produzione non usare fallback impliciti.
2. Compatibilita App IO `payment_data`: confermare mapping preciso tra IUV/notice_number e importo in centesimi secondo contratto API in uso.
3. Privacy log: mascherare CF nei log (`***`) mantenendo solo suffisso per troubleshooting.
