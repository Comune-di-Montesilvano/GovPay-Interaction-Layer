"""
Autenticazione Bearer token per le API del Master Container.
Il token è letto da config.json (campo master_token).
"""
import json
import os
from fastapi import HTTPException, Security, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

CONFIG_PATH = os.getenv("CONFIG_PATH", "/config/config.json")

security = HTTPBearer(auto_error=False)


def _load_token() -> str | None:
    try:
        with open(CONFIG_PATH, "r") as f:
            data = json.load(f)
        return data.get("master_token")
    except Exception:
        return None


def require_auth(credentials: HTTPAuthorizationCredentials | None = Security(security)) -> str:
    """
    Dependency per le route che richiedono autenticazione.
    Solleva 401 se il token manca o non corrisponde.
    """
    expected = _load_token()

    if expected is None:
        # config.json non ancora scritto (primo avvio wizard) — accesso consentito solo da localhost
        # In produzione il token deve sempre essere presente dopo il setup
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Setup non completato: config.json non trovato o privo di master_token.",
        )

    if credentials is None or credentials.credentials != expected:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token non valido.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    return credentials.credentials
