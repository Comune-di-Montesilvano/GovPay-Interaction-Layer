######################################################################
# STAGE 1: Asset Builder (Bootstrap Italia + Font Awesome)
######################################################################
FROM node:lts-trixie-slim AS asset_builder

# Installa dipendenze necessarie per la fase di build (Git, Wget, Unzip)
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && \
    apt-get install -y --no-install-recommends git ca-certificates unzip wget

WORKDIR /app

# ----------------------------------------------------------------------
# VARIABILI GLOBALI DI CONFIGURAZIONE
# ----------------------------------------------------------------------

# Variabile per il tag di Bootstrap Italia
ARG BOOTSTRAP_TAG="v2.18.0"

# Variabili per Font Awesome
ARG FA_VERSION="7.2.0"
ENV FA_URL=https://github.com/FortAwesome/Font-Awesome/releases/download/${FA_VERSION}/fontawesome-free-${FA_VERSION}-web.zip
ENV FA_DIR="fontawesome-free-${FA_VERSION}-web"

# Variabili per Chart.js
ARG CHARTJS_VERSION="4.5.1"

# 1. Clona il repository, checkout, installa e compila Bootstrap Italia
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    git clone https://github.com/italia/bootstrap-italia.git . && \
    git checkout ${BOOTSTRAP_TAG} && \
    echo "Scarico e compilo Bootstap-italia versione ${BOOTSTRAP_TAG}..." && \
    npm install && \
    npm run build

# 2. Scarica Font Awesome in questa fase per usarlo come asset copiabile nella Fase 2
RUN mkdir -p /tmp/fa_download && \
    cd /tmp/fa_download && \
    echo "Scarico Font Awesome ${FA_VERSION}..." && \
    wget -q -O fa.zip ${FA_URL} && \
    unzip -q fa.zip && \
    mv ${FA_DIR} /app/fontawesome-dist && \
    rm -rf /tmp/fa_download

# 3. Scarica Chart.js (bundle UMD) per distribuirlo localmente tramite npm
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    mkdir -p /app/chartjs-dist && \
    echo "Scarico Chart.js ${CHARTJS_VERSION} via npm..." && \
    npm install chart.js@${CHARTJS_VERSION} --prefix /tmp/chartjs-download && \
    cp /tmp/chartjs-download/node_modules/chart.js/dist/chart.umd.js /app/chartjs-dist/chart.umd.js && \
    cp /tmp/chartjs-download/node_modules/chart.js/dist/chart.umd.min.js /app/chartjs-dist/chart.umd.min.js && \
    cp /tmp/chartjs-download/node_modules/chart.js/dist/chart.umd.min.js.map /app/chartjs-dist/chart.umd.min.js.map && \
    rm -rf /tmp/chartjs-download

# Il risultato della compilazione di Bootstrap Italia è in /app/dist
# Il risultato del download di Font Awesome è in /app/fontawesome-dist
# Il risultato del download di Chart.js è in /app/chartjs-dist

######################################################################
# STAGE 2: Composer vendor builder (solo dipendenze PHP)
######################################################################
FROM composer:2 AS vendor_builder
WORKDIR /app
COPY composer.json composer.lock* ./
COPY govpay-clients/ ./govpay-clients/
COPY pagopa-clients/ ./pagopa-clients/
RUN --mount=type=cache,target=/root/.composer,sharing=locked \
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

######################################################################
# STAGE 3: Runtime (Apache + PHP) harden
######################################################################
FROM php:8.5-apache-trixie AS runtime-base

# System deps + PHP extensions FIRST — stable layer, cache must not be busted
# by per-commit ARGs. All volatile ARG/ENV go AFTER this RUN.
ARG DEBIAN_FRONTEND=noninteractive
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev \
    ca-certificates curl unzip openssl \
    && docker-php-ext-install intl mbstring pdo_mysql zip \
    && apt-get purge -y --auto-remove libicu-dev libzip-dev libonig-dev \
    && a2enmod ssl rewrite headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Versione immagine iniettata a build-time dalla CI (es. "dev", "1.2.3")
ARG GIL_IMAGE_TAG=dev
ENV GIL_IMAGE_TAG=$GIL_IMAGE_TAG

# Commit SHA iniettato a build-time dalla CI
ARG GIT_COMMIT_SHA=unknown
ENV GIT_COMMIT_SHA=$GIT_COMMIT_SHA

# Copia vendor dal builder
COPY --from=vendor_builder /app/vendor /var/www/html/vendor
# Reintroduco composer solo per script di setup (dump-autoload scenario)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Imposta la directory di lavoro
WORKDIR /var/www/html

# ----------------------------------------------------------------------
# COPIA ASSET FRONT-END
# ----------------------------------------------------------------------

RUN mkdir -p public/assets/bootstrap-italia public/assets/fontawesome
COPY --chown=www-app:www-data --from=asset_builder /app/dist/ /var/www/html/public/assets/bootstrap-italia/

# 2. Copia Font Awesome (Asset scaricati dalla Fase 1)
ENV FA_DEST="/var/www/html/public/assets/fontawesome"
COPY --chown=www-app:www-data --from=asset_builder /app/fontawesome-dist/css ${FA_DEST}/css/
COPY --chown=www-app:www-data --from=asset_builder /app/fontawesome-dist/js ${FA_DEST}/js/
COPY --chown=www-app:www-data --from=asset_builder /app/fontawesome-dist/webfonts ${FA_DEST}/webfonts/
RUN chmod -R 755 public/assets

# (Documentativo) composer gestito nello stage vendor_builder
COPY --chown=www-app:www-data composer.json composer.lock* /var/www/html/

# ----------------------------------------------------------------------
# Configurazione Finale
# ----------------------------------------------------------------------

# Copia i certificati SSL
RUN mkdir -p /ssl
COPY --chown=www-app:www-data ssl/ /ssl/ 

#Copia i certificati di govpay se esistono
RUN mkdir -p /certificate
COPY --chown=www-app:www-data certificate/ /var/www/certificate/

# Copia lo script di setup nel container e rendilo eseguibile
COPY docker-setup.sh /usr/local/bin/docker-setup.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-setup.sh && chmod 755 /usr/local/bin/docker-setup.sh

# Copia e Abilita la configurazione Apache personalizzata
RUN rm /etc/apache2/sites-enabled/000-default.conf
COPY --chown=root:root apache/000-default-ssl.conf /etc/apache2/sites-available/000-default.conf
RUN a2ensite 000-default.conf

COPY --chown=www-app:www-data img /var/www/html/public/img
COPY --chown=www-app:www-data assets /var/www/html/public/assets
COPY --chown=www-app:www-data public.htaccess /var/www/html/public/.htaccess
COPY --chown=www-app:www-data app/ /var/www/html/app/
COPY --chown=www-app:www-data migrations/ /var/www/html/migrations/

# Copia la sorgente dei client generati (necessario se Composer ha creato symlink per path repositories)
COPY --chown=www-app:www-data govpay-clients/ /var/www/html/govpay-clients/
COPY --chown=www-app:www-data pagopa-clients/ /var/www/html/pagopa-clients/

# Hardening Apache: rimuove Indexes e aggiunge security headers
RUN sed -i 's/Options Indexes FollowSymLinks/Options FollowSymLinks/g' /etc/apache2/apache2.conf && \
    printf '\n<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options "nosniff"\n  Header always set X-Frame-Options "SAMEORIGIN"\n  Header always set Referrer-Policy "strict-origin-when-cross-origin"\n  Header always set X-XSS-Protection "1; mode=block"\n</IfModule>\n' > /etc/apache2/conf-enabled/security-headers.conf

# (RIMOSSO cambio utente: Apache necessita privilegi iniziali per bind 443, rimane root che poi esegue worker come www-data)
RUN useradd -r -d /var/www -g www-data www-app

# Permessi finali già assegnati a www-app

EXPOSE 443

CMD ["apache2-foreground"]

FROM runtime-base AS runtime-backoffice
COPY --chown=www-app:www-data backoffice/ /var/www/html/backoffice/
COPY --chown=www-app:www-data backoffice/bin/ /var/www/html/bin/
RUN ln -s /var/www/html/backoffice/src/bootstrap /var/www/html/bootstrap \
    && ln -s /var/www/html/backoffice/src/routes /var/www/html/routes \
    && ln -s /var/www/html/backoffice/templates /var/www/html/templates \
    && mkdir -p /var/www/html/backoffice/storage/logs
COPY --chown=www-app:www-data --from=asset_builder /app/chartjs-dist/ /var/www/html/public/assets/chartjs/
RUN cp -r /var/www/html/backoffice/src/public/. /var/www/html/public/ || true
# Imposta ownership corretta sulle directory che saranno montate come volumi Docker.
# Docker inizializza i named volumes copiando il contenuto del path dell'immagine,
# incluse le permission → il volume nasce con www-data:www-data, non root:root.
RUN mkdir -p /var/www/html/public/img /var/www/certificate /backups \
    && chown www-data:www-data /var/www/html/public/img /var/www/certificate /backups \
    && chmod 755 /var/www/html/public/img /var/www/certificate \
    && chmod 775 /backups

FROM runtime-base AS runtime-frontoffice
COPY --chown=www-app:www-data frontoffice/ /var/www/html/frontoffice/
RUN mkdir -p /var/www/html/frontoffice/storage/logs

# Script per generazione/rinnovo metadata SP SAML (usato da init/refresh-frontoffice-sp-metadata)
RUN mkdir -p /scripts
COPY metadata/ensure-sp-metadata.sh /scripts/ensure-sp-metadata.sh
RUN chmod +x /scripts/ensure-sp-metadata.sh
