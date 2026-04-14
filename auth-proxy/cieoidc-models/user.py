from typing import Optional

from pydantic import BaseModel


class OidcUser(BaseModel):
    username: str
    first_name: str
    last_name: str
    email: Optional[str] = None
    sub: str
    fiscal_number: str
    attributes: Optional[dict] = None
