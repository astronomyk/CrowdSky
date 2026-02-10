"""
Main job processing logic: download raws, stack, upload result, clean up.
"""

import logging
import shutil
from pathlib import Path

from . import config
from .api_client import get_job_files, complete_job, fail_job
from .webdav import download_file, upload_file, mkcol, delete_files
from .stacking_adapter import stack_files
from .thumbnail import generate_thumbnail

logger = logging.getLogger(__name__)


def process_job(job: dict) -> None:
    """
    Process a single stacking job end-to-end.

    1. Fetch file list from API
    2. Download raw FITS from u:cloud
    3. Stack with seestarpy
    4. Upload stacked FITS + thumbnail to u:cloud
    5. Report completion to API
    6. Delete raw files from u:cloud
    """
    job_id = job["job_id"]
    user_id = job["user_id"]
    chunk_key = job["chunk_key"]
    object_name = job.get("object_name") or "unknown"

    work_dir = config.WORK_DIR / f"job_{job_id}"
    raws_dir = work_dir / "raws"
    raws_dir.mkdir(parents=True, exist_ok=True)

    try:
        # 1. Get file list
        logger.info(f"Job {job_id}: fetching file list")
        file_info = get_job_files(job_id)
        files = file_info["files"]

        if not files:
            fail_job(job_id, "No raw files found for this job.")
            return

        # 2. Download raws
        logger.info(f"Job {job_id}: downloading {len(files)} raw files")
        local_paths = []
        remote_paths = []
        for f in files:
            local_path = raws_dir / f["filename"]
            download_file(f["ucloud_path"], local_path)
            local_paths.append(local_path)
            remote_paths.append(f["ucloud_path"])

        # 3. Stack
        logger.info(f"Job {job_id}: stacking {len(local_paths)} frames")
        stack_output = work_dir / f"stack_{chunk_key}_{job_id}.fits"
        result = stack_files(local_paths, stack_output)

        # 4. Generate thumbnail
        thumb_output = work_dir / f"stack_{chunk_key}_{job_id}_thumb.png"
        try:
            generate_thumbnail(stack_output, thumb_output)
        except Exception as e:
            logger.warning(f"Job {job_id}: thumbnail generation failed: {e}")
            thumb_output = None

        # 5. Upload to u:cloud
        safe_object = object_name.replace("/", "_").replace(" ", "_")
        stack_remote_dir = f"{config.UCLOUD_BASE_PATH}/stacks/user_{user_id}/{safe_object}"
        mkcol(stack_remote_dir)

        stack_remote_path = f"{stack_remote_dir}/stack_{chunk_key}_{job_id}.fits"
        logger.info(f"Job {job_id}: uploading stack to {stack_remote_path}")
        upload_file(stack_output, stack_remote_path)

        thumb_remote_path = None
        if thumb_output and thumb_output.exists():
            thumb_remote_path = f"{stack_remote_dir}/stack_{chunk_key}_{job_id}_thumb.png"
            upload_file(thumb_output, thumb_remote_path)

        # 6. Report completion
        metadata = {
            "ucloud_path": stack_remote_path,
            "thumbnail_path": thumb_remote_path,
            "n_frames_input": result.n_frames_input,
            "n_frames_aligned": result.n_aligned,
            "total_exptime": result.total_exptime,
            "date_obs_start": result.date_obs_start,
            "date_obs_end": result.date_obs_end,
            "ra_deg": result.ra_deg,
            "dec_deg": result.dec_deg,
            "file_size_bytes": stack_output.stat().st_size,
            "n_stars_detected": result.n_stars_detected,
            "raws_deleted": True,
        }
        complete_job(job_id, metadata)
        logger.info(f"Job {job_id}: completed ({result.n_aligned}/{result.n_frames_input} aligned)")

        # 7. Delete raws from u:cloud
        logger.info(f"Job {job_id}: cleaning up {len(remote_paths)} raw files")
        delete_files(remote_paths)

    except Exception as e:
        logger.error(f"Job {job_id}: failed — {e}", exc_info=True)
        try:
            fail_job(job_id, str(e)[:500])
        except Exception:
            logger.error(f"Job {job_id}: could not report failure to API")

    finally:
        # Clean up local work directory
        if work_dir.exists():
            shutil.rmtree(work_dir, ignore_errors=True)
