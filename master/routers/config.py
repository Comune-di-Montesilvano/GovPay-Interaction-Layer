import os
from pathlib import Path
from fastapi import APIRouter, Depends, HTTPException, status
from auth import require_auth
from schemas.requests import ConfigWriteRequest, IamProxyEnvRequest, EnvBootstrapRequest
from schemas.responses import OperationResponse, SetupStatusResponse
from services import config_service

CONFIG_PATH = os.getenv("CONFIG_PATH", "/config/config.json")

router = APIRouter(prefix="/config", tags=["config"])


@router.get("/setup-complete", response_model=SetupStatusResponse)
def get_setup_complete(_token: str = Depends(require_auth)):
    return SetupStatusResponse(setup_complete=config_service.is_setup_complete())


@router.get("/json")
def get_config(_token: str = Depends(require_auth)):
    """Ritorna config.json con i valori sensibili oscurati."""
    return config_service.read_config_redacted()


@router.post("/json", response_model=OperationResponse)
def write_config(body: ConfigWriteRequest, _token: str = Depends(require_auth)):
    """Scrive config.json. Usato dal wizard al completamento."""
    try:
        config_service.write_config(body.config)
        return OperationResponse(success=True, message="config.json aggiornato.")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/write-initial", response_model=OperationResponse)
def write_initial_config(body: ConfigWriteRequest):
    """Scrive config.json al primo setup. Nessuna auth: funziona solo se config.json non esiste ancora."""
    if os.path.exists(CONFIG_PATH):
        raise HTTPException(
            status_code=status.HTTP_409_CONFLICT,
            detail="config.json esiste già. Usare POST /config/json (richiede autenticazione).",
        )
    try:
        config_service.write_config(body.config)
        return OperationResponse(success=True, message="config.json scritto correttamente.")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/write-env-bootstrap", response_model=OperationResponse)
def write_env_bootstrap_endpoint(body: EnvBootstrapRequest):
    """Scrive ./runtime/.env.bootstrap con le credenziali DB generate dal wizard. Nessuna auth richiesta."""
    try:
        config_service.write_env_bootstrap(body.variables)
        return OperationResponse(success=True, message=".env.bootstrap scritto.")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/generate-iam-env", response_model=OperationResponse)
def generate_iam_env(body: IamProxyEnvRequest, _token: str = Depends(require_auth)):
    """
    Genera ./runtime/.auth-proxy.env dai settings iam_proxy forniti dal backoffice.
    Il file viene letto dai servizi SATOSA (auth-proxy, auth-proxy-nginx, ecc.)
    tramite env_file al prossimo recreate del container.
    """
    try:
        runtime_dir = Path(os.getenv("RUNTIME_DIR", "/runtime"))
        runtime_dir.mkdir(parents=True, exist_ok=True)
        env_file = runtime_dir / ".auth-proxy.env"
        lines = [f"{k}={v}" for k, v in body.settings.items() if v is not None]
        env_file.write_text("\n".join(lines) + "\n", encoding="utf-8")
        return OperationResponse(success=True, message=f"File .auth-proxy.env generato ({len(lines)} variabili).")
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))
