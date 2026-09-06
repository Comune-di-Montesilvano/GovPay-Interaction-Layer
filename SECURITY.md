# Security Policy

## Versioni supportate

Solo l'ultima release taggata (`vX.Y.Z`) e il branch `main` ricevono patch di sicurezza.
Versioni precedenti non sono mantenute.

## Segnalare una vulnerabilità

**Non aprire una issue pubblica** per una vulnerabilità di sicurezza — potrebbe esporre il
problema prima che sia disponibile una correzione.

Segnala privatamente a:

- **Email**: supporto@comune.montesilvano.pe.it
- Oggetto: `[SECURITY] <breve descrizione>`

Includi, dove possibile:

- Componente interessato (backoffice, frontoffice, immagine `db`, integrazione esterna)
- Passi per riprodurre il problema
- Impatto potenziale
- Versione/commit interessato

## Cosa aspettarsi

- Conferma di ricezione entro 5 giorni lavorativi
- Valutazione e, se confermata, una stima dei tempi di correzione
- Coordinamento sulla disclosure pubblica dopo il rilascio della patch (credito al segnalante
  su richiesta)

## Ambito

Rientrano nell'ambito di questa policy vulnerabilità nel codice di questo repository
(backoffice, frontoffice, immagine `db`, script di build/deploy). Non rientrano:
vulnerabilità nelle dipendenze upstream già note pubblicamente (GovPay, pagoPA, librerie
di terze parti) — per queste, segnalare direttamente al progetto interessato — ne'
problemi di configurazione specifici di un singolo deployment (es. credenziali di
produzione, reverse proxy esterno).
