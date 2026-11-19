# Frontoffice — accesso persona giuridica (SPID uso professionale) via pa-sso-proxy

## Intento

pa-sso-proxy (già operativo) supporta il login SPID persona giuridica (Tipo 3/4, `Purpose=PG`) quando il
client OIDC chiede lo scope `legal_entity`. GIL deve permettere a un'impresa di accedere al frontoffice e
operare **a nome dell'impresa**: pendenze cercate per P.IVA, spontanei intestati alla P.IVA con ragione sociale.

## Obiettivi / non-obiettivi

- Obiettivi: opzione di abilitazione in Backoffice → Impostazioni → Frontoffice (blocco OIDC esterno);
  scelta "cittadino / impresa" al login; sessione impresa che fa funzionare a nome della P.IVA tutto il
  frontoffice esistente.
- Non-obiettivi: deleghe, scelta tra più imprese, persona giuridica via CIE/eIDAS (il proxy li rifiuta),
  modifiche al backoffice oltre all'opzione.
- Anti-obiettivo: un login impresa che ricade in silenzio sulla persona fisica e fa creare pendenze
  intestate al CF dell'operatore.

## Contratto pa-sso-proxy

- Scope `legal_entity` → claim `company_name`, `registered_office`, `iva_code` (possono arrivare come
  lista a un elemento). Login solo SPID.
- `fiscal_number` = CF della **persona fisica** che opera, non la P.IVA.
- Lato proxy (fuori da GIL): `legal_entity` negli `allowed_scopes` del client GIL + `legal_entity_enabled`.

## Approcci

1. **Sostituire l'identità in sessione** (scelto): con `iva_code` presente, `fiscal_number` = P.IVA,
   `subject_type = G`, `company_name`; CF operatore in campo separato. Il codice esistente
   (ricerca pendenze, precompilato `tipo G` su identificativo di 11 cifre, carrello) funziona senza modifiche.
2. Nuova struttura "soggetto" separata dall'utente: modello più pulito ma tocca ogni consumatore di
   `fiscal_number`, più rischio di dimenticarne uno. Scartato.

Login: due ingressi ("Accedi come cittadino" / "Accedi come impresa"). Un login unico che chiede sempre
`legal_entity` costringerebbe tutti a SPID uso professionale (niente CIE/eIDAS per i cittadini); la scelta
non è delegabile alla discovery del proxy perché lo scope lo decide il client.

## Design

- **Setting**: `frontoffice.external_oidc_legal_entity_enabled` (checkbox nel blocco OIDC esterno di
  `tab-frontoffice.html.twig`, salvato in `ImpostazioniController`), esposto al frontoffice come
  `EXTERNAL_OIDC_LEGAL_ENTITY_ENABLED`. Testo d'aiuto: richiede scope `legal_entity` abilitato per il client
  in pa-sso-proxy.
- **Login**: `/login?subject=pg` aggiunge `legal_entity` agli scope e salva in sessione il tipo richiesto.
  `subject=pg` con opzione disattivata → login persona fisica (come fa il proxy).
- **Header**: con opzione attiva il pulsante "Accedi" offre le due scelte; disattiva = come oggi.
- **Callback**: se era richiesto `pg`:
  - `iva_code` normalizzato (via prefisso `VATIT-`/`IT`, spazi) deve essere 11 cifre, altrimenti pagina
    d'errore dedicata, nessuna sessione creata (anti-obiettivo);
  - sessione: `fiscal_number` = P.IVA, `subject_type = 'G'`, `company_name`, `registered_office`,
    `operator_fiscal_number` = CF normalizzato, nome/cognome = operatore.
  - Login persona fisica: invariato (`subject_type = 'F'`).
- **Precompilato soggetto pagatore**: per `tipo G` anagrafica = `company_name` (oggi usa il cognome).
- **Profilo**: mostra impresa (ragione sociale, P.IVA) e operatore (nome, CF).
- **Log**: il callback smette di loggare il corpo UserInfo intero (oggi CF/PII in chiaro, con PG anche
  P.IVA/ragione sociale): solo status + elenco nomi claim.
- **Logica pura estratta** in una classe `app/` (mapping claim → utente sessione, normalizzazione P.IVA),
  caricata dal frontoffice via `require`, con test PHPUnit.
- **i18n**: nuove stringhe nei 5 file `frontoffice/locales/*.json`.

## Lasciato al builder

- Forma esatta del selettore nell'header (dropdown o due pulsanti), coerente col design system.
- Altri link di login (`login_path` in checkout, `accesso-negato`): restano persona fisica; aggiungere
  l'opzione impresa dove semplice.
- Nome della classe estratta e chiave di sessione per il tipo richiesto.
