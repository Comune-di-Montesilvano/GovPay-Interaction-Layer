import copy
import json
import logging
from typing import Callable
from urllib.parse import urlsplit, urlunsplit

from satosa.attribute_mapping import AttributeMapper
from satosa.context import Context
from satosa.internal import InternalData
from satosa.response import Redirect, Response

from backends.cieoidc.models.federation import FederationEntityConfiguration
from backends.cieoidc.utils.handlers.base_endpoint import BaseEndpoint
from backends.cieoidc.utils.helpers.jwtse import create_jws

logger = logging.getLogger(__name__)

_RP_ONLY_EXCLUDED_FEDERATION_KEYS = (
    "federation_fetch_endpoint",
    "federation_trust_mark_status_endpoint",
    "federation_list_endpoint",
)


def _normalize_entity_id(value: str) -> str:
    if not value:
        return ""

    raw = value.strip()
    parts = urlsplit(raw)
    if parts.scheme in ("http", "https") and parts.netloc:
        normalized_path = parts.path.rstrip("/") or "/"
        return urlunsplit((parts.scheme, parts.netloc, normalized_path, parts.query, parts.fragment))

    return raw


class FederationResolveHandler(BaseEndpoint):
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
        self._jwks_federation = self.config.get("jwks_federation", [])
        self._jwks_core = self.config.get("jwks_core", [])
        self._default_sig_alg = self.config.get("default_sig_alg", "RS256")
        self._auth_hints = self.config.get("authority_hints")
        self._trust_marks = self.config.get("trust_marks", [])
        self._entity_configuration_exp = self.config.get("entity_configuration_exp", 2800)
        self._entity_type = self.config.get("entity_type", "openid_relying_party")
        self._metadata = copy.deepcopy(self.config.get("metadata", {}))
        if self._entity_type == "openid_relying_party":
            fed_meta = self._metadata.get("federation_entity", {})
            for key in _RP_ONLY_EXCLUDED_FEDERATION_KEYS:
                fed_meta.pop(key, None)
        meta = self._metadata.get(self._entity_type, {})
        self._subject = meta.get("client_id") or f"{base_url}/{name}"

    def _build_payload(self) -> dict:
        entity = FederationEntityConfiguration(
            self._subject,
            self._entity_configuration_exp,
            self._default_sig_alg,
            self._jwks_core,
            self._jwks_federation,
            self._entity_type,
            self._metadata,
            self._auth_hints,
            self._trust_marks,
        )
        payload = entity.entity_configuration_as_dict
        # RP foglia: non emette trust chain verso discendenti, ma mantiene struttura valida.
        payload["trust_chain"] = []
        return payload

    def endpoint(self, context: Context) -> Redirect | Response:
        requested_sub = context.qs_params.get("sub", "")
        if requested_sub and _normalize_entity_id(requested_sub) != _normalize_entity_id(self._subject):
            data = json.dumps({"error": "entity_not_found", "sub": requested_sub})
            return Response(data, status="404", content="application/json")

        payload = self._build_payload()
        if context.qs_params.get("format", "") == "json":
            return Response(json.dumps(payload), status="200", content="application/json")

        jws = create_jws(
            payload,
            self._jwks_federation[0],
            alg=self._default_sig_alg,
            typ="entity-statement+jwt",
        )
        return Response(jws, status="200", content="application/entity-statement+jwt")
