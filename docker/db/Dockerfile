# Build context: docker/db/ (docker build -f docker/db/Dockerfile docker/)
# Nessuno script di init: schema e migrazioni li applica il backoffice all'avvio.
FROM mariadb:12.3@sha256:4ebbcf100a3ba320766dfa0e1af008df12261301db6ef72798c7650f70794aed

# Patch di sicurezza Ubuntu sopra la base fissata per digest. APT_REFRESH (data del giorno da
# docker-publish) invalida il layer a ogni build, altrimenti la cache congela l'upgrade.
# Il repo mariadb dell'immagine punta alla release esatta (archive.mariadb.org/mariadb-12.3.x):
# l'upgrade non cambia versione di MariaDB, quella arriva solo col digest (dependabot).
ARG APT_REFRESH=local
RUN echo "apt refresh: ${APT_REFRESH}"     && apt-get update     && apt-get upgrade -y --no-install-recommends     && rm -rf /var/lib/apt/lists/*
