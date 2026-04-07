FROM python:3.12-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
      openssl curl libxml2-utils bash \
    && pip install --no-cache-dir cryptography \
    && rm -rf /var/lib/apt/lists/*

# Script openssl SPID (già progettato per girare in container)
COPY metadata/spid-gencert-public.sh   /builder/spid-gencert-public.sh

# Script del builder
COPY metadata/builder/entrypoint.sh    /builder/entrypoint.sh
COPY metadata/builder/setup-spid.sh    /builder/setup-spid.sh
COPY metadata/builder/setup-cieoidc.sh /builder/setup-cieoidc.sh
COPY metadata/builder/gen-jwk.py       /builder/gen-jwk.py
COPY metadata/builder/export-agid.sh    /builder/export-agid.sh
COPY metadata/builder/export-cieoidc.sh /builder/export-cieoidc.sh
COPY metadata/builder/backup.sh         /builder/backup.sh
COPY metadata/builder/restore.sh       /builder/restore.sh
COPY metadata/builder/status.sh        /builder/status.sh
COPY metadata/builder/renew-spid.sh    /builder/renew-spid.sh
COPY metadata/builder/renew-cieoidc.sh /builder/renew-cieoidc.sh
COPY metadata/builder/server.py        /builder/server.py

RUN chmod +x /builder/*.sh

ENTRYPOINT ["/builder/entrypoint.sh"]
CMD ["server"]
