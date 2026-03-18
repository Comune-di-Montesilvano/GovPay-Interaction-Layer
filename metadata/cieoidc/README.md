# CIE OIDC Metadata Export

Questa cartella contiene gli artefatti locali per la federazione CIE OIDC,
esportati manualmente dal proxy IAM.

## File generati

- `entity-configuration.jwt`: Entity Statement JWS pubblicato da `/.well-known/openid-federation`
- `entity-configuration.json`: payload JSON decodificato del JWT
- `jwks-federation-public.json`: chiavi pubbliche federation (`jwks.keys`) da usare nel portale
- `jwks-rp.json`: JWKS JSON del RP (`/openid_relying_party/jwks.json`)
- `jwks-rp.jose`: JWKS JOSE del RP (`/openid_relying_party/jwks.jose`)
- `component-values.env`: riepilogo pronto da copiare nel portale (identifier, endpoint, scadenza)

## Export manuale

Windows (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File .\metadata\export-cieoidc.ps1
```

Per esportare usando direttamente gli endpoint pubblici (utile per verifica finale):

```powershell
powershell -ExecutionPolicy Bypass -File .\metadata\export-cieoidc.ps1 -FromPublic
```

Linux/macOS/WSL:

```bash
bash metadata/export-cieoidc.sh
```

Per usare endpoint pubblici:

```bash
bash metadata/export-cieoidc.sh --from-public
```

## Verifica endpoint federazione

Per controllare rapidamente che gli endpoint normativi rispondano con status e content-type corretti:

Windows (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\check-cie-oidc-federation-endpoints.ps1 -BaseUrl "http://127.0.0.1:9445/CieOidcRp" -Subject "https://pagopa-prx.comune.montesilvano.pe.it/CieOidcRp"
```

Linux/macOS/WSL:

```bash
bash scripts/check-cie-oidc-federation-endpoints.sh "http://127.0.0.1:9445/CieOidcRp" "https://pagopa-prx.comune.montesilvano.pe.it/CieOidcRp"
```

## Scadenza

L'Entity Configuration OIDC ha una scadenza (`exp`), non un certificato X509 come SPID.

Dopo ogni export verifica in `component-values.env`:

- `ENTITY_STATEMENT_EXP_UTC`
- `ENTITY_STATEMENT_EXP_DAYS_REMAINING`

Per inserimento sul portale preferisci i campi `PUBLIC_*` presenti in `component-values.env`.

Quando i giorni residui sono bassi, riesegui l'export e aggiorna manualmente i valori sul portale di federazione.

## Nota

Gli artefatti in questa cartella sono locali e non vengono versionati (vedi `.gitignore`).
