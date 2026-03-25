from pydantic import BaseModel
from typing import Optional


class RestartRequest(BaseModel):
    services: list[str]


class ProfileRequest(BaseModel):
    profile: str


class RestoreRequest(BaseModel):
    filename: str


class ConfigWriteRequest(BaseModel):
    config: dict


class IamProxyEnvRequest(BaseModel):
    settings: dict[str, str]
