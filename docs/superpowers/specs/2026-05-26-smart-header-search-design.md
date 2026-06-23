# Smart Header Search

**Date:** 2026-05-26  
**Status:** Approved

## Goal

Trasformare la searchbar nell'header del backoffice da placeholder inutilizzabile a strumento di navigazione rapida: riconosce il tipo di input, mostra un badge visivo del tipo rilevato, e al submit redirige alla maschera corretta con il campo giusto pre-popolato.

## Scope

File coinvolti:
- `backoffice/templates/partials/header.html.twig` — markup form + badge
- `assets/css/backoffice-shell.css` — stile badge
- `assets/js/app.js` — logica detection + comportamento form

Nessuna modifica backend, nessuna nuova route.

## Detection Rules

| Tipo | Pattern | Destinazione | Param |
|------|---------|--------------|-------|
| Codice Fiscale | `/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/i` (16 char) | `/pendenze/ricerca` | `idDebitore` |
| Partita IVA | `/^\d{11}$/` | `/pendenze/ricerca` | `idDebitore` |
| IUV | `/^\d{15,18}$/` | `/pendenze/ricerca` | `iuv` |
| ID Pendenza | `/^GIL-[\w\-]+$/i` o `/^\d{4}-\d+$/` | `/pendenze/ricerca` | `idPendenza` |
| ID Flusso | fallback se ≥ 5 char e nessun match sopra | `/pagamenti/ricerca-flussi` | `idFlusso` |
| Non riconosciuto | < 5 char o vuoto | — (submit bloccato) | — |

Tutti gli URL di destinazione includono `q=1` per attivare la query nella pagina target.

## UI

### Rimozioni
- `<kbd>Ctrl+K</kbd>` rimosso: shortcut non implementato, fuorviante.
- "nome contribuente" rimosso dal placeholder.

### Placeholder aggiornato
`Cerca CF, P.IVA, IUV o ID flusso`

### Badge tipo
Elemento `<span id="bo-search-badge">` posizionato dentro `.bo-topbar-search`, visibile solo quando c'è testo nell'input.

Badge states:
| Tipo | Label | Colore CSS var / Hex |
|------|-------|----------------|
| Codice Fiscale | `CF` | `--bo-success` (verde) |
| Partita IVA | `P.IVA` | `--bo-success` (verde) |
| IUV | `IUV` | `--bo-info` (blu) |
| ID Pendenza | `Pendenza` | Purple (#f3e8ff, #5b21b6) |
| ID Flusso | `Flusso` | `--bo-warning` (arancio) |
| Non riconosciuto / breve | `?` | `--bo-border-strong` (grigio) |

Il badge non blocca visivamente l'input (posizionato a destra, max-width limitato).

### Submit behavior
- Tipo riconosciuto (CF/PI/IUV/idFlusso): `event.preventDefault()`, costruisce URL, `window.location.href = url`.
- Non riconosciuto (badge `?`): `event.preventDefault()`, nessuna navigazione (operatore deve completare l'input).

## Implementation Notes

- La logica detection va in `app.js` dentro un IIFE che si inizializza su `DOMContentLoaded`.
- Nessuna dipendenza esterna aggiuntiva (zero librerie nuove).
- Il badge usa classi CSS nuove `.bo-search-badge` + modificatori `.is-cf`, `.is-iuv`, `.is-flusso`, `.is-unknown` aggiunte a `backoffice-shell.css`.
- Regex CF case-insensitive: accetta maiuscole e minuscole (normalizza a uppercase prima del submit).
- Regex valutate in ordine: CF prima di PI (un CF non può essere 11 cifre, ma l'ordine evita ambiguità future).
