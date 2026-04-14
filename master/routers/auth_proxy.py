import base64
import json
import os
import xml.etree.ElementTree as ET
from datetime import datetime, timezone

from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, status
from fastapi.responses import FileResponse, JSONResponse
from auth import require_auth
from schemas.responses import OperationResponse
from services import docker_service

router = APIRouter(prefix="/auth-proxy", tags=["auth-proxy"])

SP_METADATA_PATH = os.getenv("SP_METADATA_PATH", "/sp-metadata")
SP_METADATA_FILE = os.path.join(SP_METADATA_PATH, "frontoffice_sp.xml")
PROJECT_DIR = os.getenv("PROJECT_DIR", "/project")
CIE_METADATA_DIR = os.path.join(PROJECT_DIR, "metadata", "cieoidc")
CIE_KEYS_DIR = os.path.join(PROJECT_DIR, "metadata", "cieoidc-keys")

_SAML_NS = {
    "md": "urn:oasis:names:tc:SAML:2.0:metadata",
    "ds": "http://www.w3.org/2000/09/xmldsig#",
}


def _parse_cert_expiry(cert_b64: str) -> str | None:
    """Parse X509 certificate expiry from base64 DER. Returns ISO date string or None."""
    try:
        from cryptography import x509
        from cryptography.hazmat.backends import default_backend

        der = base64.b64decode(cert_b64)
        cert = x509.load_der_x509_certificate(der, default_backend())
        not_after = cert.not_valid_after_utc
        return not_after.strftime("%Y-%m-%dT%H:%M:%SZ")
    except Exception:
        return None


def _is_expired(iso_date: str | None) -> bool:
    if not iso_date:
        return False
    try:
        dt = datetime.fromisoformat(iso_date.replace("Z", "+00:00"))
        return dt < datetime.now(timezone.utc)
    except Exception:
        return False


def _parse_spid_metadata(xml_path: str) -> dict:
    """Parse SPID SP metadata XML and return structured info."""
    if not os.path.isfile(xml_path):
        return {"exists": False}

    try:
        tree = ET.parse(xml_path)
        root = tree.getroot()

        entity_id = root.attrib.get("entityID", "")
        valid_until = root.attrib.get("validUntil", "")

        sp_desc = root.find("md:SPSSODescriptor", _SAML_NS)
        acs_urls = []
        if sp_desc is not None:
            for acs in sp_desc.findall("md:AssertionConsumerService", _SAML_NS):
                acs_urls.append(acs.attrib.get("Location", ""))

        # Extract first X509Certificate
        cert_expiry = None
        certs = root.findall(".//ds:X509Certificate", _SAML_NS)
        if certs:
            cert_b64 = "".join(certs[0].text.split()) if certs[0].text else ""
            cert_expiry = _parse_cert_expiry(cert_b64)

        file_stat = os.stat(xml_path)
        return {
            "exists": True,
            "entity_id": entity_id,
            "valid_until": valid_until,
            "valid_until_expired": _is_expired(valid_until),
            "acs_urls": acs_urls,
            "cert_expiry": cert_expiry,
            "cert_expired": _is_expired(cert_expiry),
            "file_size": file_stat.st_size,
            "file_mtime": datetime.fromtimestamp(file_stat.st_mtime, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
    except Exception as e:
        return {"exists": True, "parse_error": str(e)}


def _parse_cie_metadata() -> dict:
    """Parse CIE OIDC entity configuration and return structured info."""
    cfg_path = os.path.join(CIE_METADATA_DIR, "entity-configuration.json")
    if not os.path.isfile(cfg_path):
        return {"exists": False}

    try:
        with open(cfg_path) as f:
            cfg = json.load(f)

        exp_ts = cfg.get("exp")
        exp_iso = datetime.fromtimestamp(exp_ts, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ") if exp_ts else None
        iat_ts = cfg.get("iat")
        iat_iso = datetime.fromtimestamp(iat_ts, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ") if iat_ts else None

        # Count JWK keys
        jwks_path = os.path.join(CIE_METADATA_DIR, "jwks-rp.json")
        key_count = 0
        if os.path.isfile(jwks_path):
            with open(jwks_path) as f:
                jwks = json.load(f)
            key_count = len(jwks.get("keys", []))

        file_stat = os.stat(cfg_path)
        return {
            "exists": True,
            "iss": cfg.get("iss", ""),
            "sub": cfg.get("sub", ""),
            "exp": exp_iso,
            "exp_expired": _is_expired(exp_iso),
            "iat": iat_iso,
            "jwk_count": key_count,
            "file_size": file_stat.st_size,
            "file_mtime": datetime.fromtimestamp(file_stat.st_mtime, tz=timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
    except Exception as e:
        return {"exists": True, "parse_error": str(e)}


# ── Existing endpoints ──────────────────────────────────────────────────────

@router.post("/restart", response_model=OperationResponse)
def restart_auth_proxy(_token: str = Depends(require_auth)):
    """Riavvia i container dell'auth-proxy (auth-proxy e auth-proxy-nginx)."""
    try:
        results = docker_service.restart_services(["auth-proxy", "auth-proxy-nginx"])
        return OperationResponse(success=True, message="Auth Proxy riavviato.", details=results)
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


# ── SPID metadata endpoints ─────────────────────────────────────────────────

@router.get("/spid-metadata/info")
def get_spid_metadata_info(_token: str = Depends(require_auth)):
    """Restituisce info parsed del metadata SP SPID (entity ID, ACS, scadenza)."""
    info = _parse_spid_metadata(SP_METADATA_FILE)
    return JSONResponse(content={"success": True, "data": info})


@router.get("/spid-metadata/download")
def download_spid_metadata(_token: str = Depends(require_auth)):
    """Scarica il file XML del metadata SP SPID."""
    if not os.path.isfile(SP_METADATA_FILE):
        raise HTTPException(status_code=404, detail="File metadata SPID non trovato.")
    return FileResponse(
        SP_METADATA_FILE,
        media_type="application/xml",
        filename="frontoffice_sp.xml",
    )


@router.post("/spid-metadata/restore", response_model=OperationResponse)
async def restore_spid_metadata(file: UploadFile = File(...), _token: str = Depends(require_auth)):
    """Ripristina il metadata SP SPID da un file XML caricato."""
    content = await file.read()
    if not content.strip().startswith(b"<?xml") and b"<md:EntityDescriptor" not in content:
        raise HTTPException(status_code=400, detail="Il file non sembra un XML SAML2 valido.")
    os.makedirs(SP_METADATA_PATH, exist_ok=True)
    with open(SP_METADATA_FILE, "wb") as f:
        f.write(content)
    # Crea anche la copia senza estensione (usata da alcuni consumer)
    no_ext = SP_METADATA_FILE.replace(".xml", "")
    with open(no_ext, "wb") as f:
        f.write(content)
    return OperationResponse(success=True, message="Metadata SPID ripristinato.")


# ── CIE metadata endpoints ──────────────────────────────────────────────────

@router.get("/cie-metadata/info")
def get_cie_metadata_info(_token: str = Depends(require_auth)):
    """Restituisce info dell'entity configuration CIE OIDC."""
    info = _parse_cie_metadata()
    return JSONResponse(content={"success": True, "data": info})


@router.get("/cie-metadata/download")
def download_cie_metadata(_token: str = Depends(require_auth)):
    """Scarica l'entity configuration CIE OIDC (JSON)."""
    cfg_path = os.path.join(CIE_METADATA_DIR, "entity-configuration.json")
    if not os.path.isfile(cfg_path):
        raise HTTPException(status_code=404, detail="Entity configuration CIE non trovata.")
    return FileResponse(
        cfg_path,
        media_type="application/json",
        filename="cie-entity-configuration.json",
    )


@router.post("/cie-metadata/restore", response_model=OperationResponse)
async def restore_cie_metadata(file: UploadFile = File(...), _token: str = Depends(require_auth)):
    """Ripristina l'entity configuration CIE da un file JSON caricato."""
    content = await file.read()
    try:
        parsed = json.loads(content)
        if "iss" not in parsed and "sub" not in parsed:
            raise ValueError("Campi obbligatori mancanti (iss, sub)")
    except (json.JSONDecodeError, ValueError) as e:
        raise HTTPException(status_code=400, detail=f"JSON non valido: {e}")
    os.makedirs(CIE_METADATA_DIR, exist_ok=True)
    cfg_path = os.path.join(CIE_METADATA_DIR, "entity-configuration.json")
    with open(cfg_path, "wb") as f:
        f.write(content)
    return OperationResponse(success=True, message="Entity configuration CIE ripristinata.")
