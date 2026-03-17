# Progetto GovPay Interaction Layer - TODO List

### Notifiche Pendenze
- [ ] **Email**
    - [X] Lato Backoffice: Invio mail al cittadino al momento della creazione pendenza
        - [X] Invio email automatico al momento della creazione della pendenza
        - [X] Inserimento dei dati notifica nei `datiAllegati` della pendenza
        - [X] Visualizzazione in dettaglio pendenza: sezione dedicata ai dati allegati della pendenza
    - [ ] Lato Frontoffice: Inserire tasto "Invia per email" post-creazione spontaneo (accanto a "Paga ora" e "Stampa").
- [ ] **App IO**
    - [X] Implementazione delle medesime notifiche (Backoffice/Frontoffice) tramite App IO.
    - [X] **Configurazione API**: Implementazione API App IO e gestione chiavi/servizi per ogni tipologia di pendenza.
- [X] **Integrazione Dati e UI**
    - [X] Inserimento esiti/log notifiche nei `datiAllegati` della pendenza (es. timestamp mail, ID notifica IO).
    - [X] Modifica del dettaglio pendenza per visualizzare una scheda/tab dedicata alle notifiche inviate.
    - [ ] Notifiche per pendenze rateali

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
- [ ] **Personalizzazione e Permessi**
    - [X] Possibilità di associare template all'utente.
    - [ ] Associare agli utenti una tipologia di pendenza di default.
    - [ ] Sistema per limitare la visibilità delle tipologie per utente (filtro tipologie abilitate).
- [ ] **Gruppi Utenti**: Implementazione gruppi per gestire centralmente template, tipologie e permessi.

### Autenticazione e Identity
- [X] **IAM Proxy**: Sistemazione integrazione proxy IAM.
- [ ] **CIE**: Bugfixing autenticazione con CIE.
- [X] **Discovery Page**: Sistemazione grafica della pagina `disco.html`.

### Manutenzione e Sistema
- [ ] **Configurazione**: Funzionalità di backup e importazione della configurazione di sistema.
- [ ] **Pannello di Configurazione (UI)**:
    - [ ] Creazione di una procedura di inizializzazione guidata (setup wizard).
    - [ ] Possibilità di gestire i parametri (comprese variabili env, logo, certificati GovPay) direttamente dall'interfaccia.
    - [ ] Snellimento e semplificazione della gestione manuale dei file `.env`.
- [x] **Documentazione**: Sistemazione documentazione relativa ai cron (attualmente massivo, in futuro rendicontazione).

### Integrazioni Esterne
- [ ] **PagoPA Checkout**: Implementazione API PagoPA per avviare il checkout di pagamenti non generati da GovPay (simulazione portale checkout.pagopa.it).

### Ottimizzazione Infrastruttura e Cleanup
- [ ] **Snellimento Build**
    - [X] Semplificazione degli script di build.
    - [ ] Rimozione dei container effimeri che terminano dopo la build (es. `sync-iam-proxy`).
    - [ ] Valutazione sostituzione bind-mount con istruzioni `COPY` (o `docker cp`) per le cartelle statiche.
    - [X] Rimozione di `chown` e operazioni simili dagli script di entrypoint/build per velocizzare l'avvio.
- [ ] **Pulizia Repository**
    - [ ] Rimozione degli script di migrazione orfani.
    - [ ] Eliminazione definitiva della cartella `debug/`.
    - [ ] Cleanup finale e modernizzazione della struttura del repository.

### Altro
- [X] Pagina sul frontoffice pubblica per il download della ricevuta di pagamento
    - Meccanismo di autenticazione: IUV + ID notifica + IUR di pagamento
- [ ] Automatismo CI/CD per creazione e pubblicazione immagini Docker su GHCR
    - Workflow GitHub Actions: build, tag automatico, push su `ghcr.io`
