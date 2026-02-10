"""
CrowdSky Worker entry point.

Usage:
    python -m worker              # daemon mode (polls continuously)
    python -m worker --once       # process one job and exit (for cron)
"""

import argparse
import logging
import time
import sys

from . import config
from .api_client import get_next_job
from .job_processor import process_job

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("crowdsky.worker")


def run_once() -> bool:
    """Poll for one job and process it. Returns True if a job was found."""
    job = get_next_job()
    if job is None:
        logger.info("No pending jobs.")
        return False

    logger.info(f"Claimed job {job['job_id']}: {job.get('object_name', '?')} chunk={job['chunk_key']}")
    process_job(job)
    return True


def run_daemon() -> None:
    """Poll continuously for jobs."""
    logger.info(f"Worker {config.WORKER_ID} starting (poll interval: {config.POLL_INTERVAL}s)")
    config.WORK_DIR.mkdir(parents=True, exist_ok=True)

    while True:
        try:
            found = run_once()
            if not found:
                time.sleep(config.POLL_INTERVAL)
        except KeyboardInterrupt:
            logger.info("Shutting down.")
            break
        except Exception as e:
            logger.error(f"Unexpected error: {e}", exc_info=True)
            time.sleep(config.POLL_INTERVAL)


def main():
    parser = argparse.ArgumentParser(description="CrowdSky stacking worker")
    parser.add_argument("--once", action="store_true", help="Process one job and exit")
    args = parser.parse_args()

    if args.once:
        found = run_once()
        sys.exit(0 if found else 1)
    else:
        run_daemon()


if __name__ == "__main__":
    main()
