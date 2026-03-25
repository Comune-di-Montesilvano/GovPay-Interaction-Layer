from fastapi import APIRouter
from schemas.responses import HealthResponse

router = APIRouter()


@router.get("/health", response_model=HealthResponse, tags=["health"])
def health_check():
    """Healthcheck senza autenticazione."""
    docker_ok = False
    try:
        import docker
        client = docker.from_env()
        client.ping()
        docker_ok = True
    except Exception:
        pass
    return HealthResponse(status="ok", docker_connected=docker_ok)
