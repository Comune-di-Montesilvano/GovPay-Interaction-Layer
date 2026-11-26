# Rateizzazione con rata unica a scelta — design breve

## Intento

Istanze urbanistiche: il cittadino sceglie se pagare in unica soluzione o a rate. L'operatore
crea il piano dalla funzione rateizzazione backoffice con una spunta "Crea anche rata unica".
Stampa: PDF documento GovPay (`/documenti/{idDominio}/{numeroDocumento}/avvisi`), che aggrega
già unica soluzione + rate (usato in passato). Al pagamento di un'opzione GIL annulla l'altra.

## Obiettivi / non-obiettivi

- Sì: spunta nel form rateizzazione backoffice (`pendenze/rateizzazione.html.twig`,
  `PendenzeController::createRateizzazione()`).
- Sì: annullamento automatico della controparte quando il pagamento viene rendicontato.
- No: massivo CSV (`MassivoRateizzazioneService`), frontoffice, nuova tabella, nuovo lavoro worker.
- No: prevenzione del doppio pagamento nella finestra prima della rendicontazione (1-2 giorni):
  scelta deliberata, a carico del cittadino.

## Approcci considerati

- **A. Aggancio al motore `rendicontazione-govpay` — scelto.** Ritardo = arrivo flusso (1-2 gg),
  zero infrastruttura nuova. Doppio pagamento nella finestra accettato.
- B. Lavoro periodico dedicato (polling stato GovPay ~15 min) + tabella piani: finestra
  stretta, ma migrazione + lavoro nuovo. Scartato: non vale il costo per questo caso.
- C. A + B: scartato, eccessivo.

## Design

### Creazione (backoffice)

- Checkbox `crea_rata_unica` nel form rateizzazione (default spenta).
- Se spuntata, oltre alle N rate `<base>-R<i>` crea una pendenza in più `<base>-RU`:
  - stesso `documento.identificativo`, **senza** `documento.rata` (= unica soluzione per GovPay);
  - importo = totale, voci originali non ripartite;
  - causale `"<causale> (Rata unica)"`;
  - `dataValidita`/`dataScadenza` = quelle della rata 1 (proposta, modificabile dall'operatore
    in un campo dedicato del form).
- Marker in `datiAllegati.rateizzazioneScelta` su TUTTE le pendenze del piano:
  `{ "idRataUnica": "<base>-RU", "idRate": ["<base>-R1", ...] }`. Rateizzazioni ordinarie
  senza marker: comportamento invariato.
- La pendenza RU si crea per prima: se fallisce, nessuna rata viene creata (piano
  "a scelta" mai parziale). Se una rata fallisce dopo, stesso comportamento di oggi (errori
  mostrati all'operatore).
- Notifiche email/App IO di creazione: invariate, con riga in più per la rata unica.

### Annullamento (motore rendicontazione)

- In `RendicontazioneEngineService::processaRigaSpecifica()`, subito dopo la GET della
  pendenza e **prima** del routing (il pagamento è un fatto, anche se la riga va poi in
  `IN_ATTESA_CONFERMA` o al ponte legacy): se c'è `datiAllegati.rateizzazioneScelta`:
  - pagata `idRataUnica` → annulla tutte le `idRate`;
  - pagata una delle `idRate` → annulla `idRataUnica`.
- Per ogni controparte: GET stato; `NON_ESEGUITA`/`SCADUTA` → PATCH `/stato` = `ANNULLATA`
  (stesso formato di `PendenzeController::updatePendenzaStatus()`, URL
  `govpay.pendenze_patch_url` con fallback `backoffice_url`); già `ANNULLATA` → nulla;
  `ESEGUITA`/`ESEGUITA_PARZIALE`/`INCASSATA` → doppio pagamento: `Logger::warning` (→ Sentry)
  con id pendenze, nessuna azione automatica.
- Idempotente: le righe `ERRORE` ripassate dal motore rifanno il controllo senza effetti.
- Un errore di annullamento NON blocca la rendicontazione della riga: warning + Sentry,
  si riprova al successivo passaggio solo se la riga torna in ciclo (best-effort).

### Test

- Unit: costruzione payload RU + marker (estrarre la logica in un servizio puro testabile).
- Unit: decisione controparti da annullare dato marker + id pagato + stati (logica pura).
- Motore: test con client Guzzle mock (pattern di `RendicontazioneJobTest`).

## Lasciato al builder

- Nome/posizione del servizio puro (es. `App\Services\RateizzazioneSceltaService`).
- Testo esatto label/aiuto della checkbox e riga nelle notifiche.
- Verifica in ambiente di test che GovPay accetti in PUT `datiAllegati` arbitrari e li
  restituisca nella GET backoffice (già usato per `datiAllegati.notifiche`).
