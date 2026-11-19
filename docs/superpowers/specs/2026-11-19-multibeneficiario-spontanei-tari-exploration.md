# Multibeneficiario per spontanei TARI — note di esplorazione

> Stato: **esplorazione**, nessun design approvato. Bozza parcheggiata per riprendere il brainstorming.

## Richiesta

Pagamento spontaneo TARI dal frontoffice cittadino come **pendenza multibeneficiario** (specifiche pagoPA):
una sola pendenza/avviso con più voci accreditate a enti diversi — quota TARI al Comune, quota TEFA
(tributo provinciale) accreditata direttamente alla Provincia.

## Stato attuale (2026-11-19)

- Spontaneo frontoffice = **voce singola**: `FrontofficeApiController::createPendenza()` chiama
  `PendenzeController::buildVociWithAccounting()` con una sola voce; IBAN / contabilità dalla tipologia
  del dominio dell'ente (`EntrateRepository::findDetails`).
- Nessun supporto multibeneficiario nel codice GIL.
- Client GovPay pronto: `NuovaVocePendenza` (pendenze-v2) espone `idDominio` per voce → una voce può
  essere accreditata a un altro ente.

## Vincoli noti

- pagoPA: massimo 5 transfer (voci) per pendenza.
- Ente secondario (Provincia) deve esistere in GovPay come dominio, con IBAN e contabilità propri —
  **da verificare sull'istanza GovPay prima di qualsiasi sviluppo**.
- Lavoro `tefa` oggi classifica ricevute TEFA esterne (Biz Events). Spontanei GIL multibeneficiario
  produrrebbero ricevute GovPay con transfer TEFA → report TEFA / ragioneria potrebbero doverle includere.

## Domande aperte

1. Come inserisce l'importo il cittadino?
   - solo TARI, TEFA aggiunta in automatico (percentuale configurabile, es. 5%);
   - totale da scomporre;
   - due importi separati copiati dall'avviso/prospetto.
2. Regola di arrotondamento della quota TEFA (centesimo a chi?).
3. Configurazione: multibeneficiario per tipologia (voci secondarie con dominio/IBAN/contabilità/percentuale)
   o caso specifico TARI+TEFA?
4. Solo frontoffice spontaneo o anche backoffice / massivo?
5. Impatto su rendicontazione GovPay (`RendicontazioneEngineService`), report TEFA, CSV ragioneria.
