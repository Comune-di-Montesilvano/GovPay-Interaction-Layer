# Build context: docker/db/ (docker build -f docker/db/Dockerfile docker/)
# Bakes in DB init scripts so no bind-mount is needed in production.
FROM mariadb:12.3@sha256:d46e102fa8b9b72bfcdeb56f9dd538742092119dc8b11f31fe662605cd234cd9

COPY db-init/ /docker-entrypoint-initdb.d/
