"""Motor ML provisional (TASK-001).

Solo expone GET /v1/health mientras no existe el motor real (TASK-074, SDD §4.6).
Usa únicamente la biblioteca estándar para no fijar dependencias antes de tiempo.
"""

import json
from http.server import BaseHTTPRequestHandler, HTTPServer


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path != "/v1/health":
            self._send(404, {"detail": "No encontrado"})
            return
        self._send(200, {"status": "ok", "stub": True})

    def _send(self, status: int, body: dict) -> None:
        payload = json.dumps(body).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, format: str, *args: object) -> None:
        return


if __name__ == "__main__":
    HTTPServer(("0.0.0.0", 8000), Handler).serve_forever()
