# Build context: docker/db/ (docker build -f docker/db/Dockerfile docker/)
# Bakes in DB init scripts so no bind-mount is needed in production.
FROM mariadb:11

COPY db-init/ /docker-entrypoint-initdb.d/
