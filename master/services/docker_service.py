"""
Wrapper attorno al Docker SDK Python per operazioni sui container GIL.
Usa label com.docker.compose.project per scoprire i container del progetto.
"""
import os
import subprocess
import docker
from docker.errors import DockerException

PROJECT_DIR = os.getenv("PROJECT_DIR", "/project")
COMPOSE_PROJECT_NAME = os.getenv("COMPOSE_PROJECT_NAME", "govpay-interaction-layer")


def _client() -> docker.DockerClient:
    return docker.from_env()


def get_project_containers() -> list[dict]:
    """Ritorna tutti i container del progetto GIL con nome, stato e immagine."""
    try:
        client = _client()
        containers = client.containers.list(
            all=True,
            filters={"label": f"com.docker.compose.project={COMPOSE_PROJECT_NAME}"}
        )
        return [
            {
                "name": c.name,
                "status": c.status,
                "image": c.image.tags[0] if c.image.tags else c.image.short_id,
            }
            for c in containers
        ]
    except DockerException as e:
        raise RuntimeError(f"Docker SDK error: {e}") from e


def restart_services(service_names: list[str]) -> dict:
    """Riavvia i container corrispondenti ai service_names del compose."""
    results = {}
    try:
        client = _client()
        for service in service_names:
            containers = client.containers.list(
                all=True,
                filters={
                    "label": [
                        f"com.docker.compose.project={COMPOSE_PROJECT_NAME}",
                        f"com.docker.compose.service={service}",
                    ]
                }
            )
            if not containers:
                results[service] = "not_found"
                continue
            for c in containers:
                c.restart(timeout=30)
            results[service] = "restarted"
    except DockerException as e:
        raise RuntimeError(f"Docker restart error: {e}") from e
    return results


def recreate_services(service_names: list[str]) -> str:
    """
    Riavvia i servizi con --force-recreate, ricaricando env_file dal compose.
    Necessario quando si aggiornano file come ./runtime/.auth-proxy.env.
    """
    return _compose_run(["up", "-d", "--force-recreate"] + service_names)


def start_profile(profile: str) -> str:
    """Avvia tutti i servizi del profilo compose specificato."""
    return _compose_run(["--profile", profile, "up", "-d", "--no-recreate"])


def stop_profile(profile: str) -> str:
    """Ferma tutti i servizi del profilo compose specificato."""
    return _compose_run(["--profile", profile, "stop"])


def _compose_run(args: list[str]) -> str:
    """Esegue docker compose dalla directory di progetto."""
    compose_file = os.path.join(PROJECT_DIR, "docker-compose.yml")
    cmd = [
        "docker", "compose",
        "-f", compose_file,
        "--project-name", COMPOSE_PROJECT_NAME,
        "--project-directory", PROJECT_DIR,
    ] + args
    try:
        result = subprocess.run(
            cmd,
            cwd=PROJECT_DIR,
            capture_output=True,
            text=True,
            timeout=120,
        )
        if result.returncode != 0:
            raise RuntimeError(
                f"docker compose {' '.join(args)} failed (rc={result.returncode}):\n"
                f"stdout: {result.stdout}\nstderr: {result.stderr}"
            )
        return result.stdout + result.stderr
    except subprocess.TimeoutExpired as e:
        raise RuntimeError(f"docker compose timeout: {e}") from e
