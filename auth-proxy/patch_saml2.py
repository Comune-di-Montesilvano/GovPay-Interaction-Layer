"""
Patch 1 — pysaml2: _filter_values() returns None instead of [] when vals=None.

Root cause:
  SATOSA's _get_approved_attributes() builds:
    all_attributes = {v: None for v in aconv._fro.values()}
  Then pysaml2 calls _filter_values(None, []) which returns None (vals as-is).
  The caller does: res[attr].extend(None) → TypeError: 'NoneType' object is not iterable

  This causes SATOSA to crash on every AuthnRequest with a generic 302 redirect
  to SATOSA_UNKNOW_ERROR_REDIRECT_PAGE, preventing the disco page from receiving
  the correct ?return= parameter, so clicking on IDPs does nothing.

Fix: return [] instead of None when vals is None and vlist is empty/None.

Patch 2 — spidsaml2: handle_error() returns HTTP 403 su autenticazione fallita/annullata.

Root cause:
  spidsaml2.SpidBackend.handle_error() restituisce sempre HTTP 403 (Response con
  status="403") quando l'autenticazione fallisce (es. utente annulla il login).
  Questo 403 attraversa eventuali reverse proxy intermedi che lo interpretano come
  un errore del backend e interrompono il flusso applicativo.

Fix: se SATOSA_CANCEL_REDIRECT_URL (priorità) o SATOSA_UNKNOW_ERROR_REDIRECT_PAGE
    sono configurate, handle_error() restituisce una pagina HTML 200 che esegue
    redirect client-side via window.location.replace(), evitando 302 server-side.
"""
import sys
import glob
import os
import re

# ─────────────────────────────────────────────────────────────────────────────
# Patch 3: CieOidcRp authorization_endpoint — Redirect → JS window.location
# ─────────────────────────────────────────────────────────────────────────────
#
# Il reverse proxy esterno intercetta i redirect HTTP 302 verso provider esterni.
# Restituire una pagina HTML 200 con window.location.replace() fa sì che sia
# il browser a navigare direttamente verso il provider CIE OIDC.
# ─────────────────────────────────────────────────────────────────────────────

_AUTHZ_PATH = "/satosa_proxy/backends/cieoidc/endpoints/authorization_endpoint.py"

if not os.path.exists(_AUTHZ_PATH):
    print(f"[patch_cieoidc_authz] {_AUTHZ_PATH} not found — skipping patch 3")
else:
    print(f"[patch_cieoidc_authz] Patching {_AUTHZ_PATH}")
    with open(_AUTHZ_PATH) as _f:
        _authz_content = _f.read()
    _OLD3 = "        resp = Redirect(url)\n\n        return resp\n"
    _NEW3 = '''        _js_url = json.dumps(url)
        resp = Response(
            message=f"<!DOCTYPE html><html><head><meta charset='utf-8'><script>window.location.replace({_js_url});</script></head><body></body></html>".encode("utf-8"),
            status="200 OK",
            content="text/html; charset=utf-8",
        )

        return resp
'''
    if _OLD3 not in _authz_content:
        if "_js_url = json.dumps(url)" in _authz_content:
            print("[patch_cieoidc_authz] Already patched, nothing to do.")
        else:
            print("[patch_cieoidc_authz] WARNING: expected pattern not found — skipping.")
    else:
        _authz_content = _authz_content.replace(_OLD3, _NEW3, 1)
        with open(_AUTHZ_PATH, "w") as _f:
            _f.write(_authz_content)
        print("[patch_cieoidc_authz] Patch applied: Redirect → JS window.location.")


# Find the actual pysaml2 path (Python version may vary)
candidates = glob.glob("/.venv/lib/python*/site-packages/saml2/assertion.py")
if not candidates:
    print("ERROR: saml2/assertion.py not found under /.venv - skipping patch")
else:
    path = candidates[0]
    print(f"[patch_saml2] Patching {path}")

    with open(path) as f:
        content = f.read()

    OLD = (
        "    if not vlist:  # No value specified equals any value\n"
        "        return vals\n"
    )
    NEW = (
        "    if not vlist:  # No value specified equals any value\n"
        "        return vals if vals is not None else []\n"
    )

    if OLD not in content:
        if "return vals if vals is not None else []" in content:
            print("[patch_saml2] Already patched, nothing to do.")
        else:
            print("[patch_saml2] WARNING: expected pattern not found — pysaml2 may have changed.")
            print("[patch_saml2] Skipping patch. SSO may not work correctly.")
    else:
        content = content.replace(OLD, NEW, 1)
        with open(path, "w") as f:
            f.write(content)

        print("[patch_saml2] patch applied successfully.")

# ─────────────────────────────────────────────────────────────────────────────
# Patch 2: spidsaml2.handle_error() → Redirect invece di HTTP 403
# ─────────────────────────────────────────────────────────────────────────────

import os

SPID_PATH = "/satosa_proxy/backends/spidsaml2.py"

if not os.path.exists(SPID_PATH):
    print(f"[patch_spidsaml2] {SPID_PATH} not found — skipping patch 2")
else:
    print(f"[patch_spidsaml2] Patching {SPID_PATH}")

    with open(SPID_PATH) as f:
        spid_content = f.read()

    # 2a) Aggiungi import Redirect se non già presente
    REDIRECT_IMPORT = "from satosa.response import Redirect\n"
    RESPONSE_IMPORT = "from satosa.response import Response\n"

    if "from satosa.response import Redirect" not in spid_content:
        spid_content = spid_content.replace(
            RESPONSE_IMPORT,
            RESPONSE_IMPORT + REDIRECT_IMPORT,
            1,
        )
        print("[patch_spidsaml2] Added 'from satosa.response import Redirect'")

    # 2b) Sostituisce il return 403 con un redirect configurabile
    OLD2 = (
        "        return Response(result, content=\"text/html; charset=utf8\", status=\"403\")\n"
    )
    NEW2 = (
        "        _cancel_url = (\n"
        "            os.environ.get(\"SATOSA_CANCEL_REDIRECT_URL\")\n"
        "            or os.environ.get(\"SATOSA_UNKNOW_ERROR_REDIRECT_PAGE\")\n"
        "        )\n"
        "        if _cancel_url:\n"
        "            _js_url = json.dumps(_cancel_url)\n"
        "            return Response(\n"
        "                message=f\"<!DOCTYPE html><html><head><meta charset='utf-8'><script>window.location.replace({_js_url});</script></head><body></body></html>\".encode(\"utf-8\"),\n"
        "                status=\"200 OK\",\n"
        "                content=\"text/html; charset=utf8\",\n"
        "            )\n"
        "        return Response(result, content=\"text/html; charset=utf8\", status=\"403\")\n"
    )

    if OLD2 not in spid_content:
        if "_cancel_url" in spid_content:
            print("[patch_spidsaml2] handle_error already patched, nothing to do.")
        else:
            print("[patch_spidsaml2] WARNING: expected pattern not found in handle_error — spidsaml2 may have changed.")
            print("[patch_spidsaml2] Skipping patch 2. Cancellation will still return 403.")
    else:
        # Verifica che 'import os' sia presente nel file
        if "import os\n" not in spid_content and "import os " not in spid_content:
            spid_content = "import os\n" + spid_content
            print("[patch_spidsaml2] Added 'import os'")

        spid_content = spid_content.replace(OLD2, NEW2, 1)
        with open(SPID_PATH, "w") as f:
            f.write(spid_content)

        print("[patch_spidsaml2] handle_error patched: 403 -> Redirect on cancel.")

# -------------------------------------------------------------------------
# Patch 4: CieOidcBackend.__init__ -- _generate_trust_chains non-fatal
#
# Root cause:
#   CieOidcBackend.__init__ chiama self._generate_trust_chains() che fa una
#   richiesta HTTP alla trust anchor (registry.servizicie.interno.gov.it).
#   Se il registry non e' raggiungibile al boot, l'eccezione propaga e
#   SATOSA non si avvia, bloccando anche export-cieoidc.
#
# Fix:
#   Wrappa la chiamata in try/except: se la fetch fallisce, log warning e
#   imposta self.trust_chain = {} -- SATOSA parte, gli endpoint entity
#   configuration funzionano, il fallimento si manifesta solo all'auth reale.
# -------------------------------------------------------------------------

_CIEOIDC_PATH = "/satosa_proxy/backends/cieoidc/cieoidc.py"

if not os.path.exists(_CIEOIDC_PATH):
    print(f"[patch_cieoidc_init] {_CIEOIDC_PATH} not found -- skipping patch 4")
else:
    print(f"[patch_cieoidc_init] Patching {_CIEOIDC_PATH}")
    with open(_CIEOIDC_PATH) as _f:
        _cie_content = _f.read()

    _endpoints_re = re.compile(r"^(\s*)self\.endpoints\s*=\s*\{\}\s*$", re.MULTILINE)
    _m_end = _endpoints_re.search(_cie_content)
    if _m_end is None:
        print("[patch_cieoidc_init] WARNING: anchor 'self.endpoints = {}' not found -- skipping patch 4.")
    else:
        _indent = _m_end.group(1)
        _start = _m_end.end()
        _metadata_re = re.compile(
            rf"^{re.escape(_indent)}metadata\s*=\s*self\.config\.get\(\"metadata\",\s*\{{\}}\)\.get\(\"openid_relying_party\",\s*\{{\}}\)\s*$",
            re.MULTILINE,
        )
        _m_meta = _metadata_re.search(_cie_content, _start)
        if _m_meta is None:
            print("[patch_cieoidc_init] WARNING: anchor 'metadata = ...openid_relying_party...' not found -- skipping patch 4.")
        else:
            _current_block = _cie_content[_start:_m_meta.start()]
            _desired_block = (
                f"\n{_indent}try:\n"
                f"{_indent}    self.trust_chain = self._generate_trust_chains()\n"
                f"{_indent}except Exception as _tc_exc:\n"
                f"{_indent}    import logging as _log\n"
                f"{_indent}    _log.getLogger(__name__).warning(\n"
                f"{_indent}        f\"[patch] CieOidcBackend: trust chain fetch fallita al boot \"\n"
                f"{_indent}        f\"({{type(_tc_exc).__name__}}: {{_tc_exc}}). \"\n"
                f"{_indent}        \"SATOSA parte senza trust chain precalcolata. \"\n"
                f"{_indent}        \"L'autenticazione CIE potrebbe fallire se il registry non e' raggiungibile.\"\n"
                f"{_indent}    )\n"
                f"{_indent}    self.trust_chain = {{}}\n"
            )

            if _current_block == _desired_block:
                print("[patch_cieoidc_init] Already patched, nothing to do.")
            else:
                _cie_content = _cie_content[:_start] + _desired_block + _cie_content[_m_meta.start():]
                with open(_CIEOIDC_PATH, "w") as _f:
                    _f.write(_cie_content)
                print("[patch_cieoidc_init] Normalized trust-chain init block (idempotent).")
