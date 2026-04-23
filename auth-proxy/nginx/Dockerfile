FROM nginx:stable-alpine

ARG GIL_IMAGE_TAG=development
ARG GIL_VERSION_TYPE=development
ARG GIL_VERSION_LABEL=
ARG GIL_REF_URL=
ARG GIT_COMMIT_SHA=unknown

ENV APP_VERSION=$GIL_IMAGE_TAG
ENV APP_VERSION_TYPE=$GIL_VERSION_TYPE
ENV APP_VERSION_LABEL=$GIL_VERSION_LABEL
ENV APP_REF_URL=$GIL_REF_URL
ENV APP_COMMIT_SHA=$GIT_COMMIT_SHA

# Bake in nginx config templates, error pages and entrypoint init scripts.
# Only mount at runtime: ./ssl:/etc/nginx/certs (TLS certs per-domain)
# and the iam_proxy_project named volume for SATOSA static files.

COPY conf.d/       /satosa_config_templates/
COPY html/         /usr/share/nginx/html/
COPY entrypoint.d/ /docker-entrypoint.d/

RUN chmod +x /docker-entrypoint.d/*.sh
