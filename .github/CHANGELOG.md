# Changelog

TBD - changelog sintetico dei cambiamenti più importanti.

## Unreleased

- Miglioramenti: header Twig e fix menu hamburger
- Add: EUPL-1.2 license and SPDX headers
- Add: top-level TODO.md and docs/TODO.md
- Add: logger e miglioramenti di sicurezza per utenti

## v0.9.4 — 2026-03-23

- feat: backup e importazione configurazione dal pannello di configurazione
  - Nuova sezione "Backup" (visibile solo ai superadmin) in `/configurazione?tab=backup`
  - Export selettivo in JSON delle sezioni: override locali tipologie, tipologie esterne, template pendenze, servizi App IO, utenti
  - Import con strategia REPLACE per sezione (transazione atomica); UPSERT by email per gli utenti
  - Le API key dei servizi IO vengono esportate in chiaro e ri-cifrate (AES-256) all'importazione
  - Le assegnazioni template-utente vengono esportate per email e ripristinate per email

## 2025-10-16

- README updated with project status and license note
