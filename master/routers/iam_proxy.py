from fastapi import APIRouter, Depends, HTTPException, status
from auth import require_auth
from schemas.responses import OperationResponse
from services import docker_service

router = APIRouter(prefix="/iam-proxy", tags=["iam-proxy"])


@router.post("/restart", response_model=OperationResponse)
def restart_iam_proxy(_token: str = Depends(require_auth)):
    """Riavvia i container dell'IAM Proxy (iam-proxy-italia e satosa-nginx)."""
    try:
        results = docker_service.restart_services(["iam-proxy-italia", "satosa-nginx"])
        return OperationResponse(success=True, message="IAM Proxy riavviato.", details=results)
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/regenerate-sp-metadata", response_model=OperationResponse)
def regenerate_sp_metadata(_token: str = Depends(require_auth)):
    """Rigenera i metadata SP del frontoffice riavviando init-frontoffice-sp-metadata."""
    try:
        results = docker_service.restart_services(["init-frontoffice-sp-metadata"])
        return OperationResponse(
            success=True,
            message="Rigenerazione metadata SP avviata.",
            details=results,
        )
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))
