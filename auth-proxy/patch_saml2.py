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
  sono configurate, handle_error() restituisce un 302 Redirect invece di 403.
"""
import sys
import glob
import os

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
    sys.exit(0)

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
        sys.exit(0)
    else:
        print("[patch_saml2] WARNING: expected pattern not found — pysaml2 may have changed.")
        print("[patch_saml2] Skipping patch. SSO may not work correctly.")
        sys.exit(0)

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
    sys.exit(0)

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
    "            return Redirect(_cancel_url)\n"
    "        return Response(result, content=\"text/html; charset=utf8\", status=\"403\")\n"
)

if OLD2 not in spid_content:
    if "_cancel_url" in spid_content:
        print("[patch_spidsaml2] handle_error already patched, nothing to do.")
    else:
        print("[patch_spidsaml2] WARNING: expected pattern not found in handle_error — spidsaml2 may have changed.")
        print("[patch_spidsaml2] Skipping patch 2. Cancellation will still return 403.")
    sys.exit(0)

# Verifica che 'import os' sia presente nel file
if "import os\n" not in spid_content and "import os " not in spid_content:
    spid_content = "import os\n" + spid_content
    print("[patch_spidsaml2] Added 'import os'")

spid_content = spid_content.replace(OLD2, NEW2, 1)
with open(SPID_PATH, "w") as f:
    f.write(spid_content)

print("[patch_spidsaml2] handle_error patched: 403 → Redirect on cancel.")
