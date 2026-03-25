import os
from fastapi import APIRouter, Depends, HTTPException, UploadFile, File, status
from fastapi.responses import FileResponse
from auth import require_auth
from schemas.requests import RestoreRequest
from schemas.responses import BackupListResponse, BackupListItem, OperationResponse
from services import backup_service, config_service

BACKUP_DIR = os.getenv("BACKUP_DIR", "/backups")

router = APIRouter(prefix="/backup", tags=["backup"])


@router.post("/run", response_model=OperationResponse)
def run_backup(settings_export: dict, _token: str = Depends(require_auth)):
    """
    Crea un backup completo: settings + DB dump + volumi SPID.
    Il corpo della richiesta deve contenere i settings esportati dal PHP.
    """
    config = config_service.read_config()
    db = config.get("db", {})
    try:
        filename = backup_service.create_backup(
            db_host=db.get("host", "db"),
            db_user=db.get("user", "govpay"),
            db_password=db.get("password", ""),
            db_name=db.get("name", "govpay"),
            settings_export=settings_export,
        )
        return OperationResponse(success=True, message=f"Backup creato: {filename}", details={"filename": filename})
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.get("/list", response_model=BackupListResponse)
def list_backups(_token: str = Depends(require_auth)):
    items = backup_service.list_backups()
    return BackupListResponse(backups=[BackupListItem(**i) for i in items])


@router.get("/download/{filename}")
def download_backup(filename: str, _token: str = Depends(require_auth)):
    filepath = os.path.join(BACKUP_DIR, filename)
    if not os.path.isfile(filepath):
        raise HTTPException(status_code=404, detail="Backup non trovato.")
    return FileResponse(filepath, media_type="application/zip", filename=filename)


@router.post("/restore", response_model=OperationResponse)
def restore_backup(body: RestoreRequest, _token: str = Depends(require_auth)):
    """Ripristina da un backup archiviato in /backups/."""
    try:
        result = backup_service.restore_backup(body.filename)
        return OperationResponse(
            success=True,
            message=f"Restore completato. Componenti: {', '.join(result['restored'])}",
            details={"restored": result["restored"]},
        )
    except FileNotFoundError as e:
        raise HTTPException(status_code=404, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))


@router.post("/upload-restore", response_model=OperationResponse)
async def upload_and_restore(file: UploadFile = File(...), _token: str = Depends(require_auth)):
    """Upload di un file .zip di backup e ripristino immediato."""
    if not file.filename.endswith(".zip"):
        raise HTTPException(status_code=400, detail="Solo file .zip sono accettati.")

    dest_path = os.path.join(BACKUP_DIR, file.filename)
    content = await file.read()
    with open(dest_path, "wb") as f:
        f.write(content)

    try:
        result = backup_service.restore_backup(file.filename)
        return OperationResponse(
            success=True,
            message=f"Restore completato. Componenti: {', '.join(result['restored'])}",
            details={"restored": result["restored"]},
        )
    except Exception as e:
        raise HTTPException(status_code=status.HTTP_500_INTERNAL_SERVER_ERROR, detail=str(e))
