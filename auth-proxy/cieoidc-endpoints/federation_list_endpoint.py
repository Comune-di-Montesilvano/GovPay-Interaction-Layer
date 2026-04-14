import json
from typing import Callable

from satosa.attribute_mapping import AttributeMapper
from satosa.context import Context
from satosa.internal import InternalData
from satosa.response import Redirect, Response

from backends.cieoidc.utils.handlers.base_endpoint import BaseEndpoint


class FederationListHandler(BaseEndpoint):
    def __init__(
        self,
        config: dict,
        internal_attributes: dict[str, dict[str, str | list[str]]],
        base_url: str,
        name: str,
        auth_callback_func: Callable[[Context, InternalData], Response],
        converter: AttributeMapper,
        trust,
    ) -> None:
        super().__init__(config, internal_attributes, base_url, name, auth_callback_func, converter)

    def endpoint(self, context: Context) -> Redirect | Response:
        # RP foglia non ha discendenti registrati.
        data = json.dumps({"entities": []})
        return Response(data, status="200", content="application/json")
