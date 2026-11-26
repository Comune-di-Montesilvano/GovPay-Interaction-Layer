# Rateizzazione con rata unica a scelta — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Spunta "Crea anche rata unica" nella rateizzazione backoffice + annullamento automatico della controparte quando il motore `rendicontazione-govpay` elabora il pagamento.

**Architecture:** Logica pura (id, marker, decisione controparti/azione) in un servizio statico testabile. Il controller crea la pendenza `<base>-RU` prima delle rate e mette il marker `datiAllegati.rateizzazioneScelta` su tutte. Il motore, dopo la GET della pendenza pagata e prima del routing, legge il marker e annulla le controparti via PATCH `/stato`.

**Tech Stack:** PHP 8.5, Slim 4, Twig 3, Guzzle 7, PHPUnit (test DB su MariaDB usa-e-getta).

**Spec:** `docs/superpowers/specs/2026-11-26-rateizzazione-con-rata-unica-design.md`

## Global Constraints

- id rata unica = `<base>-RU`; rate invariate `<base>-R<i>`; tutti ≤ 35 char, `[A-Za-z0-9\-_]`.
- Marker: `datiAllegati.rateizzazioneScelta = {"idRataUnica": "<base>-RU", "idRate": ["<base>-R1", ...]}` su TUTTE le pendenze del piano.
- Rata unica: stesso `documento.identificativo`, **nessun** `documento.rata`, importo = totale, causale `"<causale> (Rata unica)"`, date default = rata 1.
- Rateizzazioni senza spunta: payload identico a oggi (nessun marker).
- Annullamento: `NON_ESEGUITA`/`SCADUTA` → PATCH `[{"op":"REPLACE","path":"/stato","value":"ANNULLATA"}]` su `govpay.pendenze_patch_url` (fallback `$backofficeUrl`); `ANNULLATA` → nulla; `ESEGUITA`/`ESEGUITA_PARZIALE`/`INCASSATA` → `Logger::getInstance()->warning` "doppio pagamento" con id, nessuna azione.
- Errori annullamento non bloccano la riga (warning, poi routing normale). Mai URL/corpi upstream nei log (usare `ThrowableSummary::of($e)`).
- Fixture test sintetiche (es. `GIL-0000000000000001-RU`), mai dati reali. `Logger::getInstance()->...`, mai statico.

## Review Focus

- Riga `ERRORE` ripassata dal motore dopo annullamento già fatto → nessuna seconda PATCH (controparte già `ANNULLATA`). Test in Task 3.
- Controparte GET 404 / 500 → warning, riga comunque `GESTITO`. Test in Task 3.
- Pagata rata 2 quando la RU è già `ANNULLATA` (rata 1 pagata prima) → nessuna PATCH. Coperto dal test idempotenza Task 3.
- Marker malformato (non array, `idRate` non lista, id pagato non presente nel marker) → nessuna azione, nessuna eccezione. Test in Task 1.
- Creazione RU fallita → nessuna rata creata, errore mostrato all'operatore. Verifica manuale Task 2 (controller non coperto da unit test).

---

### Task 1: Servizio puro `RateizzazioneSceltaService`

**Files:**
- Create: `app/Services/RateizzazioneSceltaService.php`
- Test: `tests/Services/RateizzazioneSceltaServiceTest.php`

**Interfaces:**
- Produces:
  - `RateizzazioneSceltaService::idRataUnica(string $baseId): string` → `"<base>-RU"` (sanitize `[^A-Za-z0-9\-_]`→`-`; tronca la base così che il totale sia ≤ 35).
  - `RateizzazioneSceltaService::buildMarker(string $idRataUnica, array $idRate): array` → `['idRataUnica'=>..., 'idRate'=>list<string>]`.
  - `RateizzazioneSceltaService::controparti(mixed $datiAllegati, string $idPagata): list<string>` → `[]` se marker assente/malformato o id non nel marker; tutte `idRate` se pagata la RU; `[idRataUnica]` se pagata una rata.
  - `RateizzazioneSceltaService::azione(string $statoControparte): string` → `'ANNULLA'` (`NON_ESEGUITA`,`SCADUTA`), `'DOPPIO_PAGAMENTO'` (`ESEGUITA`,`ESEGUITA_PARZIALE`,`INCASSATA`), `'NESSUNA'` (altro, incl. `ANNULLATA`, vuoto).

- [ ] **Step 1: Test che falliscono** — `testIdRataUnica` (`'GIL-0000000000000001'` → `'GIL-0000000000000001-RU'`; base di 40 char → risultato di 35 che termina con `-RU`), `testContropartiDaRataUnica` (marker con 3 rate, pagata RU → le 3 rate), `testContropartiDaRata` (pagata `-R2` → `['…-RU']`), `testContropartiMarkerAssenteOMalformato` (`null`, `[]`, `['rateizzazioneScelta'=>'x']`, `idRate` stringa, id estraneo → `[]`), `testAzione` (tabella stati sopra). Input `controparti()` = intero `datiAllegati` della GET (chiave `rateizzazioneScelta` dentro).
- [ ] **Step 2:** `php vendor/bin/phpunit tests/Services/RateizzazioneSceltaServiceTest.php` → FAIL (classe mancante).
- [ ] **Step 3:** Implementa `final class` con metodi statici sopra.
- [ ] **Step 4:** stesso comando → PASS. `vendor/bin/phpstan analyse app/Services/RateizzazioneSceltaService.php` → 0 errori.
- [ ] **Step 5:** commit `feat(rateizzazione): servizio rata unica a scelta`.

### Task 2: Creazione rata unica nel backoffice

**Files:**
- Modify: `backoffice/templates/pendenze/rateizzazione.html.twig` (sotto la tabella rate, prima dei bottoni ~riga 88)
- Modify: `backoffice/templates/pendenze/conferma.html.twig:147-160` (riepilogo)
- Modify: `backoffice/src/Controllers/PendenzeController.php` `createRateizzazione()` (1067-1650)

**Interfaces:**
- Consumes: Task 1 `idRataUnica()`, `buildMarker()`.
- Produces: campi form `crea_rata_unica` (`"1"`), `rata_unica_scadenza` (`Y-m-d`, opzionale). Passano scalari: `conferma.html.twig` li riemette già nel ramo generico degli hidden — verificare, altrimenti aggiungerli.

- [ ] **Step 1: Template rateizzazione** — checkbox `name="crea_rata_unica" value="1"` label "Crea anche rata unica (il cittadino sceglie: unica soluzione o rate)"; input date `name="rata_unica_scadenza"` visibile solo con spunta, precompilato via JS con la scadenza della rata 1 se vuoto. Preservare i valori al re-render (errore somma) leggendo `params.crea_rata_unica`/`params.rata_unica_scadenza`.
- [ ] **Step 2: Riepilogo conferma** — se `params.crea_rata_unica`, riga "Rata unica: <totale> € — scadenza <data o rata 1>" sopra la tabella rate.
- [ ] **Step 3: Refactor senza cambi di comportamento** — estrai dal loop rate il blocco sanitizzazione (bollo, `soggettoPagatore`, string fields, `proprieta`, `$allowed`, rimozione `idPendenza`, righe 1310-1395) in `private function preparaPayloadRata(array $single): array`. Il loop lo chiama; risultato identico.
- [ ] **Step 4: Creazione RU** — se `!empty($orig['crea_rata_unica'])`, dopo il calcolo di `$baseId`/`$rateIdTipo` e PRIMA del loop rate:
  - calcola gli id rate (`<base>-R<i>`, stesso calcolo del loop — estrai `$idPForRate` in un helper o precalcola un array usato da entrambi) e `$idRu = RateizzazioneSceltaService::idRataUnica($baseId)`;
  - `$marker = buildMarker($idRu, $idRate)`; `$merged['datiAllegati']['rateizzazioneScelta'] = $marker` (così finisce in ogni rata);
  - payload RU da `$merged`: importo totale, `documento` senza chiave `rata`, causale/descrizione documento come da Global Constraints, voci via `buildVociForInsertionFromMerged()` senza ripartizione (descrizione voce + `" (Rata unica)"`), date = `rata_unica_scadenza` o quelle della rata 1 (`dataValidita` = stessa data), poi `preparaPayloadRata()` e `sendPendenzaToBackoffice($payload, $idRu)`;
  - fallimento → render `rateizzazione.html.twig` con errori `"Rata unica: …"`, **nessuna rata inviata**.
- [ ] **Step 5: Esito + notifiche** — RU in `$_SESSION['multi_rate_document']` come `'rataUnica' => [idPendenza, importo, dataScadenza, numeroAvviso]` (stessa estrazione `numeroAvviso` delle rate); `$emailData['rataUnica']` con `pdfUrl`/`paymentUrl` come per le rate e riga dedicata in `MailerService::sendRateizzazioneNotification()` ("Oppure in unica soluzione: …") resa solo se presente; App IO: un messaggio anche per la RU nel loop esistente.
- [ ] **Step 6: Verifica** — `php -l` sui file toccati; `vendor/bin/phpstan analyse` → 0 errori; `php vendor/bin/phpunit` → tutto verde. Manuale su stack locale (GovPay di test, worker spento): piano 3 rate con spunta → 4 pendenze, GET `<base>-RU` senza `documento.rata` e con `datiAllegati.rateizzazioneScelta`; PDF `/documenti/{idDominio}/{doc}/avvisi` mostra unica + rate; senza spunta payload invariato (nessun marker).
- [ ] **Step 7:** commit `feat(rateizzazione): opzione rata unica a scelta`.

### Task 3: Annullamento controparte nel motore rendicontazione

**Files:**
- Modify: `app/Services/RendicontazioneEngineService.php` (`processaRigaSpecifica()` dopo riga 72)
- Test: `tests/Services/Jobs/RendicontazioneJobTest.php` (gruppo `db`)

**Interfaces:**
- Consumes: Task 1 `controparti()`, `azione()`.
- Produces: `private function annullaControparti(array $pendenza, string $idPendenza, string $idA2A, string $backofficeUrl): void` — mai eccezioni verso il chiamante.

- [ ] **Step 1: Estendi il fake GovPay del test** — ramo `PATCH` su `#/pendenze/[^/]+/([^/]+)$#` → kind `'patch'`, risposta da `$this->patchResponses[$id] ?? new Response(200)`; i GET controparte usano già `$this->pendenze[$id]` (stato via chiave `stato`).
- [ ] **Step 2: Test che falliscono** (base `GIL-0000000000000001`, marker con `-RU`, `-R1`, `-R2`, `-R3`):
  - `testPagataRataUnicaAnnullaTutteLeRate` — riga con `id_pendenza` `…-RU`, GET RU con marker; rate `NON_ESEGUITA` → 3 chiamate `patch` con body `[{"op":"REPLACE","path":"/stato","value":"ANNULLATA"}]`; riga `GESTITO`.
  - `testPagataRataAnnullaRataUnica` — pagata `…-R1` → 1 `patch` su `…-RU`.
  - `testControparteGiaAnnullataNessunaPatch` — RU `ANNULLATA` → 0 `patch`, `GESTITO`.
  - `testDoppioPagamentoNessunaPatch` — pagata `…-R1`, RU `ESEGUITA` → 0 `patch`, `GESTITO`.
  - `testErroreControparteNonBloccaRiga` — GET RU 500 o PATCH 500 → `GESTITO`, nessun corpo upstream in `rendicontazione_errore`.
  - `testSenzaMarkerNessunaChiamataExtra` — pendenza ordinaria → nessuna chiamata oltre a quelle di oggi.
- [ ] **Step 3:** `DB_HOST=127.0.0.1 DB_PORT=3307 DB_NAME=appdb DB_USER=appuser DB_PASSWORD=apppass BACKOFFICE_DB_USER=appuser php -d extension=pdo_mysql -d extension=curl vendor/bin/phpunit --filter 'RataUnica|Controparte|DoppioPagamento|SenzaMarker' tests/Services/Jobs/RendicontazioneJobTest.php` → FAIL.
- [ ] **Step 4: Implementa** `annullaControparti()` e chiamala subito dopo il controllo `!is_array($pendenza)`: per ogni id da `controparti($pendenza['datiAllegati'] ?? null, $idPendenza)` → GET `<backofficeUrl>/pendenze/{idA2A}/{id}` con `$this->govPayClient`, `azione($stato)`; `ANNULLA` → PATCH su `rtrim(SettingsRepository::get('govpay','pendenze_patch_url','') ?: $backofficeUrl,'/')` stesso path, header `Content-Type: application/json`; `info` log a esito OK; `DOPPIO_PAGAMENTO` → warning `"Rateizzazione a scelta: doppio pagamento"` con `id_pagata`/`id_controparte`; ogni `Throwable` (per singola controparte, il loop continua) → warning con `ThrowableSummary::of($e)`.
- [ ] **Step 5:** comando Step 3 senza `--filter` → tutto verde; `vendor/bin/phpstan analyse` → 0 errori.
- [ ] **Step 6:** commit `feat(rendicontazione): annulla controparte rateizzazione a scelta`.

### Task 4: Documentazione

**Files:**
- Modify: `backoffice/guida/guida.md` (sezione rateizzazione), `CLAUDE.md` (riga nei servizi chiave + gotcha: annullamento solo alla rendicontazione, doppio pagamento possibile nella finestra, deciso)

- [ ] **Step 1:** guida operatore: spunta, scadenza RU, stampa documento aggregata, annullamento automatico dopo la rendicontazione (1-2 gg), doppio pagamento = warning, gestione manuale rimborso.
- [ ] **Step 2:** commit `docs: rateizzazione con rata unica a scelta`.
