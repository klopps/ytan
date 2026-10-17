"""Class-index mosaic -> PNG tile pyramid {z}/{x}/{y}.png (Google scheme)."""

from __future__ import annotations

import io
import os

import numpy as np
from PIL import Image

from .mercator import TILE, TileGrid
from .palette import png_palette

_PALETTE = png_palette()


def encode_png(block: np.ndarray) -> bytes:
    img = Image.fromarray(block, mode="P")
    img.putpalette(_PALETTE)
    buf = io.BytesIO()
    # 10 colours fit in 4 bits; index 0 is transparent (tRNS)
    img.save(buf, format="PNG", bits=4, transparency=0, optimize=True)
    return buf.getvalue()


def full_mosaic(grid: TileGrid, window_idx: np.ndarray) -> np.ndarray:
    mosaic = np.zeros(grid.shape, np.uint8)
    r0, r1, c0, c1 = grid.window
    mosaic[r0:r1, c0:c1] = window_idx
    return mosaic


def write_pyramid(grid: TileGrid, window_idx: np.ndarray, out_dir: str) -> int:
    """Write every non-empty tile from zmax down to zmin. Returns the tile count.

    A lower zoom keeps the MAXIMUM class of each 2x2 block, so a small heavy
    shower doesn't vanish when zooming out.
    """
    arr = full_mosaic(grid, window_idx)
    tx0, _, ty0, _ = grid.tile_range
    count = 0
    for z in range(grid.zmax, grid.zmin - 1, -1):
        scale = 2 ** (z - grid.zmin)
        h, w = arr.shape
        for ty in range(h // TILE):
            band = arr[ty * TILE:(ty + 1) * TILE]
            if not band.any():
                continue
            for tx in range(w // TILE):
                block = band[:, tx * TILE:(tx + 1) * TILE]
                if not block.any():
                    continue
                path = os.path.join(out_dir, str(z), str(tx0 * scale + tx))
                os.makedirs(path, exist_ok=True)
                with open(os.path.join(path, f"{ty0 * scale + ty}.png"), "wb") as f:
                    f.write(encode_png(np.ascontiguousarray(block)))
                count += 1
        if z > grid.zmin:
            arr = arr.reshape(h // 2, 2, w // 2, 2).max(axis=(1, 3))
    return count
