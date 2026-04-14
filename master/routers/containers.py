from fastapi import APIRouter, Depends, HTTPException, status
from auth import require_auth
from schemas.requests import RestartRequest, ProfileRequest
from schemas.responses import ContainerStatusResponse, ContainerStatusItem, OperationResponse
from services import docker_service

router = APIRouter(prefix="/containers", tags=["containers"])


@router.get("/status", response_model=ContainerStatusResponse)
def get_status(_token: str = Depends(require_auth)):
    """Stato running di tutti i container del progetto GIL."""
    try:
        items = docker_service.get_project_containers()
        return ContainerStatusResponse(
            services=[ContainerStatusItem(**i) for i in items]
        )
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/restart", response_model=OperationResponse)
def restart_services(body: RestartRequest, _token: str = Depends(require_auth)):
    """Riavvia uno o più servizi del compose."""
    try:
        results = docker_service.restart_services(body.services)
        return OperationResponse(success=True, message="Restart eseguito.", details=results)
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/recreate", response_model=OperationResponse)
def recreate_services(body: RestartRequest, _token: str = Depends(require_auth)):
    """Ricrea i container con --force-recreate (rilegge env_file dal compose)."""
    try:
        out = docker_service.recreate_services(body.services)
        return OperationResponse(success=True, message="Recreate eseguito.", details={"output": out[:500]})
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/start-profile", response_model=OperationResponse)
def start_profile(body: ProfileRequest, _token: str = Depends(require_auth)):
    """Avvia tutti i servizi di un profilo compose (es. auth-proxy)."""
    try:
        out = docker_service.start_profile(body.profile)
        return OperationResponse(success=True, message=f"Profilo '{body.profile}' avviato.", details={"output": out[:500]})
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/stop-profile", response_model=OperationResponse)
def stop_profile(body: ProfileRequest, _token: str = Depends(require_auth)):
    """Ferma tutti i servizi di un profilo compose."""
    try:
        out = docker_service.stop_profile(body.profile)
        return OperationResponse(success=True, message=f"Profilo '{body.profile}' fermato.", details={"output": out[:500]})
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))
