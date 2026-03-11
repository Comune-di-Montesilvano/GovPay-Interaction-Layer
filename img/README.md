# Cartella `img/`

Contiene le immagini statiche usate dall'interfaccia (favicon e loghi).

## File presenti

| File | Uso |
|---|---|
| `favicon_default.png` | Favicon di default mostrata nel browser quando non è configurato un logo personalizzato |
| `logo-pagopa-bianco.svg` | Logo pagoPA (variante bianca) mostrato nel footer delle pagine |

## Personalizzazione del logo dell'ente

Il logo dell'ente si configura nel `.env` tramite:

```env
APP_LOGO_SRC=/img/stemma_ente.png
APP_LOGO_TYPE=img
```

`APP_LOGO_SRC` accetta sia un **percorso locale** (relativo alla webroot pubblica) sia un **URL esterno**:

- **File locale**: metti il file direttamente in questa cartella (`img/`) e imposta `APP_LOGO_SRC=/img/nome-file.png` — è la modalità consigliata.
- **URL esterno**: imposta `APP_LOGO_SRC=https://ente.gov.it/logo.png` se preferisci referenziare un'immagine remota.

Il valore di `APP_LOGO_TYPE` deve essere `img` per file locali o il MIME type (es. `image/png`) per URL esterni.
