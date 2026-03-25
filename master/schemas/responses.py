from pydantic import BaseModel
from typing import Optional


class HealthResponse(BaseModel):
    status: str
    docker_connected: bool


class SetupStatusResponse(BaseModel):
    setup_complete: bool


class ContainerStatusItem(BaseModel):
    name: str
    status: str
    image: str


class ContainerStatusResponse(BaseModel):
    services: list[ContainerStatusItem]


class OperationResponse(BaseModel):
    success: bool
    message: str
    details: Optional[dict] = None


class BackupListItem(BaseModel):
    filename: str
    size_bytes: int
    created_at: str


class BackupListResponse(BaseModel):
    backups: list[BackupListItem]
