"""
Generate PNG thumbnail from a stacked FITS file.
"""

from pathlib import Path
import numpy as np
from PIL import Image
from astropy.io import fits


def generate_thumbnail(
    fits_path: Path,
    output_path: Path,
    max_size: int = 512,
) -> Path:
    """
    Create a PNG thumbnail from a stacked RGB FITS file.

    Args:
        fits_path: Path to stacked FITS file (primary HDU = RGB data).
        output_path: Where to save the PNG.
        max_size: Max dimension in pixels.

    Returns:
        Path to the saved thumbnail.
    """
    with fits.open(fits_path) as hdul:
        data = hdul[0].data  # shape: (3, H, W) for RGB

    if data is None:
        raise ValueError(f"No data in primary HDU of {fits_path}")

    # Handle different shapes
    if data.ndim == 3 and data.shape[0] == 3:
        # (3, H, W) -> (H, W, 3)
        rgb = np.transpose(data, (1, 2, 0))
    elif data.ndim == 3 and data.shape[2] == 3:
        # Already (H, W, 3) — seestarpy outputs this format
        rgb = data
    elif data.ndim == 2:
        # Grayscale -> fake RGB
        rgb = np.stack([data, data, data], axis=-1)
    else:
        raise ValueError(f"Unexpected data shape: {data.shape}")

    # Normalize to 0-255
    vmin, vmax = np.percentile(rgb, [1, 99.5])
    if vmax > vmin:
        rgb = np.clip((rgb - vmin) / (vmax - vmin) * 255, 0, 255).astype(np.uint8)
    else:
        rgb = np.zeros_like(rgb, dtype=np.uint8)

    img = Image.fromarray(rgb)

    # Resize preserving aspect ratio
    w, h = img.size
    scale = max_size / max(w, h)
    if scale < 1:
        img = img.resize((int(w * scale), int(h * scale)), Image.LANCZOS)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    img.save(output_path, "PNG")
    return output_path
