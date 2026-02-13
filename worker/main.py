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
from concurrent.futures import ThreadPoolExecutor

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
    """Poll continuously for jobs using a thread pool."""
    logger.info(
        f"Worker {config.WORKER_ID} starting "
        f"(poll interval: {config.POLL_INTERVAL}s, max workers: {config.MAX_WORKERS})"
    )
    config.WORK_DIR.mkdir(parents=True, exist_ok=True)

    with ThreadPoolExecutor(max_workers=config.MAX_WORKERS) as pool:
        futures = set()
        try:
            while True:
                # Remove completed futures
                done = {f for f in futures if f.done()}
                for f in done:
                    try:
                        f.result()
                    except Exception as e:
                        logger.error(f"Thread error: {e}", exc_info=True)
                futures -= done

                # If pool has capacity, try to claim a job
                if len(futures) < config.MAX_WORKERS:
                    job = get_next_job()
                    if job:
                        logger.info(f"Claimed job {job['job_id']}, submitting to thread pool")
                        futures.add(pool.submit(process_job, job))
                        continue  # immediately try to claim another
                    else:
                        logger.info("No pending jobs.")

                # Sleep: briefly if threads are running, full interval if idle
                time.sleep(config.POLL_INTERVAL if not futures else 2)
        except KeyboardInterrupt:
            logger.info("Shutting down, waiting for running jobs to finish...")
            for f in futures:
                f.cancel()
            # Wait for running (non-cancelled) futures to complete
            for f in futures:
                if not f.cancelled():
                    try:
                        f.result(timeout=300)
                    except Exception:
                        pass
            logger.info("Shutdown complete.")


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
