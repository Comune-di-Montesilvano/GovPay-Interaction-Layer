# Build context: root of the repository (docker build -f iam-proxy/sync/Dockerfile .)
# Bakes in all iam-proxy templates, custom Python files, and sync scripts.
# Only mounts needed at runtime: ./metadata/cieoidc-keys (per-commune federation keys)
# and the iam_proxy_project named volume (output written by the sync).

FROM alpine:latest

RUN apk add --no-cache bash git curl unzip openssl gettext python3

# Sync scripts
COPY scripts/sync-iam-proxy-italia.sh              /scripts/sync-iam-proxy-italia.sh
COPY scripts/check-cie-oidc-federation-endpoints.sh /scripts/check-cie-oidc-federation-endpoints.sh

# IAM-proxy templates and overrides
COPY iam-proxy/disco.static.html.template              /iam-proxy/disco.static.html.template
COPY iam-proxy/wallets-it.json.template                /iam-proxy/wallets-it.json.template
COPY iam-proxy/wallets-en.json.template                /iam-proxy/wallets-en.json.template
COPY iam-proxy/wallets-config.json.template            /iam-proxy/wallets-config.json.template
COPY iam-proxy/wallets-spid-demo-override.json.template /iam-proxy/wallets-spid-demo-override.json.template
COPY iam-proxy/spid_base_override.html.template        /iam-proxy/spid_base_override.html.template
COPY iam-proxy/cieoidc_backend.override.yaml.template  /iam-proxy/cieoidc_backend.override.yaml.template

# Custom CIE OIDC Python files (endpoints, models, static-overrides)
COPY iam-proxy/cieoidc-endpoints/ /iam-proxy/cieoidc-endpoints/
COPY iam-proxy/cieoidc-models/    /iam-proxy/cieoidc-models/
COPY iam-proxy/static-overrides/  /iam-proxy/static-overrides/

# Patch for pysaml2 (applied by iam-proxy-italia at startup from /satosa_proxy/patch_saml2.py)
COPY iam-proxy/patch_saml2.py /iam-proxy/patch_saml2.py

RUN chmod +x /scripts/*.sh

WORKDIR /scripts

# After sync, copy patch_saml2.py into the project directory (needed by iam-proxy-italia)
CMD ["bash", "-c", "bash /scripts/sync-iam-proxy-italia.sh && cp /iam-proxy/patch_saml2.py /iam-proxy/iam-proxy-italia-project/patch_saml2.py 2>/dev/null || true"]
