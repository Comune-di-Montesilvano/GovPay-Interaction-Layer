"""
Lettura e scrittura di config.json dal volume gil_config.
"""
import json
import os
import stat
import threading

CONFIG_PATH = os.getenv("CONFIG_PATH", "/config/config.json")
RUNTIME_DIR = os.getenv("RUNTIME_DIR", "/runtime")

_lock = threading.Lock()

# Campi sensibili che non vengono mai restituiti nelle GET
SENSITIVE_FIELDS = {"master_token", "password", "root_password", "password_cittadini",
                    "encryption_key", "salt", "state_encryption_key", "user_id_hash_salt"}


def read_config() -> dict:
    """Legge config.json, ritorna dict vuoto se non esiste."""
    with _lock:
        if not os.path.exists(CONFIG_PATH):
            return {}
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            return json.load(f)


def read_config_redacted() -> dict:
    """Legge config.json oscurando i valori sensibili (per le GET API)."""
    data = read_config()
    return _redact(data)


def write_config(config: dict) -> None:
    """Scrive config.json in modo atomico con permessi 600."""
    with _lock:
        tmp_path = CONFIG_PATH + ".tmp"
        with open(tmp_path, "w", encoding="utf-8") as f:
            json.dump(config, f, indent=2, ensure_ascii=False)
        os.chmod(tmp_path, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)  # 644
        os.replace(tmp_path, CONFIG_PATH)


def write_env_bootstrap(variables: dict) -> None:
    """Scrive ./runtime/.env.bootstrap con le credenziali generate dal wizard."""
    path = os.path.join(RUNTIME_DIR, ".env.bootstrap")
    lines = [f"{k}={v}" for k, v in variables.items() if v is not None]
    with _lock:
        with open(path, "w", encoding="utf-8") as f:
            f.write("# Generato dal wizard GIL — NON MODIFICARE MANUALMENTE\n")
            f.write("\n".join(lines) + "\n")
        os.chmod(path, stat.S_IRUSR | stat.S_IWUSR | stat.S_IRGRP | stat.S_IROTH)  # 644


def is_setup_complete() -> bool:
    """True se config.json esiste e setup_complete=true."""
    data = read_config()
    return bool(data.get("setup_complete", False))


def _redact(obj: dict | list | str | None, _depth: int = 0) -> dict | list | str | None:
    """Ricorsivamente oscura i valori corrispondenti ai campi sensibili."""
    if isinstance(obj, dict):
        result = {}
        for k, v in obj.items():
            if k.lower() in SENSITIVE_FIELDS:
                result[k] = "***"
            else:
                result[k] = _redact(v, _depth + 1)
        return result
    if isinstance(obj, list):
        return [_redact(item, _depth + 1) for item in obj]
    return obj
