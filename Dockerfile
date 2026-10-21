######################################################################
# STAGE 1: Asset Builder (librerie frontend da npm)
######################################################################
FROM node:lts-trixie-slim AS asset_builder

WORKDIR /app

# Versioni esatte in package.json/package-lock.json (root repo): le aggiorna dependabot
# (ecosistema npm). Niente ARG di versione qui: dependabot non li vedrebbe.
COPY package.json package-lock.json ./

# --ignore-scripts: nessun postinstall di terze parti in build (supply-chain).
RUN --mount=type=cache,target=/root/.npm,sharing=locked \
    npm ci --ignore-scripts --no-audit --no-fund && \
    mkdir -p /app/dist/fontawesome /app/dist/chartjs /app/dist/litepicker /app/dist/tom-select && \
    cp -r node_modules/@fortawesome/fontawesome-free/css \
          node_modules/@fortawesome/fontawesome-free/js \
          node_modules/@fortawesome/fontawesome-free/webfonts /app/dist/fontawesome/ && \
    cp node_modules/chart.js/dist/chart.umd.js \
       node_modules/chart.js/dist/chart.umd.min.js \
       node_modules/chart.js/dist/chart.umd.min.js.map /app/dist/chartjs/ && \
    cp node_modules/litepicker/dist/litepicker.js \
       node_modules/litepicker/dist/css/litepicker.css /app/dist/litepicker/ && \
    cp node_modules/tom-select/dist/js/tom-select.complete.min.js \
       node_modules/tom-select/dist/css/tom-select.bootstrap5.min.css /app/dist/tom-select/

# Risultato in /app/dist/{fontawesome,chartjs,litepicker,tom-select} -> /assets/* runtime


######################################################################
# STAGE 2: Composer vendor builder (solo dipendenze PHP)
######################################################################
FROM composer:2 AS vendor_builder
WORKDIR /app
COPY --link composer.json composer.lock* ./
COPY --link govpay-clients/ ./govpay-clients/
COPY --link pagopa-clients/ ./pagopa-clients/
RUN --mount=type=cache,target=/root/.composer,sharing=locked \
    composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction


######################################################################
# STAGE 3: Runtime (Apache + PHP) harden
######################################################################
FROM php:8.5-apache-trixie AS runtime-base

# System deps + PHP extensions FIRST — stable layer, cache must not be busted
# by per-commit ARGs. All volatile ARG/ENV go AFTER this RUN.
ARG DEBIAN_FRONTEND=noninteractive
# Chiave di refresh settimanale (anno-settimana ISO, passata da docker-publish.yml): senza,
# la build cache congela questo layer e `apt-get upgrade` non applica più le patch Debian
# (CVE fixabili bloccanti sul gate Trivy). Cambia una volta a settimana, non per commit.
ARG APT_REFRESH=local
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt,sharing=locked \
    echo "apt refresh: ${APT_REFRESH}" && apt-get update && apt-get upgrade -y && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev \
    ca-certificates curl unzip openssl \
    && docker-php-ext-install intl mbstring pdo_mysql zip \
    && apt-get purge -y libicu-dev libzip-dev libonig-dev \
    && a2enmod ssl rewrite headers \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf \
    && printf 'upload_max_filesize=256M\npost_max_size=256M\nmemory_limit=256M\nmax_execution_time=0\n' \
       > /usr/local/etc/php/conf.d/gil-uploads.ini \
    && printf 'Timeout 3600\n' > /etc/apache2/conf-enabled/gil-timeout.conf

# Imposta la directory di lavoro subito
WORKDIR /var/www/html

# Copia vendor dal builder (strato pesante, teniamolo in alto in modo che non venga invalidato)
COPY --link --from=vendor_builder /app/vendor /var/www/html/vendor
# Reintroduco composer solo per script di setup (dump-autoload scenario)
COPY --link --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ----------------------------------------------------------------------
# COPIA ASSET FRONT-END
# ----------------------------------------------------------------------

RUN mkdir -p public/assets/fontawesome

# Copia librerie frontend (Fase 1, da npm)
COPY --chown=www-app:www-data --from=asset_builder /app/dist/fontawesome/ /var/www/html/public/assets/fontawesome/
COPY --chown=www-app:www-data --from=asset_builder /app/dist/litepicker/ /var/www/html/public/assets/litepicker/
COPY --chown=www-app:www-data --from=asset_builder /app/dist/tom-select/ /var/www/html/public/assets/tom-select/
RUN chmod -R 755 public/assets

# (Documentativo) composer gestito nello stage vendor_builder
COPY --chown=www-app:www-data composer.json composer.lock* /var/www/html/

# ----------------------------------------------------------------------
# Configurazione Finale del Server
# ----------------------------------------------------------------------

# Copia i certificati SSL
RUN mkdir -p /ssl
COPY --chown=www-app:www-data ssl/ /ssl/

# Copia i certificati di govpay se esistono
RUN mkdir -p /certificate
COPY --chown=www-app:www-data certificate/ /var/www/certificate/

# Copia lo script di setup nel container e rendilo eseguibile
COPY --link docker-setup.sh /usr/local/bin/docker-setup.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-setup.sh && chmod 755 /usr/local/bin/docker-setup.sh

# Copia e Abilita la configurazione Apache personalizzata
RUN rm /etc/apache2/sites-enabled/000-default.conf
COPY --chown=root:root --link apache/000-default-ssl.conf /etc/apache2/sites-available/000-default.conf
RUN a2ensite 000-default.conf

# Hardening Apache: rimuove Indexes e aggiunge security headers
RUN sed -i 's/Options Indexes FollowSymLinks/Options FollowSymLinks/g' /etc/apache2/apache2.conf && \
    printf '\n<IfModule mod_headers.c>\n  Header always set X-Content-Type-Options "nosniff"\n  Header always set X-Frame-Options "SAMEORIGIN"\n  Header always set Referrer-Policy "strict-origin-when-cross-origin"\n  Header always set X-XSS-Protection "1; mode=block"\n</IfModule>\n' > /etc/apache2/conf-enabled/security-headers.conf

RUN useradd -r -d /var/www -g www-data www-app

# ----------------------------------------------------------------------
# Codice Sorgente Applicativo
# ----------------------------------------------------------------------
# Copiamo il codice applicativo solo ORA. Verrà invalidato solo se modifichi i sorgenti,
# ma Apache, dipendenze, utenze e permessi resteranno cachati.

COPY --chown=www-app:www-data img /var/www/html/public/img
COPY --chown=www-app:www-data assets /var/www/html/public/assets
COPY --chown=www-app:www-data public.htaccess /var/www/html/public/.htaccess
COPY --chown=www-app:www-data app/ /var/www/html/app/
COPY --chown=www-app:www-data migrations/ /var/www/html/migrations/

# Copia la sorgente dei client generati
COPY --chown=www-app:www-data govpay-clients/ /var/www/html/govpay-clients/
COPY --chown=www-app:www-data pagopa-clients/ /var/www/html/pagopa-clients/

# ----------------------------------------------------------------------
# Variabili Volatili e File Version (IL COLLO DI BOTTIGLIA RISOLTO)
# ----------------------------------------------------------------------
# Tutto ciò che c'è da qui in giù verrà ricostruito a OGNI COMMIT. 
# Mantienilo al minimo indispensabile.

# Versione immagine iniettata a build-time dalla CI
ARG GIL_IMAGE_TAG=development
ENV GIL_IMAGE_TAG=$GIL_IMAGE_TAG

ARG GIL_VERSION_TYPE=development
ENV GIL_VERSION_TYPE=$GIL_VERSION_TYPE

ARG GIL_VERSION_LABEL=development
ENV GIL_VERSION_LABEL=$GIL_VERSION_LABEL

ARG GIL_REF_URL=
ENV GIL_REF_URL=$GIL_REF_URL

# Commit SHA iniettato a build-time dalla CI
ARG GIT_COMMIT_SHA=unknown
ENV GIT_COMMIT_SHA=$GIT_COMMIT_SHA

RUN printf '%s\n' "$GIL_IMAGE_TAG" > /var/www/html/VERSION \
    && php -r '$data = ["version" => getenv("GIL_IMAGE_TAG") ?: "development", "version_type" => getenv("GIL_VERSION_TYPE") ?: "development", "version_label" => getenv("GIL_VERSION_LABEL") ?: (getenv("GIL_IMAGE_TAG") ?: "development"), "commit" => getenv("GIT_COMMIT_SHA") ?: "unknown", "ref_url" => getenv("GIL_REF_URL") ?: ""]; file_put_contents("/var/www/html/VERSION_INFO.json", json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));'

EXPOSE 443

CMD ["apache2-foreground"]


######################################################################
# STAGE 4: Runtime Backoffice (Eredita tutto il caching del runtime-base)
######################################################################
FROM runtime-base AS runtime-backoffice
COPY --chown=www-app:www-data backoffice/ /var/www/html/backoffice/
COPY --chown=www-app:www-data backoffice/bin/ /var/www/html/bin/
COPY --chown=www-app:www-data scripts/ /var/www/html/scripts/
RUN ln -s /var/www/html/backoffice/src/bootstrap /var/www/html/bootstrap \
    && ln -s /var/www/html/backoffice/src/routes /var/www/html/routes \
    && ln -s /var/www/html/backoffice/templates /var/www/html/templates \
    && mkdir -p /var/www/html/backoffice/storage/logs /var/www/html/backoffice/storage/tmp \
    && touch /var/www/html/backoffice/storage/logs/app.log \
    && chown -R www-data:www-data /var/www/html/backoffice/storage \
    && chmod 2775 /var/www/html/backoffice/storage/logs /var/www/html/backoffice/storage/tmp \
    && chmod 664 /var/www/html/backoffice/storage/logs/app.log
# storage/logs e storage/tmp: setgid (2775) + file pre-creati e chownati www-data.
# I demoni cron sono lanciabili anche via `docker exec` (di norma come root, nessun
# USER nel container) mentre Apache gira come www-data — senza setgid un file creato
# da root finisce root:root e www-data non riesce più a fopen/unlink (Permission
# denied / Operation not permitted, vedi GOVPAY-GIL-D/K e duplicati). Col bit setgid
# ogni nuovo file eredita il gruppo www-data della directory indipendentemente da chi
# lo crea, restando scrivibile da entrambi.
COPY --chown=www-app:www-data --from=asset_builder /app/dist/chartjs/ /var/www/html/public/assets/chartjs/
RUN cp -r /var/www/html/backoffice/src/public/. /var/www/html/public/ || true

# Imposta ownership corretta sulle directory che saranno montate come volumi Docker.
RUN mkdir -p /var/www/html/public/img /var/www/certificate /backups \
    && chown www-data:www-data /var/www/html/public/img /var/www/certificate /backups \
    && chmod 755 /var/www/html/public/img /var/www/certificate \
    && chmod 775 /backups


######################################################################
# STAGE 5: Runtime Frontoffice (Eredita tutto il caching del runtime-base)
######################################################################
FROM runtime-base AS runtime-frontoffice
COPY --chown=www-app:www-data frontoffice/ /var/www/html/frontoffice/
RUN mkdir -p /var/www/html/frontoffice/storage/logs \
    && touch /var/www/html/frontoffice/storage/logs/app.log \
    && chown -R www-data:www-data /var/www/html/frontoffice/storage \
    && chmod 2775 /var/www/html/frontoffice/storage/logs \
    && chmod 664 /var/www/html/frontoffice/storage/logs/app.log
