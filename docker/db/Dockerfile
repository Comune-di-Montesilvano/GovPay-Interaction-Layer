# Build context: docker/db/ (docker build -f docker/db/Dockerfile docker/)
# Bakes in DB init scripts so no bind-mount is needed in production.
FROM mariadb:12.3@sha256:d46e102fa8b9b72bfcdeb56f9dd538742092119dc8b11f31fe662605cd234cd9

# Patch di sicurezza Ubuntu sopra la base fissata per digest. APT_REFRESH (data del giorno da
# docker-publish) invalida il layer a ogni build, altrimenti la cache congela l'upgrade.
# Il repo mariadb dell'immagine punta alla release esatta (archive.mariadb.org/mariadb-12.3.x):
# l'upgrade non cambia versione di MariaDB, quella arriva solo col digest (dependabot).
ARG APT_REFRESH=local
RUN echo "apt refresh: ${APT_REFRESH}"     && apt-get update     && apt-get upgrade -y --no-install-recommends     && rm -rf /var/lib/apt/lists/*

COPY db-init/ /docker-entrypoint-initdb.d/
