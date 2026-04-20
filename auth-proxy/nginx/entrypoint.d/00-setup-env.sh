#!/bin/sh
# Substitute environment variables in nginx config files
# This runs before 10-docker-entrypoint.sh from the official nginx image

set -e

NGINX_HOST="${NGINX_HOST:-auth-proxy-nginx}"
SSL_ENABLED="${SSL:-off}"
DNS_RESOLVER="${NGINX_DNS_RESOLVER:-127.0.0.11}"

echo "[nginx-setup] Initializing configuration from templates..."
# Pulizia conf.d originale e copia dei template
rm -f /etc/nginx/conf.d/default.conf
cp /satosa_config_templates/*.conf /etc/nginx/conf.d/

# Replace $NGINX_HOST in all config files
find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|\$NGINX_HOST|${NGINX_HOST}|g" {} \;
find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#DNS_RESOLVER_MARKER#|${DNS_RESOLVER}|g" {} \;

if [ "$SSL_ENABLED" = "on" ]; then
    echo "[nginx-setup] SSL is enabled. Injecting SSL config..."
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#SSL_MARKER#| ssl|g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#CERT_MARKER_1#|ssl_certificate /etc/nginx/certs/server.crt;|g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#CERT_MARKER_2#|ssl_certificate_key /etc/nginx/certs/server.key;|g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#PROTO_MARKER#|https|g" {} \;
else
    echo "[nginx-setup] SSL is disabled. Running in clear HTTP..."
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#SSL_MARKER#||g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#CERT_MARKER_1#||g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#CERT_MARKER_2#||g" {} \;
    find /etc/nginx/conf.d -name "*.conf" -type f -exec sed -i "s|#PROTO_MARKER#|http|g" {} \;
fi

# Genera le pagine di errore a partire dai template, sostituendo le variabili dell'organizzazione
# Le pagine vengono scritte in /tmp/errors/ (percorso scrivibile) e servite da nginx
mkdir -p /tmp/errors
APP_VERSION="${APP_VERSION:-unknown}"
ORG_NAME="${SATOSA_ORGANIZATION_NAME_IT:-Servizio di Autenticazione}"
ORG_DISPLAY_NAME="${SATOSA_ORGANIZATION_DISPLAY_NAME_IT:-${SATOSA_ORGANIZATION_NAME_IT:-Servizio di Autenticazione}}"
ORG_LOGO="${SATOSA_UI_LOGO_URL:-}"
ORG_URL="${SATOSA_ORGANIZATION_URL_IT:-/}"
SERVICE_URL="${SATOSA_UNKNOW_ERROR_REDIRECT_PAGE:-/}"
FRONTOFFICE_URL="${SATOSA_UNKNOW_ERROR_REDIRECT_PAGE:-/}"
LEGAL_URL="${SATOSA_UI_LEGAL_URL_IT:-#}"
PRIVACY_URL="${SATOSA_UI_PRIVACY_URL_IT:-#}"
ACCESSIBILITY_URL="${SATOSA_UI_ACCESSIBILITY_URL_IT:-#}"

for tmpl in /usr/share/nginx/html/errors/*.html; do
  fname="$(basename "$tmpl")"
  sed \
    -e "s|#ORG_NAME#|${ORG_NAME}|g" \
    -e "s|#ORG_DISPLAY_NAME#|${ORG_DISPLAY_NAME}|g" \
    -e "s|#ORG_LOGO#|${ORG_LOGO}|g" \
    -e "s|#ORG_URL#|${ORG_URL}|g" \
    -e "s|#SERVICE_URL#|${SERVICE_URL}|g" \
    -e "s|#FRONTOFFICE_URL#|${FRONTOFFICE_URL}|g" \
    -e "s|#LEGAL_URL#|${LEGAL_URL}|g" \
    -e "s|#PRIVACY_URL#|${PRIVACY_URL}|g" \
    -e "s|#ACCESSIBILITY_URL#|${ACCESSIBILITY_URL}|g" \
    -e "s|#APP_VERSION#|${APP_VERSION}|g" \
    "$tmpl" > "/tmp/errors/$fname"
done
echo "[nginx-setup] Error pages generated in /tmp/errors/ for org: ${ORG_NAME}"

echo "[nginx-setup] Substituted NGINX_HOST=${NGINX_HOST}, DNS_RESOLVER=${DNS_RESOLVER} and SSL macros in config files"
