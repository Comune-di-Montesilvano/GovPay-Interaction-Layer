#!/usr/bin/env python3
"""
metadata-builder internal HTTP server.
Accepts POST /run/<command>[?force=1] (authenticated via Bearer MASTER_TOKEN).
Dispatches to /builder/entrypoint.sh <command> and returns JSON output.
"""
import http.server
import json
import os
import subprocess
import sys
import threading
from urllib.parse import urlparse, parse_qs

MASTER_TOKEN = os.environ.get("MASTER_TOKEN", "")
PORT = int(os.environ.get("METADATA_BUILDER_PORT", "8081"))

ALLOWED_COMMANDS = {
    "export-agid",
    "export-cieoidc",
    "status",
    "setup-spid",
    "setup-cieoidc",
    "renew-spid",
    "renew-cieoidc",
}


class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print(f"[server] {fmt % args}", file=sys.stderr, flush=True)

    def _respond(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        try:
            self.wfile.write(data)
        except BrokenPipeError:
            # Il client puo chiudere la connessione prima della risposta finale.
            print("[server] Client disconnected before response write", file=sys.stderr, flush=True)

    @staticmethod
    def _drain_stream(pipe, collector, label):
        try:
            for line in iter(pipe.readline, ""):
                collector.append(line)
                print(line, end="", file=sys.stderr, flush=True)
        finally:
            pipe.close()

    def _auth_ok(self):
        auth = self.headers.get("Authorization", "")
        if not MASTER_TOKEN:
            self._respond(503, {"error": "MASTER_TOKEN non configurato sul server"})
            return False
        if auth != f"Bearer {MASTER_TOKEN}":
            self._respond(401, {"error": "Token non valido"})
            return False
        return True

    def do_GET(self):
        if self.path in ("/health", "/"):
            self._respond(200, {"status": "ok"})
        else:
            self._respond(404, {"error": "Not found"})

    def do_POST(self):
        if not self._auth_ok():
            return

        parsed = urlparse(self.path)
        parts = parsed.path.strip("/").split("/")
        qparams = parse_qs(parsed.query)

        if len(parts) != 2 or parts[0] != "run":
            self._respond(404, {"error": "Endpoint non trovato. Usa POST /run/<command>"})
            return

        cmd = parts[1]
        if cmd not in ALLOWED_COMMANDS:
            self._respond(400, {"error": f"Comando non consentito: {cmd}"})
            return

        extra_env = os.environ.copy()
        if qparams.get("force", ["0"])[0] == "1":
            extra_env["FORCE"] = "1"

        print(
            f"[server] Esecuzione: {cmd} (force={extra_env.get('FORCE', '0')})",
            file=sys.stderr,
            flush=True,
        )
        timeout_seconds = int(os.environ.get("METADATA_BUILDER_COMMAND_TIMEOUT", "600"))
        proc = subprocess.Popen(
            ["/builder/entrypoint.sh", cmd],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            bufsize=1,
            env=extra_env,
        )
        out_lines = []
        err_lines = []
        t_out = threading.Thread(target=self._drain_stream, args=(proc.stdout, out_lines, "stdout"), daemon=True)
        t_err = threading.Thread(target=self._drain_stream, args=(proc.stderr, err_lines, "stderr"), daemon=True)
        t_out.start()
        t_err.start()

        try:
            rc = proc.wait(timeout=timeout_seconds)
        except subprocess.TimeoutExpired:
            proc.kill()
            t_out.join(timeout=2)
            t_err.join(timeout=2)
            self._respond(504, {"success": False, "command": cmd, "error": f"Timeout ({timeout_seconds}s)"})
            return

        t_out.join(timeout=2)
        t_err.join(timeout=2)
        stdout = "".join(out_lines)
        stderr = "".join(err_lines)
        ok = rc == 0
        self._respond(
            200 if ok else 500,
            {
                "success": ok,
                "command": cmd,
                "exit_code": rc,
                "stdout": stdout,
                "stderr": stderr,
            },
        )


if __name__ == "__main__":
    print(f"[server] metadata-builder server in ascolto su :{PORT}", flush=True)
    httpd = http.server.HTTPServer(("0.0.0.0", PORT), Handler)
    httpd.serve_forever()
