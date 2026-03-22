FROM nginx:stable-alpine

# Bake in nginx config templates, error pages and entrypoint init scripts.
# Only mount at runtime: ./ssl:/etc/nginx/certs (TLS certs per-domain)
# and the iam_proxy_project named volume for SATOSA static files.

COPY conf.d/       /satosa_config_templates/
COPY html/         /usr/share/nginx/html/
COPY entrypoint.d/ /docker-entrypoint.d/

RUN chmod +x /docker-entrypoint.d/*.sh
