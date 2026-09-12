#!/usr/bin/env python3
"""Pull RMS label PDFs and submit them to locally allowlisted OS printer queues."""

from __future__ import annotations

import argparse
import base64
import binascii
import json
import os
import sqlite3
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


MAX_PDF_BYTES = 10 * 1024 * 1024
ALLOWED_DOC_TYPE = "order_label_pdf"
ALLOWED_TARGET = "order_label_printer"


class AgentError(RuntimeError):
    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code


class StateStore:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.connection = sqlite3.connect(path)
        self.connection.execute(
            """
            CREATE TABLE IF NOT EXISTS handled_jobs (
                job_id INTEGER PRIMARY KEY,
                claim_token TEXT NOT NULL,
                state TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )
            """
        )
        self.connection.commit()

    def state(self, job_id: int) -> str | None:
        row = self.connection.execute(
            "SELECT state FROM handled_jobs WHERE job_id = ?", (job_id,)
        ).fetchone()
        return str(row[0]) if row else None

    def mark(self, job_id: int, claim_token: str, state: str) -> None:
        self.connection.execute(
            """
            INSERT INTO handled_jobs (job_id, claim_token, state, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(job_id) DO UPDATE SET
                claim_token = excluded.claim_token,
                state = excluded.state,
                updated_at = excluded.updated_at
            """,
            (job_id, claim_token, state, int(time.time())),
        )
        self.connection.commit()


class RmsClient:
    def __init__(self, base_url: str, token: str, device_id: str, timeout: int = 45):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.device_id = device_id
        self.timeout = timeout

    def pull(self) -> list[dict[str, Any]]:
        response = self._request("GET", "/api/pos/print-jobs/pull?wait_seconds=30&limit=10")
        jobs = response.get("jobs", [])
        if not isinstance(jobs, list):
            raise AgentError("INVALID_RESPONSE", "RMS returned an invalid job list")
        return [job for job in jobs if isinstance(job, dict)]

    def ack(self, job_id: int, claim_token: str, status: str, code: str | None = None, message: str | None = None) -> None:
        body: dict[str, Any] = {"claim_token": claim_token, "status": status}
        if status == "failed":
            body["error_code"] = code or "PRINT_FAILED"
            body["error_message"] = (message or "Print failed")[:500]
        self._request("POST", f"/api/pos/print-jobs/{job_id}/ack", body)

    def _request(self, method: str, path: str, body: dict[str, Any] | None = None) -> dict[str, Any]:
        payload = json.dumps(body).encode("utf-8") if body is not None else None
        request = urllib.request.Request(
            self.base_url + path,
            data=payload,
            method=method,
            headers={
                "Accept": "application/json",
                "Content-Type": "application/json",
                "Authorization": f"Bearer {self.token}",
                "X-Device-Id": self.device_id,
                "User-Agent": "LaylaOrderLabelAgent/1.0",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                decoded = json.loads(response.read().decode("utf-8"))
                return decoded if isinstance(decoded, dict) else {}
        except urllib.error.HTTPError as error:
            raise AgentError("RMS_HTTP_ERROR", f"RMS request failed with HTTP {error.code}") from error
        except (urllib.error.URLError, TimeoutError, json.JSONDecodeError) as error:
            raise AgentError("RMS_UNAVAILABLE", "RMS is unavailable or returned invalid JSON") from error


def validated_pdf(job: dict[str, Any], queues: dict[str, Any]) -> tuple[bytes, str, dict[str, Any]]:
    if job.get("doc_type") != ALLOWED_DOC_TYPE or job.get("target") != ALLOWED_TARGET:
        raise AgentError("UNSUPPORTED_DOCUMENT", "The job document type is not allowlisted")
    metadata = job.get("metadata")
    if not isinstance(metadata, dict):
        raise AgentError("INVALID_METADATA", "The label metadata is missing")
    queue = str(metadata.get("printer_queue", ""))
    if queue not in queues:
        raise AgentError("QUEUE_NOT_ALLOWLISTED", "The requested printer queue is not allowlisted locally")
    try:
        pdf = base64.b64decode(str(job.get("payload_base64", "")), validate=True)
    except (ValueError, binascii.Error) as error:
        raise AgentError("INVALID_PAYLOAD", "The label payload is not valid base64") from error
    if not pdf.startswith(b"%PDF-") or len(pdf) > MAX_PDF_BYTES:
        raise AgentError("INVALID_PDF", "The label payload is not an accepted PDF")
    width = int(metadata.get("width_tenths_mm", 0))
    height = int(metadata.get("height_tenths_mm", 0))
    dpi = int(metadata.get("resolution_dpi", 0))
    if not (100 <= width <= 1200 and 100 <= height <= 3000 and dpi in (203, 300, 600)):
        raise AgentError("INVALID_MEDIA", "The requested label media is outside safe bounds")
    return pdf, queue, metadata


def submit_pdf(pdf: bytes, queue_name: str, queue_config: dict[str, Any]) -> None:
    command = queue_config.get("command")
    if not isinstance(command, list) or not command or not all(isinstance(part, str) for part in command):
        if sys.platform == "win32":
            raise AgentError("LOCAL_CONFIG", "Windows queues require a local PDF print command")
        command = ["lp", "-d", "{printer}", "{file}"]

    with tempfile.NamedTemporaryFile(prefix="layla-label-", suffix=".pdf", delete=False) as handle:
        handle.write(pdf)
        temp_path = handle.name
    try:
        arguments = [part.replace("{printer}", queue_name).replace("{file}", temp_path) for part in command]
        result = subprocess.run(arguments, capture_output=True, text=True, timeout=60, check=False)
        if result.returncode != 0:
            raise AgentError("OS_PRINT_FAILED", f"Operating system print command failed ({result.returncode})")
    except (OSError, subprocess.TimeoutExpired) as error:
        raise AgentError("OS_PRINT_FAILED", "Operating system print command could not complete") from error
    finally:
        try:
            os.unlink(temp_path)
        except OSError:
            pass


def handle_job(client: RmsClient, store: StateStore, queues: dict[str, Any], job: dict[str, Any]) -> str:
    job_id = int(job.get("job_id", 0))
    claim_token = str(job.get("claim_token", ""))
    if job_id <= 0 or not claim_token:
        raise AgentError("INVALID_JOB", "The job identity is invalid")

    prior = store.state(job_id)
    if prior in ("accepted", "acked"):
        client.ack(job_id, claim_token, "printed")
        store.mark(job_id, claim_token, "acked")
        return "printed"
    if prior == "submitting":
        client.ack(job_id, claim_token, "failed", "AMBIGUOUS_LOCAL_STATE", "Manual review required; the previous OS submission result is unknown")
        return "failed"

    pdf, queue_name, _metadata = validated_pdf(job, queues)
    store.mark(job_id, claim_token, "submitting")
    submit_pdf(pdf, queue_name, queues[queue_name])
    store.mark(job_id, claim_token, "accepted")
    client.ack(job_id, claim_token, "printed")
    store.mark(job_id, claim_token, "acked")
    return "printed"


def load_config(path: Path) -> dict[str, Any]:
    config = json.loads(path.read_text(encoding="utf-8"))
    required = ("base_url", "token", "device_id", "state_db", "allowed_queues")
    if not isinstance(config, dict) or any(not config.get(key) for key in required):
        raise AgentError("LOCAL_CONFIG", "Agent configuration is incomplete")
    if not isinstance(config["allowed_queues"], dict):
        raise AgentError("LOCAL_CONFIG", "allowed_queues must be an object")
    return config


def run(config: dict[str, Any], once: bool = False) -> None:
    client = RmsClient(str(config["base_url"]), str(config["token"]), str(config["device_id"]))
    store = StateStore(Path(str(config["state_db"])).expanduser())
    queues = config["allowed_queues"]
    while True:
        try:
            for job in client.pull():
                try:
                    result = handle_job(client, store, queues, job)
                    print(f"job={int(job.get('job_id', 0))} result={result}", flush=True)
                except AgentError as error:
                    job_id = int(job.get("job_id", 0))
                    claim_token = str(job.get("claim_token", ""))
                    print(f"job={job_id} result=failed code={error.code}", file=sys.stderr, flush=True)
                    if job_id > 0 and claim_token:
                        try:
                            client.ack(job_id, claim_token, "failed", error.code, str(error))
                        except AgentError:
                            pass
            if once:
                return
        except AgentError as error:
            print(f"agent result=retry code={error.code}", file=sys.stderr, flush=True)
            if once:
                raise
            time.sleep(5)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", required=True, type=Path)
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()
    try:
        run(load_config(args.config), once=args.once)
        return 0
    except (AgentError, OSError, json.JSONDecodeError) as error:
        print(f"agent result=stopped error={error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
