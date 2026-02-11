"""
HTTP client for the CrowdSky PHP worker API.
"""

from typing import Optional
import requests
from . import config


def _headers() -> dict:
    return {"Authorization": f"Bearer {config.WORKER_API_KEY}"}


def get_next_job() -> Optional[dict]:
    """Claim the next pending stacking job. Returns job dict or None."""
    resp = requests.get(
        f"{config.API_BASE_URL}/next_job.php",
        headers=_headers(),
        params={"worker_id": config.WORKER_ID},
        timeout=30,
    )
    if resp.status_code == 204:
        return None
    resp.raise_for_status()
    return resp.json()


def get_job_files(job_id: int) -> dict:
    """Get list of raw file paths for a stacking job."""
    resp = requests.get(
        f"{config.API_BASE_URL}/job_files.php",
        headers=_headers(),
        params={"job_id": job_id},
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()


def complete_job(job_id: int, metadata: dict) -> dict:
    """Mark a job as completed with stack metadata."""
    payload = {"job_id": job_id, **metadata}
    resp = requests.post(
        f"{config.API_BASE_URL}/complete_job.php",
        headers=_headers(),
        json=payload,
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()


def fail_job(job_id: int, error_message: str) -> dict:
    """Mark a job as failed."""
    resp = requests.post(
        f"{config.API_BASE_URL}/fail_job.php",
        headers=_headers(),
        json={"job_id": job_id, "error_message": error_message},
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()


def download_raw_file(file_id: int, local_path: "Path") -> None:
    """Download a raw FITS file from the PHP webspace via API."""
    from pathlib import Path

    local_path = Path(local_path)
    local_path.parent.mkdir(parents=True, exist_ok=True)

    resp = requests.get(
        f"{config.API_BASE_URL}/download_raw.php",
        headers=_headers(),
        params={"file_id": file_id},
        stream=True,
        timeout=300,
    )
    resp.raise_for_status()

    with open(local_path, "wb") as f:
        for chunk in resp.iter_content(chunk_size=65536):
            f.write(chunk)
