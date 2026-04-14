# Build context: root della repository
# Immagine wrapper: bake-in iam-proxy-italia-project + templates + file custom.
# Al container startup, startup.sh applica envsubst, inietta JWK e avvia SATOSA.
# Elimina la necessità di sync-iam-proxy (container one-shot) e del volume iam_proxy_project.

FROM ghcr.io/italia/iam-proxy-italia:latest

USER root

# Installa gettext (envsubst), curl, git (Alpine)
RUN apk add --no-cache gettext curl git

# Bake-in il progetto SATOSA dal repo upstream (versione fissa a build time → riproducibile)
ARG IAM_PROXY_REF=master
ARG GIL_IMAGE_TAG=dev
ENV APP_VERSION=$GIL_IMAGE_TAG

RUN git clone --depth 1 --branch "${IAM_PROXY_REF}" \
      https://github.com/italia/iam-proxy-italia.git /tmp/upstream \
    && cp -r /tmp/upstream/iam-proxy-italia-project/. /satosa_proxy/ \
    && rm -rf /tmp/upstream

# Templates (envsubst a startup, dipendono da variabili d'ambiente)
COPY auth-proxy/disco.static.html.template               /builder/templates/disco.static.html.template
COPY auth-proxy/wallets-it.json.template                 /builder/templates/wallets-it.json.template
COPY auth-proxy/wallets-en.json.template                 /builder/templates/wallets-en.json.template
COPY auth-proxy/wallets-config.json.template             /builder/templates/wallets-config.json.template
COPY auth-proxy/wallets-spid-demo-override.json.template /builder/templates/wallets-spid-demo-override.json.template
COPY auth-proxy/spid_base_override.html.template         /builder/templates/spid_base_override.html.template
COPY auth-proxy/cieoidc_backend.override.yaml.template   /builder/templates/cieoidc_backend.override.yaml.template

# File custom (non dipendono da env vars → installati a build time)
COPY auth-proxy/cieoidc-endpoints/ /builder/cieoidc-endpoints/
COPY auth-proxy/cieoidc-models/    /builder/cieoidc-models/
COPY auth-proxy/static-overrides/  /builder/static-overrides/
COPY auth-proxy/patch_saml2.py     /builder/patch_saml2.py
COPY auth-proxy/startup.sh         /builder/startup.sh

RUN chmod +x /builder/startup.sh \
    # Installa file custom nel progetto (non dipendono da env vars)
    && mkdir -p /satosa_proxy/backends/cieoidc/endpoints \
                /satosa_proxy/backends/cieoidc/models \
    && cp -r /builder/cieoidc-endpoints/. /satosa_proxy/backends/cieoidc/endpoints/ \
    && cp -r /builder/cieoidc-models/.    /satosa_proxy/backends/cieoidc/models/ \
    && cp -r /builder/static-overrides/.  /satosa_proxy/static/ \
    && cp    /builder/patch_saml2.py      /satosa_proxy/patch_saml2.py \
    # Directory necessarie a SATOSA (logs, metadata)
    && mkdir -p /satosa_proxy/logs /satosa_proxy/metadata/idp /satosa_proxy/metadata/sp \
    && chmod -R 777 /satosa_proxy/logs /satosa_proxy/metadata
