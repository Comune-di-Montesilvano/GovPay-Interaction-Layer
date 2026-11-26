# Sportello pagamenti – Guida per gli operatori

_Ultimo aggiornamento: 2026-11-17_

## Cos'è e chi lo usa

Lo Sportello pagamenti (backoffice di GovPay Interaction Layer) è il portale con cui gli uffici dell'Ente gestiscono i pagamenti pagoPA: creano le pendenze (le posizioni debitorie che il cittadino paga), le consultano, scaricano avvisi e ricevute e controllano gli incassi arrivati dai flussi di rendicontazione.

Lo usano gli uffici che emettono pagamenti (singoli o massivi, anche con marca da bollo telematica) e la ragioneria, che riconcilia gli incassi per tipologia di entrata.

I dati di pagamento vivono in GovPay e sul nodo pagoPA: lo Sportello li legge e li aggiorna tramite GovPay. I cittadini pagano dal portale pubblico dell'Ente, da App IO o da qualsiasi canale pagoPA (banca, tabaccheria, app) con il numero avviso.

## Accesso

- **Indirizzo**: il backoffice dell'Ente (il link è nella plancia dei dipendenti).
- **Credenziali**: email e password dell'account operatore, create da un amministratore. Se non ricordi la password usa **Hai dimenticato la password?** nella pagina di accesso: ricevi per email un link per impostarne una nuova.
- **Una sola sessione per utente**: se accedi da un altro PC o browser, la sessione precedente si chiude e lì ti viene richiesto il login.
- **Ruoli**: ci sono tre ruoli, **user**, **admin** e **superadmin** (vedi «Ruoli e permessi»). Il ruolo è indicato in alto a destra, sotto il tuo nome.
- **Stato GovPay**: il riquadro **GovPay: Online** in alto indica se il collegamento con GovPay funziona. Se diventa rosso (**GovPay: Offline**), cliccalo per leggere il motivo: finché non torna online ricerche e inserimenti non funzionano.

## Operazioni frequenti

### Cercare una pendenza

Il modo più rapido è la barra in alto, **Cerca CF, P.IVA, IUV o ID flusso**: riconosce da solo cosa hai scritto (codice fiscale, partita IVA, IUV o numero avviso, ID pendenza, identificativo di un flusso) e apre la ricerca giusta. L'etichetta accanto al campo indica cosa ha riconosciuto.

Per una ricerca con più criteri apri **Pendenze → Ricerca pendenze**:

1. Compila uno o più campi: ID debitore (CF/PI), IUV, ID pendenza, stato, tipologia, intervallo date.
2. Per le date usa i pulsanti rapidi (**Mese Corrente**, **Mese Precedente**, **Anno Corrente**, **Anno Precedente**, **Azzera**) oppure scrivi la data a mano.
3. In **Filtri avanzati** trovi ID A2A, ID pagamento, direzione, divisione e l'ordinamento dei risultati.
4. Clicca **Dettaglio** su una riga per aprire la pendenza.

### Leggere il dettaglio di una pendenza

Il dettaglio mostra soggetto pagatore, causale, numero avviso, voci di pendenza (importo, IBAN di accredito, tipologia) e stato.

Gli stati più comuni:

| Stato | Significato |
| --- | --- |
| NON_ESEGUITA | Da pagare. Si può modificare o annullare |
| ESEGUITA | Pagata |
| ANNULLATA | Annullata dall'ufficio. Si può riattivare |
| SCADUTA | Scaduta senza pagamento |
| INCASSATA | Pagata e già riconciliata con il flusso di rendicontazione |

In fondo alla pagina tre pulsanti aprono le sezioni di approfondimento:

- **Mostra tentativi di pagamento**: ogni volta che il cittadino avvia un pagamento pagoPA nasce un tentativo. Un tentativo **RPT_SCADUTA** vuol dire che il cittadino ha iniziato il pagamento senza completarlo entro 30 minuti: la pendenza resta da pagare. Se GovPay non fornisce la data del tentativo, la colonna **Data e ora** mostra «—».
- **Sorgente e storico**: da dove è nata la pendenza (backoffice con l'operatore, portale cittadini con il cittadino autenticato, caricamento massivo con il lotto) e lo storico delle modifiche con data e operatore.
- **Notifiche**: email e messaggi App IO inviati al cittadino, con esito. Con **Reinvia** ripeti l'invio, finché la pendenza non è pagata.

### Scaricare avviso e ricevuta

Dal dettaglio della pendenza:

- **Avviso** (PDF): l'avviso di pagamento pagoPA, da consegnare o inviare al cittadino. Per le pendenze rateizzate c'è anche il pulsante per l'**avviso multirata**, con tutte le rate in un unico PDF.
- **Ricevuta**: compare solo a pagamento avvenuto e scarica la ricevuta telematica.
- Per la marca da bollo telematica pagata si scarica il file XML della marca (vedi «Emettere una marca da bollo telematica»).

Se il PDF non si apre, controlla che il browser non abbia bloccato la nuova finestra.

### Inserire una nuova pendenza

1. Apri **Pendenze → Nuova Pendenza**.
2. Se hai dei template, scegline uno in **Seleziona per autocompilare**: tipologia, importo e causale si compilano da soli.
3. Scegli la **Tipologia pendenza** e indica il soggetto pagatore (**Persona fisica** o **Persona giuridica**, con codice fiscale o partita IVA, nome ed email facoltativa).
4. Scrivi causale, anno di riferimento e importo. Con **Mostra campi avanzati** puoi indicare date di validità e scadenza, più voci con importi diversi e i dati contabili.
5. Premi **Anteprima**: controlla il riepilogo e conferma. Dalla conferma puoi anche **Rateizza** (vedi «Rateizzare una pendenza»).

Dopo l'inserimento si apre il dettaglio della nuova pendenza, con l'avviso già scaricabile. Se il cittadino ha un indirizzo email o App IO attivo, riceve la notifica di creazione.

### Modificare, annullare o riattivare una pendenza

Dal dettaglio della pendenza:

- **Modifica**: solo per pendenze **NON_ESEGUITA**. Puoi cambiare importi, date, causale e soggetto. La tipologia la possono cambiare solo admin e superadmin.
- **Annulla**: solo per pendenze **NON_ESEGUITA**. Il cittadino non può più pagarla.
- **Riattiva**: per una pendenza **ANNULLATA**, la rende di nuovo pagabile.

Una pendenza già pagata non si può più modificare né annullare: GovPay rifiuta qualsiasi aggiornamento.

### Rateizzare una pendenza

Dalla pagina di conferma di una nuova pendenza premi **Rateizza**:

1. Indica il **Numero rate**: importi e scadenze vengono calcolati in automatico, a cadenza regolare.
2. Se modifichi l'importo o la data di una rata, le rate successive si ricalcolano da sole per mantenere il totale.
3. Con **Aggiungi rate** aggiungi altre rate, con **Azzera** ricominci.
4. Conferma: nascono una pendenza per rata, collegate da un unico documento, e l'avviso multirata.

**Rata unica a scelta.** Spunta **Crea anche rata unica** quando il cittadino può scegliere se pagare tutto in una volta o a rate (es. istanze urbanistiche). Oltre alle rate nasce una pendenza per l'importo totale, nello stesso documento. L'avviso multirata mostra entrambe le opzioni. Se non indichi la **Scadenza rata unica**, vale la data della rata 1. Il cittadino riceve email e messaggi App IO sia per la rata unica sia per ogni rata.

Quando GIL rendiconta un pagamento (di solito 1–2 giorni dopo), annulla da solo l'opzione non scelta: pagata la rata unica, annulla le rate; pagata una rata, annulla la rata unica. Se il cittadino paga entrambe le opzioni prima della rendicontazione, GIL annulla solo gli avvisi non ancora pagati e registra un avviso di doppio pagamento: l'eventuale rimborso va gestito a mano.

### Emettere una marca da bollo telematica

La voce **Pendenze → Nuova marca da bollo** compare solo se il servizio @e.bollo è attivo per l'Ente.

1. Indica il soggetto pagatore e la **Provincia di residenza**.
2. Aggiungi i documenti a cui applicare il bollo: ogni documento vale **€ 16,00**, al massimo 5 per pendenza.
3. Conferma. Dal dettaglio stampi il **Bollettino** da consegnare al cittadino.

A pagamento avvenuto il dettaglio offre il download del file XML della marca, da conservare con il documento.

### Caricare pendenze massive da file CSV

1. Apri **Pendenze → Pendenze Massive** e premi **Scarica template CSV**.
2. Compila il file mantenendo le intestazioni: `TIPO;CODICE_FISCALE_PIVA;COGNOME_ANAGRAFICA;NOME;CAUSALE;ANNO_RIFERIMENTO;IMPORTO;EMAIL;DATA_VALIDITA;DATA_SCADENZA;NUMERO_RATE;VOCE_1_IMPORTO;VOCE_1_CAUSALE;VOCE_2_IMPORTO;VOCE_2_CAUSALE`.
3. Trascina il file nel riquadro e premi **Carica & Anteprima**.
4. Controlla il numero di righe valide e le prime righe di esempio. Le righe con errori non vengono importate: scaricale con **Scarica CSV errori**, correggile e caricale in un secondo file.
5. Scegli la **Tipologia pendenza** e premi **Conferma e Invia in elaborazione**.

Le pendenze vengono create in background, una riga alla volta: puoi chiudere la pagina e seguire l'avanzamento da **Storico Massivo**.

Regole del file:

- **Importi**: sono accettati sia `1.234,56` sia `1234.56`. Un solo separatore seguito da esattamente tre cifre (`1.234`) vale come migliaia.
- **Righe di esempio**: il template contiene quattro righe da sostituire con i tuoi dati: pendenza unica, pendenza con due voci, 3 rate mensili calcolate, 3 rate con date per rata.
- **Voci**: `VOCE_1_*` e `VOCE_2_*` dividono `IMPORTO` in al massimo due righe di dettaglio (es. 50,00 = 40,00 canone + 10,00 diritti di segreteria). La somma delle voci deve essere uguale a `IMPORTO`. Se lasci vuote le colonne delle voci, la pendenza ha una sola voce pari a `IMPORTO` con la `CAUSALE`; se compili solo la voce 2, la voce 1 prende la differenza. Con `VOCE_2_IMPORTO` va indicata anche `VOCE_2_CAUSALE`. Le due voci usano la stessa contabilità (IBAN e codice) della tipologia scelta alla conferma.
- **Rate**: la colonna `NUMERO_RATE` indica in quante rate dividere la pendenza (vuota o 1 = pendenza unica, massimo 24). `IMPORTO` e voci sono i totali: vengono divisi tra le rate (le voci in proporzione). Senza date per rata le rate sono mensili; la prima scade a `DATA_SCADENZA` (o tra un mese, se vuota).
- **Date per rata**: con le colonne `RATA_1_DATA_VALIDITA;RATA_1_DATA_SCADENZA;RATA_2_DATA_VALIDITA;…` indichi le date di ogni rata. Se le usi, la scadenza va indicata per tutte le rate.

### Seguire un caricamento massivo

**Pendenze → Storico Massivo** elenca i lotti caricati con il conteggio delle righe: in coda, in pausa, in elaborazione, completate, in errore, annullate.

Per ogni lotto:

- **Pausa** / **Riprendi**: ferma o fa ripartire l'elaborazione delle righe in coda.
- **Elimina**: cancella il lotto, solo se nessuna riga è stata ancora elaborata (per esempio un lotto caricato per errore e messo subito in pausa).
- **Annulla Pendenze**: annulla in blocco tutte le pendenze del lotto create con successo. Usalo solo se il lotto è da ritirare per intero: l'operazione non si annulla.
- **Vedi righe**: apre il dettaglio, riga per riga, con l'eventuale errore restituito da GovPay.

Nel dettaglio:

- **Riprova** rimette in coda una riga in errore. La pendenza viene reinviata con lo stesso identificativo, quindi non nascono doppioni. Le righe scartate già al caricamento sono **Non riprovabile**: vanno corrette nel file.
- **Scarica tracciato** restituisce il CSV di partenza con i numeri avviso assegnati.
- **Genera ZIP avvisi** prepara in background uno ZIP con tutti gli avvisi PDF (multirata per le rateizzate). Quando è pronto premi **Scarica ZIP**.

### Usare i template personali

Se crei spesso le stesse pendenze, salva un template da **Profilo → Template assegnati**: titolo, tipologia, importo e causale. Lo ritrovi in **Seleziona per autocompilare** della nuova pendenza.

Nel profilo vedi anche i template assegnati dagli amministratori a te o al tuo gruppo: quelli **Ereditato dal gruppo** restano sempre disponibili.

### Consultare la dashboard

La **Dashboard** mostra:

- gli incassi complessivi e l'andamento degli ultimi 6 mesi;
- la ripartizione tra pendenze interne (create in GovPay) ed esterne (pagate su altri sistemi e rendicontate all'Ente);
- la ripartizione per tipologia di pendenza: le prime 6, con il pulsante per vederle tutte, nel periodo scelto (ultimi 30 giorni, mese scorso, anno corrente…);
- gli ultimi flussi rendicontati;
- l'avviso **Smarcature manuali in attesa**, se ci sono pagamenti da confermare (vedi «Confermare i pagamenti da smarcare»).

### Consultare i flussi di rendicontazione

Il flusso di rendicontazione è l'elenco dei pagamenti che la banca tesoriera ha accreditato all'Ente in un giorno. Apri **Ragioneria → Ricerca flussi**:

1. Filtra per intervallo date, ID flusso, stato (ACQUISITO, ELABORATO, SCARTATO) o **Solo incassati**.
2. Apri **Dettaglio** per vedere i singoli pagamenti del flusso: IUV, causale, tipologia, importo ed esito.

Esiti dei pagamenti nel flusso:

| Codice | Significato |
| --- | --- |
| 0 | Pagamento eseguito |
| 3 | Pagamento revocato |
| 4 | Stand-in |
| 8 | Stand-in senza RPT |
| 9 | Pagamento senza RPT |

Admin e superadmin possono segnare un flusso come regolarizzato con **Regolarizza Flusso**.

### Produrre il report ragioneria

**Ragioneria → Report ragioneria** riepiloga gli incassi reali, presi dai flussi di rendicontazione, per tipologia (codice entrata) e per data del flusso.

1. Scegli l'**Intervallo data flusso** e, se serve, le tipologie (vuoto = tutte), il codice fiscale o l'anagrafica del debitore, l'origine (interne, esterne o tutte).
2. Premi **Aggiorna**. Alla prima apertura non parte nessuna ricerca; dopo **Aggiorna** compare l'avanzamento dell'elaborazione.
3. Leggi il **Riepilogo per tipologia** (numero di pagamenti interni ed esterni, importo) e il **Dettaglio rendicontazioni**.
4. Scarica tutto con **Esporta CSV**.

Il riquadro sullo stato delle pendenze esterne indica quante sono già state arricchite con i dati della ricevuta. Il report è completo solo quando non ci sono righe **IN ATTESA**: se ne restano molte, i servizi di sincronizzazione stanno ancora lavorando (vedi «Il report ragioneria è incompleto»).

### Produrre il report incassi

**Ragioneria → Report incassi** riepiloga i pagamenti riscossi nel periodo per tassonomia pagoPA, a partire dai dati di GovPay. Filtra per intervallo date e tipologie, premi **Aggiorna**, scarica con **Export CSV**.

### Report TEFA

La voce **Ragioneria → Report TEFA** compare solo se la gestione TEFA è attiva. Il report separa, nei pagamenti multibeneficiario, la quota TEFA (tributo provinciale) da quella del Comune e la riepiloga per Comune.

Il riquadro **Anomalie ratio TEFA/Comune** elenca i pagamenti in cui la quota TEFA è fuori dall'intervallo atteso (1%–10%). Verificali caso per caso:

- **Sì TEFA**: accetti il pagamento come TEFA;
- l'altra scelta conferma l'esclusione definitiva.

Le righe in errore si rielaborano con **Riprova tutti**.

### Analizzare le statistiche

**Statistiche** analizza le entrate dell'Ente sul database locale (flussi, ricevute, TEFA, mapping). Scegli il periodo e il raggruppamento, premi **Aggiorna Dati**. Clicca una colonna della tabella per ordinarla o un grafico per filtrare.

### Confermare i pagamenti da smarcare

Per alcune tipologie l'Ente vuole che un operatore confermi ogni pagamento prima di considerarlo chiuso. L'amministratore le assegna al gruppo dell'ufficio in modalità di notifica e smarcatura.

1. Apri **Smarcatura pagamenti**: il numero rosso accanto alla voce indica i pagamenti in attesa.
2. Controlla debitore, tipologia, causale, importo e data di pagamento.
3. Seleziona le righe e premi **Conferma selezionate**.

Vedi solo le tipologie assegnate ai tuoi gruppi; admin e superadmin le vedono tutte. Alla conferma il cittadino riceve, se possibile, la notifica App IO di pagamento registrato.

### Gestire il profilo

Da **Profilo** (menu in alto a destra):

- aggiorni nome e cognome e scegli la **Tipologia di default** proposta nelle nuove pendenze;
- cambi la password (almeno 8 caratteri);
- con **Ricevi tutte le notifiche di rendicontazione** ricevi via email tutti i riepiloghi di rendicontazione, non solo quelli dei tuoi gruppi;
- crei e scegli i template personali.

## Funzioni avanzate (admin e superadmin)

### Gestire i servizi in background

**Cron / Servizi** mostra i servizi che lavorano in automatico: sincronizzazione dei flussi (ragioneria), ricevute dei pagamenti esterni (Biz Events), classificazione TEFA, mapping delle pendenze esterne, scanner debitore GovPay, motore di rendicontazione, pendenze massive.

Per ogni servizio vedi l'ultimo giro, il prossimo e, se è al lavoro, il giro in corso con elementi elaborati e restanti. I comandi:

- **Pausa** / **Attiva**: ferma o riattiva il servizio. Ha effetto alla fine del blocco in corso.
- **Esegui ora**: avvia subito un giro, anche se il servizio è in pausa. Se un giro è già in corso, quello richiesto parte al termine.
- **Visualizza Log**: storico dei giri con esito ed errori.
- **Rescan tutto** (ragioneria): rilegge tutti i flussi dalla data di inizio sincronizzazione.

Gli stessi comandi sono anche nel pannello **GIL Services Hub** della dashboard (solo superadmin).

### Classificare le pendenze esterne

Le pendenze esterne sono pagamenti nati su altri sistemi (per esempio gestionali di tributi) che arrivano all'Ente nei flussi di rendicontazione. **Mapping pendenze est.** le classifica in due livelli:

1. **Livello 1 – Fornitore**: dal prefisso dello IUV (i primi caratteri) riconosce il sistema di provenienza. Il sistema propone da solo i prefissi ricorrenti (**SCOPERTO**); una regola diventa attiva da 5 transazioni in su, oppure subito se è creata a mano (**CUSTOM**).
2. **Livello 2 – Tipologia**: cerca parole chiave nella causale e assegna il codice entrata. Le keyword con priorità più alta vengono provate per prime; se nessuna corrisponde vale la tipologia di ripiego della regola di livello 1.

Comandi utili: **Applica ora (L1+L2)** applica subito le regole; **Accorpa Pattern IUV** unisce due prefissi che appartengono allo stesso fornitore; **Reset e Rielabora** ricomincia la classificazione da zero.

### Ritentare le rendicontazioni in errore (solo superadmin)

**Errori smarcatura** (in Funzioni avanzate) elenca i pagamenti GovPay che il motore di rendicontazione non è riuscito a trasmettere al gestionale esterno o a leggere da GovPay. Il motore li riprova da solo solo entro il numero di giorni configurato; con **Forza** ritenti una singola riga anche fuori da questa finestra.

### Impostazioni

**Impostazioni** raccoglie la configurazione dell'Ente. Gli admin la vedono in sola lettura, tranne utenti e gruppi; i superadmin possono modificare tutto. Le schede principali:

- **Generale**: dati di base dell'Ente, SMTP (posta in uscita), backoffice, frontoffice (portale cittadini e accesso con identità digitale).
- **GovPay**: connessione, dati dominio, **Conti di accredito** (IBAN: aggiunta, modifica, abilitazione), applicazioni, tassonomie, **Tipologie pagamento**, pendenze esterne, gestionali, **Template pendenze**.
- **Utenti**: **Utenti GIL** (account dello Sportello), **Gruppi Utenti** (template e tipologie di rendicontazione per ufficio), **Operatori GovPay**.
- **API esterne**: pagoPA Checkout, @e.bollo, BizEvents, Tassonomie, App IO.
- **TEFA** e **Rendicontazione GovPay** (motore di rendicontazione e regole verso i gestionali esterni).
- **Sistema**: Backup, Debug, Info GovPay, Info GIL, Logs.

Gli IBAN si disabilitano, non si cancellano: GovPay non prevede l'eliminazione di un conto.

### Gestire utenti e gruppi

Da **Impostazioni → Utenti GIL** crei o modifichi un account (email, nome, cognome, ruolo, password) e lo disabiliti o riabiliti. Un account disabilitato non può accedere. Non puoi agire sul tuo account né disabilitare l'ultimo superadmin attivo.

Da **Gruppi Utenti** raggruppi gli operatori di un ufficio: al gruppo assegni i template e le tipologie da confermare in **Smarcatura pagamenti**.

## Regole e riferimenti

### Ruoli e permessi

| Funzione | user | admin | superadmin |
| --- | --- | --- | --- |
| Ricerca, dettaglio, avvisi e ricevute | sì | sì | sì |
| Nuova pendenza, rateizzazione, marca da bollo, massivo | sì | sì | sì |
| Modifica tipologia di una pendenza esistente | no | sì | sì |
| Report ragioneria, incassi, TEFA, statistiche | sì | sì | sì |
| Conferma pagamenti da smarcare | tipologie dei suoi gruppi | tutte | tutte |
| Regolarizza flusso | no | sì | sì |
| Cron / Servizi, Mapping pendenze esterne | no | sì | sì |
| Errori smarcatura | no | no | sì |
| Impostazioni | no | lettura, più utenti e gruppi | completo |
| Backup, chiavi di cifratura | no | no | sì |

### Pendenze interne ed esterne

- **Interne**: create in GovPay, dallo Sportello, dal portale cittadini o da un gestionale collegato. Hanno dettaglio, avviso e ricevuta nello Sportello.
- **Esterne**: pagamenti nati su altri sistemi che arrivano nei flussi di rendicontazione. I dati del pagatore e la causale arrivano dalla ricevuta pagoPA (Biz Events); fornitore e tipologia dal mapping. Compaiono nei report, non nella ricerca pendenze.

### IUV e numero avviso

Lo **IUV** (17 cifre) identifica il pagamento presso l'Ente. Il **numero avviso** (18 cifre) è lo IUV preceduto da una cifra di servizio ed è quello stampato sull'avviso e usato dal cittadino per pagare. La barra di ricerca accetta entrambi.

## Problemi frequenti

### La pendenza risulta pagata ma lo stato è ancora NON_ESEGUITA

**Causa:** il cittadino ha avviato il pagamento ma non l'ha completato. Nella sezione **Mostra tentativi di pagamento** il tentativo è **RPT_SCADUTA**, cioè interrotto dopo 30 minuti.

**Soluzione:** chiedi al cittadino la ricevuta del pagamento. Senza ricevuta il pagamento non è avvenuto e la pendenza resta da pagare.

**Apri un ticket se:** il cittadino ha una ricevuta valida e dopo un giorno lavorativo la pendenza è ancora NON_ESEGUITA.

### Il cittadino non riesce a pagare: «avviso già pagato o in corso»

**Causa:** pagoPA blocca per qualche minuto un avviso con un pagamento in corso, anche se non è stato completato, oppure l'avviso è già stato pagato o annullato.

**Soluzione:** controlla lo stato nel dettaglio della pendenza. Se è NON_ESEGUITA, fai riprovare al cittadino dopo 30 minuti.

**Apri un ticket se:** dopo 30 minuti il pagamento è ancora rifiutato.

### Non riesco a modificare o annullare una pendenza

**Causa:** i pulsanti **Modifica** e **Annulla** compaiono solo per le pendenze NON_ESEGUITA. Le pendenze pagate non si possono modificare; quelle annullate si possono solo riattivare.

**Soluzione:** per correggere una pendenza pagata serve una nuova pendenza o un rimborso, secondo le procedure dell'ufficio.

### Una riga del caricamento massivo è in errore

**Causa:** GovPay ha rifiutato la pendenza (dati non validi) oppure non era raggiungibile.

**Soluzione:**

1. Apri il lotto da **Storico Massivo** e premi **Vedi righe**.
2. Leggi l'errore della riga: per un problema temporaneo (GovPay non raggiungibile) premi **Riprova**.
3. Per un errore nei dati correggi il file e carica solo le righe interessate in un nuovo lotto.

**Apri un ticket se:** **Riprova** fallisce più volte con lo stesso errore e i dati sono corretti.

### Il caricamento massivo non avanza

**Causa:** il lotto è in pausa, oppure il servizio **Pendenze massive** è in pausa in **Cron / Servizi**.

**Soluzione:** in **Storico Massivo** premi **Riprendi** sul lotto. Se resta fermo, chiedi a un amministratore di controllare il servizio in **Cron / Servizi**.

### Il report ragioneria è incompleto

**Causa:** i servizi di sincronizzazione non hanno ancora elaborato tutti i flussi del periodo, o le ricevute dei pagamenti esterni sono ancora **IN ATTESA**.

**Soluzione:** controlla i contatori sopra il report e i mesi non scansionati. Se le righe in attesa calano, aspetta che finiscano. Se ci sono **ERRORI**, un amministratore può rimetterle in coda con **Reset errori Biz**.

**Apri un ticket se:** i contatori restano fermi per più di un giorno.

### Il riquadro in alto indica «GovPay: Offline»

**Causa:** lo Sportello non riesce a contattare GovPay.

**Soluzione:** clicca il riquadro per leggere il motivo e riprova dopo qualche minuto. Se anche i colleghi vedono Offline, il problema non è del tuo PC.

**Apri un ticket se:** resta Offline per più di 15 minuti.

### Non vedo una voce del menu

**Causa:** alcune voci dipendono dal ruolo (vedi «Ruoli e permessi») o dalla configurazione dell'Ente: **Nuova marca da bollo** compare solo con @e.bollo attivo, **Report TEFA** solo con la gestione TEFA attiva.

**Soluzione:** chiedi a un amministratore se la funzione è prevista per il tuo ruolo o per l'Ente.

### Non riesco ad accedere

**Causa:** email o password errate, account disabilitato, oppure un accesso più recente con lo stesso account da un altro PC ha chiuso la tua sessione.

**Soluzione:**

1. Controlla di usare l'email dell'account operatore.
2. Usa **Hai dimenticato la password?** per impostarne una nuova.
3. Se il link non arriva, chiedi a un amministratore di verificare che l'account sia attivo.

**Apri un ticket se:** l'account è attivo, la password è stata reimpostata e l'accesso fallisce ancora.

## Contatti

Per problemi non risolti con questa guida apri un ticket dalla plancia dei dipendenti (CruscottoPA), scegliendo lo Sportello pagamenti. Indica il numero avviso o l'ID della pendenza interessata e il messaggio di errore esatto.
