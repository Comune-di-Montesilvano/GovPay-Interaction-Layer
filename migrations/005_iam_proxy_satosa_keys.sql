-- Migration 005: aggiunge le chiavi SATOSA mancanti nella sezione iam_proxy del DB.
-- Tutte le INSERT usano INSERT IGNORE per essere idempotenti (non sovrascrivono valori già impostati).
-- I campi segreto (salt, chiavi di cifratura) sono marcati encrypted=1 e vanno configurati
-- dall'interfaccia backoffice (Impostazioni → Login Proxy → Chiavi SATOSA).

INSERT IGNORE INTO settings (section, key_name, value, encrypted) VALUES
  -- Chiavi crittografiche SATOSA (obbligatorie in produzione, >= 32 char random)
  ('iam_proxy', 'satosa_salt',                      NULL, 1),
  ('iam_proxy', 'satosa_state_encryption_key',      NULL, 1),
  ('iam_proxy', 'satosa_encryption_key',            NULL, 1),
  ('iam_proxy', 'satosa_user_id_hash_salt',         NULL, 1),

  -- URL SATOSA aggiuntive
  ('iam_proxy', 'satosa_disco_srv',                  NULL, 0),
  ('iam_proxy', 'satosa_cancel_redirect_url',        NULL, 0),
  ('iam_proxy', 'satosa_unknow_error_redirect_page', NULL, 0),

  -- Toggle recupero metadata IdP
  ('iam_proxy', 'satosa_get_spid_idp_metadata',      'true',  0),
  ('iam_proxy', 'satosa_get_cie_idp_metadata',       'true',  0),
  ('iam_proxy', 'satosa_get_ficep_idp_metadata',     'false', 0),
  ('iam_proxy', 'satosa_get_idem_mdq_key',           'true',  0),

  -- Toggle debug/demo/validator (solo ambienti di test)
  ('iam_proxy', 'satosa_use_demo_spid_idp',          'false', 0),
  ('iam_proxy', 'satosa_use_spid_validator',         'false', 0),
  ('iam_proxy', 'satosa_spid_validator_metadata_url', 'https://validator.spid.gov.it/metadata.xml', 0),
  ('iam_proxy', 'satosa_disable_cieoidc_backend',    'false', 0),
  ('iam_proxy', 'satosa_disable_pyeudiw_backend',    'true',  0),

  -- Feature flags (enable_idem e enable_eidas erano solo in docker-compose)
  ('iam_proxy', 'enable_idem',                       'false', 0),
  ('iam_proxy', 'enable_eidas',                      'false', 0),

  -- Tipo auth proxy frontoffice (default: iam-proxy-saml2)
  ('iam_proxy', 'frontoffice_auth_proxy_type',       'iam-proxy-saml2', 0),

  -- UI SATOSA (aggiuntive rispetto a quelle già in saveLoginProxy)
  ('iam_proxy', 'satosa_org_display_name_en',        NULL, 0),
  ('iam_proxy', 'satosa_org_name_en',                NULL, 0),
  ('iam_proxy', 'satosa_org_url_it',                 NULL, 0),
  ('iam_proxy', 'satosa_org_url_en',                 NULL, 0),
  ('iam_proxy', 'satosa_contact_given_name',         NULL, 0),
  ('iam_proxy', 'satosa_contact_municipality',       NULL, 0),
  ('iam_proxy', 'satosa_ui_display_name_it',         NULL, 0),
  ('iam_proxy', 'satosa_ui_display_name_en',         NULL, 0),
  ('iam_proxy', 'satosa_ui_description_it',          NULL, 0),
  ('iam_proxy', 'satosa_ui_description_en',          NULL, 0),
  ('iam_proxy', 'satosa_ui_information_url_it',      NULL, 0),
  ('iam_proxy', 'satosa_ui_information_url_en',      NULL, 0),
  ('iam_proxy', 'satosa_ui_privacy_url_it',          NULL, 0),
  ('iam_proxy', 'satosa_ui_privacy_url_en',          NULL, 0),
  ('iam_proxy', 'satosa_ui_legal_url_it',            NULL, 0),
  ('iam_proxy', 'satosa_ui_legal_url_en',            NULL, 0),
  ('iam_proxy', 'satosa_ui_accessibility_url_it',    NULL, 0),
  ('iam_proxy', 'satosa_ui_accessibility_url_en',    NULL, 0),
  ('iam_proxy', 'satosa_ui_logo_url',                NULL, 0),
  ('iam_proxy', 'satosa_ui_logo_width',              '200', 0),
  ('iam_proxy', 'satosa_ui_logo_height',             '60',  0),

  -- CIE OIDC (campi aggiuntivi rispetto a quelli già in saveLoginProxy)
  ('iam_proxy', 'cie_oidc_trust_anchor_url',         'https://oidc.registry.servizicie.interno.gov.it', 0),
  ('iam_proxy', 'cie_oidc_authority_hint_url',       'https://oidc.registry.servizicie.interno.gov.it', 0),
  ('iam_proxy', 'cie_oidc_organization_name',        NULL, 0),
  ('iam_proxy', 'cie_oidc_signed_jwks_uri',          NULL, 0),
  ('iam_proxy', 'cie_oidc_federation_resolve_endpoint',           NULL, 0),
  ('iam_proxy', 'cie_oidc_federation_fetch_endpoint',             NULL, 0),
  ('iam_proxy', 'cie_oidc_federation_trust_mark_status_endpoint', NULL, 0),
  ('iam_proxy', 'cie_oidc_federation_list_endpoint', NULL, 0),
  ('iam_proxy', 'cie_oidc_homepage_uri',             NULL, 0),
  ('iam_proxy', 'cie_oidc_policy_uri',               NULL, 0),
  ('iam_proxy', 'cie_oidc_logo_uri',                 NULL, 0),
  ('iam_proxy', 'cie_oidc_contact_email',            NULL, 0);

-- Default URL redirect errore SATOSA: usa FRONTOFFICE public_base_url + /accesso-negato.
-- Se frontoffice non e configurato, fallback di sicurezza su https://127.0.0.1:8444/accesso-negato.
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
