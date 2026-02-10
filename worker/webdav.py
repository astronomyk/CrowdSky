"""
WebDAV helpers for downloading/uploading/deleting files on u:cloud.
"""

from pathlib import Path
from typing import List
import requests
from . import config


def _auth():
    return (config.UCLOUD_SHARE_TOKEN, "")


def download_file(remote_path: str, local_path: Path) -> None:
    """Download a file from u:cloud to a local path."""
    url = f"{config.UCLOUD_WEBDAV_URL}/{remote_path.lstrip('/')}"
    local_path.parent.mkdir(parents=True, exist_ok=True)

    resp = requests.get(url, auth=_auth(), stream=True, timeout=300)
    resp.raise_for_status()

    with open(local_path, "wb") as f:
        for chunk in resp.iter_content(chunk_size=65536):
            f.write(chunk)


def upload_file(local_path: Path, remote_path: str) -> None:
    """Upload a local file to u:cloud."""
    url = f"{config.UCLOUD_WEBDAV_URL}/{remote_path.lstrip('/')}"
    with open(local_path, "rb") as f:
        resp = requests.put(url, data=f, auth=_auth(), timeout=300)
    resp.raise_for_status()


def mkcol(remote_path: str) -> None:
    """Create directory on u:cloud (recursive, ignores 'already exists')."""
    parts = [p for p in remote_path.split("/") if p]
    current = ""
    for part in parts:
        current += f"/{part}"
        url = f"{config.UCLOUD_WEBDAV_URL}{current}"
        resp = requests.request("MKCOL", url, auth=_auth(), timeout=30)
        if resp.status_code not in (201, 405):
            resp.raise_for_status()


def delete_files(remote_paths: List[str]) -> None:
    """Delete multiple files from u:cloud."""
    for path in remote_paths:
        url = f"{config.UCLOUD_WEBDAV_URL}/{path.lstrip('/')}"
        resp = requests.delete(url, auth=_auth(), timeout=30)
        # 204 = deleted, 404 = already gone — both OK
        if resp.status_code not in (200, 204, 404):
            resp.raise_for_status()
