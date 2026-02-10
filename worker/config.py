"""
Worker configuration loaded from environment variables / .env file.
"""

import os
from pathlib import Path
from dotenv import load_dotenv

load_dotenv(Path(__file__).parent / ".env")

API_BASE_URL = os.environ["API_BASE_URL"]
WORKER_API_KEY = os.environ["WORKER_API_KEY"]

UCLOUD_WEBDAV_URL = os.environ["UCLOUD_WEBDAV_URL"]
UCLOUD_SHARE_TOKEN = os.environ["UCLOUD_SHARE_TOKEN"]
UCLOUD_BASE_PATH = os.environ.get("UCLOUD_BASE_PATH", "/crowdsky")

WORKER_ID = os.environ.get("WORKER_ID", "worker-01")
POLL_INTERVAL = int(os.environ.get("POLL_INTERVAL", "30"))
WORK_DIR = Path(os.environ.get("WORK_DIR", "./tmp"))
