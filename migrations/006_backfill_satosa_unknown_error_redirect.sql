-- Migration 006: backfill SATOSA unknown error redirect URL su installazioni gia migrate.
-- Imposta satosa_unknow_error_redirect_page quando NULL/vuota usando
-- FRONTOFFICE public_base_url + /accesso-negato, con fallback di sicurezza.

UPDATE settings s
JOIN (
  SELECT COALESCE(
    NULLIF(TRIM(MAX(CASE WHEN section = 'frontoffice' AND key_name = 'public_base_url' THEN value END)), ''),
    'https://127.0.0.1:8444'
  ) AS frontoffice_base
  FROM settings
) src
SET s.value = CONCAT(TRIM(TRAILING '/' FROM src.frontoffice_base), '/accesso-negato')
WHERE s.section = 'iam_proxy'
  AND s.key_name = 'satosa_unknow_error_redirect_page'
  AND (s.value IS NULL OR TRIM(s.value) = '');
