"""
Backup e restore completo del sistema GIL.
Contenuto backup: config (settings.json) + dump DB gzippato + tar volumi SPID/metadata
                  + certificati GovPay (gil_certs) + immagini ente (gil_images).
"""
import datetime
import gzip
import io
import json
import os
import shutil
import subprocess
import tarfile
import zipfile

from services.config_service import read_config

BACKUP_DIR = os.getenv("BACKUP_DIR", "/backups")
SPID_CERTS_PATH = os.getenv("SPID_CERTS_PATH", "/spid-certs")
SP_METADATA_PATH = os.getenv("SP_METADATA_PATH", "/sp-metadata")
GOVPAY_CERTS_PATH = os.getenv("GOVPAY_CERTS_PATH", "/certs")
IMAGES_PATH = os.getenv("IMAGES_PATH", "/images")

os.makedirs(BACKUP_DIR, exist_ok=True)


def create_backup(db_host: str, db_user: str, db_password: str, db_name: str,
                  settings_export: dict) -> str:
    """
    Crea un archivio ZIP di backup.
    Ritorna il nome del file creato.
    """
    ts = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"govpay-backup-{ts}.zip"
    filepath = os.path.join(BACKUP_DIR, filename)

    with zipfile.ZipFile(filepath, "w", zipfile.ZIP_DEFLATED) as zf:
        # 1. Manifest
        components = ["settings", "db_dump"]
        if os.path.isdir(SPID_CERTS_PATH): components.append("spid_certs")
        if os.path.isdir(SP_METADATA_PATH): components.append("sp_metadata")
        if os.path.isdir(GOVPAY_CERTS_PATH): components.append("govpay_certs")
        if os.path.isdir(IMAGES_PATH): components.append("images")
        manifest = {
            "created_at": datetime.datetime.now().isoformat(),
            "version": 1,
            "components": components,
        }
        zf.writestr("manifest.json", json.dumps(manifest, indent=2))

        # 2. Settings (valori in chiaro — il backup è sensibile)
        zf.writestr("settings.json", json.dumps(settings_export, indent=2, ensure_ascii=False))

        # 3. DB dump gzippato
        db_dump = _mysqldump(db_host, db_user, db_password, db_name)
        zf.writestr("db_dump.sql.gz", db_dump)

        # 4. Certificati SPID
        if os.path.isdir(SPID_CERTS_PATH):
            zf.writestr("spid_certs.tar.gz", _tar_directory(SPID_CERTS_PATH))

        # 5. SP Metadata
        if os.path.isdir(SP_METADATA_PATH):
            zf.writestr("frontoffice_sp_metadata.tar.gz", _tar_directory(SP_METADATA_PATH))

        # 6. Certificati GovPay (mTLS client cert/key)
        if os.path.isdir(GOVPAY_CERTS_PATH):
            zf.writestr("govpay_certs.tar.gz", _tar_directory(GOVPAY_CERTS_PATH))

        # 7. Immagini ente (logo, favicon)
        if os.path.isdir(IMAGES_PATH):
            zf.writestr("images.tar.gz", _tar_directory(IMAGES_PATH))

    return filename


def list_backups() -> list[dict]:
    """Lista i backup disponibili nella directory /backups."""
    result = []
    if not os.path.isdir(BACKUP_DIR):
        return result
    for fname in sorted(os.listdir(BACKUP_DIR), reverse=True):
        if not fname.endswith(".zip"):
            continue
        fpath = os.path.join(BACKUP_DIR, fname)
        stat = os.stat(fpath)
        result.append({
            "filename": fname,
            "size_bytes": stat.st_size,
            "created_at": datetime.datetime.fromtimestamp(stat.st_mtime).isoformat(),
        })
    return result


def restore_backup(filename: str) -> dict:
    """
    Ripristina da un backup archivio ZIP.
    Ritorna un dict con i componenti ripristinati.
    """
    filepath = os.path.join(BACKUP_DIR, filename)
    if not os.path.isfile(filepath):
        raise FileNotFoundError(f"Backup non trovato: {filename}")

    restored = []
    with zipfile.ZipFile(filepath, "r") as zf:
        names = zf.namelist()

        # Verifica manifest
        if "manifest.json" not in names:
            raise ValueError("Archivio non valido: manifest.json mancante")

        manifest = json.loads(zf.read("manifest.json"))

        # Leggi settings (il chiamante PHP li scriverà nel DB)
        settings = {}
        if "settings.json" in names:
            settings = json.loads(zf.read("settings.json"))
            restored.append("settings")

        # Ripristina DB dump
        if "db_dump.sql.gz" in names:
            config = read_config()
            db = config.get("db", {})
            _mysql_restore(
                db.get("host", "db"),
                db.get("user", "govpay"),
                db.get("password", ""),
                db.get("name", "govpay"),
                gzip.decompress(zf.read("db_dump.sql.gz")),
            )
            restored.append("db_dump")

        # Ripristina certificati SPID
        if "spid_certs.tar.gz" in names and os.path.isdir(SPID_CERTS_PATH):
            _restore_tar(zf.read("spid_certs.tar.gz"), SPID_CERTS_PATH)
            restored.append("spid_certs")

        # Ripristina SP Metadata
        if "frontoffice_sp_metadata.tar.gz" in names and os.path.isdir(SP_METADATA_PATH):
            _restore_tar(zf.read("frontoffice_sp_metadata.tar.gz"), SP_METADATA_PATH)
            restored.append("sp_metadata")

        # Ripristina certificati GovPay
        if "govpay_certs.tar.gz" in names:
            os.makedirs(GOVPAY_CERTS_PATH, exist_ok=True)
            _restore_tar(zf.read("govpay_certs.tar.gz"), GOVPAY_CERTS_PATH)
            restored.append("govpay_certs")

        # Ripristina immagini ente
        if "images.tar.gz" in names:
            os.makedirs(IMAGES_PATH, exist_ok=True)
            _restore_tar(zf.read("images.tar.gz"), IMAGES_PATH)
            restored.append("images")

    return {"restored": restored, "settings": settings}


# -----------------------------------------------------------------------
# Helpers privati
# -----------------------------------------------------------------------

def _mysqldump(host: str, user: str, password: str, dbname: str) -> bytes:
    """Esegue mysqldump e ritorna i dati gzippati."""
    cmd = [
        "mysqldump",
        f"--host={host}",
        f"--user={user}",
        f"--password={password}",
        "--single-transaction",
        "--routines",
        "--triggers",
        dbname,
    ]
    result = subprocess.run(cmd, capture_output=True, timeout=300)
    if result.returncode != 0:
        raise RuntimeError(f"mysqldump failed: {result.stderr.decode()}")
    return gzip.compress(result.stdout)


def _mysql_restore(host: str, user: str, password: str, dbname: str, sql_bytes: bytes) -> None:
    """Ripristina un dump SQL nel database."""
    cmd = [
        "mysql",
        f"--host={host}",
        f"--user={user}",
        f"--password={password}",
        dbname,
    ]
    result = subprocess.run(cmd, input=sql_bytes, capture_output=True, timeout=300)
    if result.returncode != 0:
        raise RuntimeError(f"mysql restore failed: {result.stderr.decode()}")


def _tar_directory(path: str) -> bytes:
    """Crea un archivio tar.gz di una directory e ritorna i bytes."""
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        tar.add(path, arcname=os.path.basename(path))
    return buf.getvalue()


def _restore_tar(tar_bytes: bytes, dest_path: str) -> None:
    """Estrae un tar.gz nella directory di destinazione."""
    buf = io.BytesIO(tar_bytes)
    with tarfile.open(fileobj=buf, mode="r:gz") as tar:
        tar.extractall(path=os.path.dirname(dest_path))
