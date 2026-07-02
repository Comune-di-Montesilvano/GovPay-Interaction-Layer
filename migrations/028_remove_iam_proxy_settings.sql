-- Migration 028: Rimuove tutte le chiavi di configurazione della sezione iam_proxy.
-- Poiché l'autenticazione interna via Satosa/SPID/CIE è stata rimossa a favore di OIDC esterno,
-- queste chiavi non sono più utilizzate dal codice.
DELETE FROM settings WHERE section = 'iam_proxy';
