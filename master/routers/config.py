from fastapi import APIRouter, Depends, HTTPException, status
from auth import require_auth
from schemas.requests import ConfigWriteRequest
from schemas.responses import OperationResponse, SetupStatusResponse
from services import config_service

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
