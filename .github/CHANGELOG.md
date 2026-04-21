# Changelog

TBD - changelog sintetico dei cambiamenti più importanti.

## v1.0.0beta — 2026-04-21

- refactor: rinomina variabili `IAM_PROXY_*` → `AUTH_PROXY_*` per coerenza naming
- fix: watchdog auth-proxy ignorava `AUTH_PROXY_WATCHDOG_INTERVAL` (leggeva `IAM_WATCHDOG_INTERVAL`, sempre 300s)
- feat: reload immediato auth-proxy al salvataggio impostazioni Login Proxy (server HTTP interno porta 9191)
- refactor: endpoint CIE OIDC derivati automaticamente da `cie_env` + `SATOSA_BASE` (rimossi campi manuali)
- refactor: rimossa directory `master/` (servizio Python deprecato)
- docs: README e metadata/README allineati con architettura corrente (config DB-based, no `.auth-proxy.env`)

## v0.9.4 — 2026-03-23

- feat: backup e importazione configurazione dal pannello di configurazione
  - Nuova sezione "Backup" (visibile solo ai superadmin) in `/configurazione?tab=backup`
  - Export selettivo in JSON delle sezioni: override locali tipologie, tipologie esterne, template pendenze, servizi App IO, utenti
  - Import con strategia REPLACE per sezione (transazione atomica); UPSERT by email per gli utenti
  - Le API key dei servizi IO vengono esportate in chiaro e ri-cifrate (AES-256) all'importazione
  - Le assegnazioni template-utente vengono esportate per email e ripristinate per email

## 2025-10-16

- README updated with project status and license note
