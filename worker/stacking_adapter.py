"""
Adapter wrapping seestarpy's FrameCollection for CrowdSky worker.
"""

from pathlib import Path
from typing import List, Optional
from dataclasses import dataclass

from seestarpy.stacking.stacking import FrameCollection


@dataclass
class StackResult:
    output_path: Path
    n_frames_input: int
    n_aligned: int
    n_stars_detected: Optional[int]
    total_exptime: Optional[float]
    date_obs_start: Optional[str]
    date_obs_end: Optional[str]
    ra_deg: Optional[float]
    dec_deg: Optional[float]


def stack_files(
    fits_paths: List[Path],
    output_path: Path,
    method: str = "mean",
    sigma_clip: float = 3.0,
) -> StackResult:
    """
    Stack a list of FITS files using seestarpy and save the result.

    Args:
        fits_paths: List of local paths to raw .fit files.
        output_path: Where to write the stacked FITS output.
        method: Stacking method ('mean' or 'median').
        sigma_clip: Sigma for outlier rejection.

    Returns:
        StackResult with metadata about the stack.
    """
    fc = FrameCollection(fits_paths)
    fc.process(method=method, sigma_clip=sigma_clip, detect_stars=True)
    fc.save(output_path)

    # Extract metadata from frames
    exptimes = []
    date_obs_values = []
    ra_values = []
    dec_values = []

    for frame in fc.frames:
        hdr = frame.hdu.header if hasattr(frame, "hdu") and frame.hdu else None
        if hdr is None:
            continue
        if "EXPTIME" in hdr:
            exptimes.append(float(hdr["EXPTIME"]))
        if "DATE-OBS" in hdr:
            date_obs_values.append(str(hdr["DATE-OBS"]))
        if "RA" in hdr:
            ra_values.append(float(hdr["RA"]))
        if "DEC" in hdr:
            dec_values.append(float(hdr["DEC"]))

    total_exptime = sum(exptimes) if exptimes else None
    date_obs_start = min(date_obs_values) if date_obs_values else None
    date_obs_end = max(date_obs_values) if date_obs_values else None
    ra_deg = sum(ra_values) / len(ra_values) if ra_values else None
    dec_deg = sum(dec_values) / len(dec_values) if dec_values else None

    n_stars = None
    if hasattr(fc, "_stars_table") and fc._stars_table is not None:
        n_stars = len(fc._stars_table)

    return StackResult(
        output_path=output_path,
        n_frames_input=fc.n_frames,
        n_aligned=fc.n_aligned,
        n_stars_detected=n_stars,
        total_exptime=total_exptime,
        date_obs_start=date_obs_start,
        date_obs_end=date_obs_end,
        ra_deg=ra_deg,
        dec_deg=dec_deg,
    )
