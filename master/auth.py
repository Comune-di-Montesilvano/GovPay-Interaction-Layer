"""
Autenticazione Bearer token per le API del Master Container.
Il token è letto dalla variabile d'ambiente MASTER_TOKEN.
"""
import os
from fastapi import HTTPException, Security, status
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

security = HTTPBearer(auto_error=False)


def _get_expected_token() -> str | None:
    return os.getenv("MASTER_TOKEN") or None


def require_auth(credentials: HTTPAuthorizationCredentials | None = Security(security)) -> str:
    """
    Dependency per le route che richiedono autenticazione.
    Solleva 401 se il token manca o non corrisponde.
    """
    expected = _get_expected_token()

    if expected is None:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="MASTER_TOKEN non configurato.",
        )

    if credentials is None or credentials.credentials != expected:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Token non valido.",
            headers={"WWW-Authenticate": "Bearer"},
        )

    return credentials.credentials
