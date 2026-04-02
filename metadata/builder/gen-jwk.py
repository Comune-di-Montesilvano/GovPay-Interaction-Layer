#!/usr/bin/env python3
# gen-jwk.py — genera 3 chiavi RSA JWK per CIE OIDC
# Estratto da metadata/setup-cie-oidc.sh
# Uso: python3 gen-jwk.py <output_dir>

import sys
import json
import base64
import hashlib
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.backends import default_backend

OUTPUT_DIR = Path(sys.argv[1])


def int_to_b64url(n):
    length = (n.bit_length() + 7) // 8
    return base64.urlsafe_b64encode(n.to_bytes(length, "big")).rstrip(b"=").decode()


def rfc7638_kid(e_b64, n_b64):
    """RFC 7638 JWK Thumbprint (SHA-256)"""
    canonical = json.dumps({"e": e_b64, "kty": "RSA", "n": n_b64},
                           separators=(",", ":"), sort_keys=True)
    digest = hashlib.sha256(canonical.encode()).digest()
    return base64.urlsafe_b64encode(digest).rstrip(b"=").decode()


def gen_rsa_jwk(extra_fields=None):
    key = rsa.generate_private_key(public_exponent=65537, key_size=2048, backend=default_backend())
    priv = key.private_numbers()
    pub = priv.public_numbers
    e_b64 = int_to_b64url(pub.e)
    n_b64 = int_to_b64url(pub.n)
    jwk = {
        "kty": "RSA",
        "kid": rfc7638_kid(e_b64, n_b64),
        "e": e_b64,
        "n": n_b64,
        "d": int_to_b64url(priv.d),
        "p": int_to_b64url(priv.p),
        "q": int_to_b64url(priv.q),
        "dp": int_to_b64url(priv.dmp1),
        "dq": int_to_b64url(priv.dmq1),
        "qi": int_to_b64url(priv.iqmp),
    }
    if extra_fields:
        jwk = {**extra_fields, **jwk}
    return jwk


# Chiave 1: federation signing
jwk_fed = gen_rsa_jwk()
(OUTPUT_DIR / "jwk-federation.json").write_text(json.dumps(jwk_fed, indent=2), encoding="utf-8")
print(f"[OK] jwk-federation.json  kid={jwk_fed['kid']}")

# Chiave 2: core signing
jwk_sig = gen_rsa_jwk({"use": "sig"})
(OUTPUT_DIR / "jwk-core-sig.json").write_text(json.dumps(jwk_sig, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-sig.json    kid={jwk_sig['kid']}")

# Chiave 3: core encryption (RSA-OAEP)
jwk_enc = gen_rsa_jwk({"use": "enc", "alg": "RSA-OAEP"})
(OUTPUT_DIR / "jwk-core-enc.json").write_text(json.dumps(jwk_enc, indent=2), encoding="utf-8")
print(f"[OK] jwk-core-enc.json    kid={jwk_enc['kid']}")
