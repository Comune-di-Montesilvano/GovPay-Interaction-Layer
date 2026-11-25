# Prova con screen reader: "Paga un avviso"

Traccia per una prova manuale del percorso principale del frontoffice con screen reader. Integra i
controlli automatici (axe in `e2e/tests/07-accessibilita.spec.ts` e
`09-accessibilita-frontoffice.spec.ts`): axe trova attributi e contrasti sbagliati, ma non dice
se il percorso si capisce e si completa senza vedere lo schermo. Da ripetere prima di ogni
release minor e dopo modifiche ai template di `frontoffice/templates/pagamenti/` o
`checkout/`.

Durata: circa 20 minuti per combinazione.

## Ambiente

**Mai su produzione**: il percorso arriva al checkout e paga. Usare lo stack e2e locale, che ha
GovPay e pagoPA finti e una pendenza già pronta:

```bash
E2E_KEEP=1 E2E_GREP='operatore|cittadino' E2E_IMAGES_FILE=<override immagini> bash e2e/run.sh
```

Il frontoffice risponde su `http://frontoffice` solo dentro la rete compose: per raggiungerlo dal
browser del PC pubblicare temporaneamente la porta (es. `docker compose -p gil-e2e port
frontoffice 80`, oppure un override locale con `ports: ["8081:80"]`). Dati di prova sintetici:

- codice fiscale: `RSSMRA80A01H501U`
- numero avviso: quello della pendenza creata dal flusso operatore
  (`curl http://fake-govpay:8080/__fake/pendenze` dalla rete compose, campo `numeroAvviso`)

Combinazioni da provare (almeno le prime due):

| Screen reader | Browser | Sistema |
|---|---|---|
| NVDA (ultima versione) | Firefox | Windows |
| VoiceOver | Safari | macOS o iOS |
| TalkBack | Chrome | Android |

Comandi utili: NVDA `Tab`/`Maiusc+Tab` (controlli), `H` (titoli), `D` (aree/landmark),
`F` (campi), `Ins+F7` (elenco elementi). VoiceOver `Ctrl+Opt+frecce`, rotore `Ctrl+Opt+U`.

## Passi

Per ogni passo annotare: esito (OK / KO / dubbio), cosa è stato letto, screen reader + browser.

| # | Azione | Atteso |
|---|---|---|
| 1 | Aprire la home, premere `Tab` una volta | Primo elemento: link "Vai al contenuto principale"; con `Invio` il focus va al contenuto |
| 2 | Elenco titoli (`H` / rotore) | Un solo titolo di livello 1, titoli in ordine logico |
| 3 | Elenco aree (`D` / rotore) | Intestazione, navigazione, contenuto principale, piè di pagina riconoscibili |
| 4 | Raggiungere "Paga un avviso" e attivarlo | Titolo della pagina letto: "Paga un avviso o scarica la ricevuta" |
| 5 | `Tab` sul campo codice avviso | Letti: etichetta "Codice avviso / IUV", "obbligatorio", **e il suggerimento** "Lo trovi sul bollettino…" |
| 6 | `Tab` sul campo codice fiscale | Etichetta, obbligatorio e suggerimento (16 caratteri o 11 cifre) |
| 7 | Inviare il form vuoto | Il browser segnala il primo campo obbligatorio mancante e ci porta il focus |
| 8 | Inviare un codice avviso inesistente (`000000000000000099`) | Messaggio d'errore letto senza dover cercarlo (area `role="status"`), testo comprensibile |
| 9 | Inviare codice fiscale e avviso corretti | Titolo "Avviso <numero>" letto; importo, causale e scadenza raggiungibili con le frecce, importo letto come cifra in euro |
| 10 | Esplorare i pulsanti dell'anteprima | Ogni pulsante ha un nome che dice cosa fa ("Paga subito", "Aggiungi al carrello", "Stampa / Scarica PDF"); i link che aprono una nuova scheda vanno segnalati come tali, altrimenti annotare KO |
| 11 | Attivare "Paga subito" | Si arriva al checkout finto; tornare con "Esito OK" |
| 12 | Pagina di esito | Messaggio di pagamento riuscito letto subito all'arrivo (`role="alert"`), link alla ricevuta raggiungibile |
| 13 | Ripetere la ricerca dell'avviso (ora pagato) | Stato "pagato" letto nell'anteprima, link "ricevuta" presente con nome chiaro |
| 14 | Attivare il download della ricevuta | Il file scaricato viene annunciato dal browser |
| 15 | Ripetere 4–9 con zoom al 200% e da telefono | Nessun contenuto tagliato, nessuno scorrimento orizzontale, ordine di lettura invariato |

## Esito

Riportare l'esito in una issue con etichetta `accessibilita`:

- data, versione GIL (`APP_VERSION`), screen reader + browser + sistema;
- per ogni KO: numero del passo, cosa è stato letto e cosa ci si aspettava, URL della pagina;
- i KO che riguardano più pagine (es. componenti di `base.html.twig`) una sola volta, con
  l'elenco delle pagine.

Un KO ai passi 5–9 o 12 blocca il pagamento senza vista: va corretto prima della release
successiva.
