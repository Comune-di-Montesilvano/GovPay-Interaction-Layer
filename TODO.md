

### UI Fix & optimization Backoffice
- [ ] Togli richiesta password alla creazione utente, ma invia una mail per il reset scrivendo nella mail ti hanno creato utenza ecc...
- [ ] Nella mail di test il logo non è embedded e la grafica è da rivedere
- [ ] Dare la possibilità all'user di crearsi dei template o associarsene alcune o dissociarsene. Vince però la logica del gruppo, ovvero se eredita dal gruppo non può togliersele. Mettere come domanda, crei sempre le stesse pendenze? Creati il tuo template o qualcosa di simile.
- [ ] Funzione di ricerca in impostazioni
- [ ] Configurazione smtp in un tab separato sotto generale
- [ ] Riordinare i gruppi tab sotto impostazioni:
        Generale: il figlio generale diventa Dati di Base, Applicazione->rimosso i figli vanno sotto generale, Autenticazione rimosso: loginproxy diventa Login frontoffice e finisce nel gruppo generale(occhio al link in frontoffice), Govpay, Pagamenti che diventa Tassonomie, Utenti, Api Esterne, Backup, Sistema
### UI Fix & optimization Frontoffice



# Progetto GovPay Interaction Layer - TODO List

### Notifiche Pendenze
- [ ] **Email**
    - [X] Lato Backoffice: Invio mail al cittadino al momento della creazione pendenza
        - [X] Invio email automatico al momento della creazione della pendenza
        - [X] Inserimento dei dati notifica nei `datiAllegati` della pendenza
        - [X] Visualizzazione in dettaglio pendenza: sezione dedicata ai dati allegati della pendenza
    - [ ] Lato Frontoffice: Inserire tasto "Invia per email" post-creazione spontaneo (accanto a "Paga ora" e "Stampa").
- [X] **App IO**
    - [X] Implementazione delle medesime notifiche (Backoffice/Frontoffice) tramite App IO.
    - [X] **Configurazione API**: Implementazione API App IO e gestione chiavi/servizi per ogni tipologia di pendenza.
- [X] **Integrazione Dati e UI**
    - [X] Inserimento esiti/log notifiche nei `datiAllegati` della pendenza (es. timestamp mail, ID notifica IO).
    - [X] Modifica del dettaglio pendenza per visualizzare una scheda/tab dedicata alle notifiche inviate.
    - [X] Notifiche per pendenze rateali

### Sistema di Rendicontazione
- [ ] **Automazione**
    - [ ] Cron job per la scansione dei flussi non rendicontati.
    - [ ] Invio notifiche email ai destinatari configurati per ogni flusso elaborato.
    - [ ] Implementazione del meccanismo di rendicontazione automatica del flusso al completamento del processing/notifica di tutte le pendenze.
- [ ] **Webhook Agnostici**
    - [ ] Meccanismo di notifica verso sistemi terzi basato su regole configurabili (per tipologia di pendenza, ecc.).
- [ ] **Interfaccia Backoffice**
    - [ ] Inserimento del tasto "Rendiconta flusso" nel dettaglio flusso per permettere il bypass manuale.

### Gestione Profilo Utente
- [X] **Interfaccia**: Miglioramento dell'interfaccia dell'area profilo.
- [X] **Sicurezza**: Funzione di cambio password.
- [X] **Personalizzazione e Permessi**
    - [X] Possibilità di associare template all'utente.
    - [X] Associare agli utenti una tipologia di pendenza di default.
    - [X] Sistema per limitare la visibilità delle tipologie per utente (filtro tipologie abilitate).
- [X] **Gruppi Utenti**: Implementazione gruppi per gestire centralmente template, tipologie e permessi.

### Autenticazione e Identity
- [X] **IAM Proxy**: Sistemazione integrazione proxy IAM.
- [X] **CIE**: Bugfixing autenticazione con CIE.
- [X] **Discovery Page**: Sistemazione grafica della pagina `disco.html`.

### Manutenzione e Sistema
- [X] **Configurazione**: Funzionalità di backup e importazione della configurazione di sistema.
- [ ] **Pannello di Configurazione (UI)**:
    - [X] Creazione di una procedura di inizializzazione guidata (setup wizard) per sostituzione file `.env`.
    - [X] Possibilità di gestire i parametri (comprese variabili env, logo, certificati GovPay) direttamente dall'interfaccia.
    - [X] Gestione segreti (API Key, certificati, ecc.) cifrati con la chiave di cifratura dell'applicazione.
- [x] **Documentazione**: Sistemazione documentazione relativa ai cron (attualmente massivo, in futuro rendicontazione).
- [X] **dev**: creazione branch, dev. Con ci/cd automatica ad ogni commit e visualizzazione del commit specifico nel footer, tipo vDEV-commit-hash.


### Integrazioni Esterne
- [PAUSED] **PagoPA Checkout**: Implementazione API PagoPA per avviare il checkout di pagamenti non generati da GovPay (simulazione portale checkout.pagopa.it). PROBABILMENTE NON FATTIBILE

### Ottimizzazione Infrastruttura e Cleanup
- [X] **Snellimento Build**
    - [X] Semplificazione degli script di build.
    - [X] Rimozione dei container effimeri che terminano dopo la build (es. `sync-iam-proxy`).
    - [X] Valutazione sostituzione bind-mount con istruzioni `COPY` (o `docker cp`) per le cartelle statiche.
    - [X] Rimozione di `chown` e operazioni simili dagli script di entrypoint/build per velocizzare l'avvio.
- [ ] **Pulizia Repository**
    - [ ] Rimozione degli script di migrazione orfani.
    - [ ] Eliminazione definitiva della cartella `debug/`.
    - [ ] Cleanup finale e modernizzazione della struttura del repository.
- [ ] **Ottimizzazione frontoffice**
    - [ ] Stato pendenze non localizzato in lingue diverse dall'italiano
    - [ ] Tabella le mie pendenze non ottimizzata per mobile
    - [ ] Limite 5 pendenze non sempre rispettato
    - [ ] Ottimizzazione navigazione, in particolare per mobile, ridondanza di informazioni

### Sviluppi Futuri
- [ ] Integrazione eBollo 2.0 sul frontoffice
    - [API](https://developer.pagopa.it/it/pago-pa/api/e-bollo)