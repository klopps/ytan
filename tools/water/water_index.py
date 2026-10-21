"""The water distance index file (storage/water/water-index.bin).

One binary file, little-endian, read by PHP's Ytan\\Service\\Water\\WaterIndex
(format described in docs/water-index.md - keep both in step):

    header (64 bytes)
        8s   magic b"YTANWATR"
        I    format version (1)
        I    cell size in microdegrees (latitude and longitude alike)
        i    first cell row    (floor(lat_microdeg / cell))
        i    first cell column (floor(lng_microdeg / cell))
        I    rows
        I    columns
        I    kinds (3: sea, river, lake - in this order)
        I    total segments
        24x  reserved (64 bytes in all)
    directory  kinds * rows * columns entries of (I start, I count)
               index = (kind * rows + row) * columns + column,
               start/count in segments
    segments   16 bytes each: i lat1, i lng1, i lat2, i lng2 (microdegrees)

A segment is stored in every cell its bounding box touches, so the cells
around a position hold every segment that can be nearest within them.
"""

from __future__ import annotations

import struct

import numpy as np

MAGIC = b"YTANWATR"
FORMAT_VERSION = 1
HEADER = struct.Struct("<8sIIiiIIII24x")  # 64 bytes
KINDS = ("sea", "river", "lake")
MICRO = 1_000_000


def cell_ranges(segments: np.ndarray, cell: int) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    """Per segment (N x 4 int microdegrees) the first/last cell row and column of its bounding box."""
    lat_min = np.minimum(segments[:, 0], segments[:, 2])
    lat_max = np.maximum(segments[:, 0], segments[:, 2])
    lng_min = np.minimum(segments[:, 1], segments[:, 3])
    lng_max = np.maximum(segments[:, 1], segments[:, 3])
    return (np.floor_divide(lat_min, cell), np.floor_divide(lat_max, cell),
            np.floor_divide(lng_min, cell), np.floor_divide(lng_max, cell))


def write(path: str, segments_by_kind: dict[str, np.ndarray], cell_degrees: float = 0.05) -> dict:
    """Writes the index; segments_by_kind maps each of KINDS to an N x 4 float array (lat1, lng1, lat2, lng2)."""
    cell = int(round(cell_degrees * MICRO))
    micro = {k: np.round(np.asarray(segments_by_kind.get(k, np.zeros((0, 4))), dtype=np.float64).reshape(-1, 4) * MICRO).astype(np.int64)
             for k in KINDS}

    ranges = {k: cell_ranges(micro[k], cell) for k in KINDS}
    non_empty = [k for k in KINDS if len(micro[k])]
    if non_empty:
        row0 = int(min(ranges[k][0].min() for k in non_empty))
        row1 = int(max(ranges[k][1].max() for k in non_empty))
        col0 = int(min(ranges[k][2].min() for k in non_empty))
        col1 = int(max(ranges[k][3].max() for k in non_empty))
    else:
        row0 = row1 = col0 = col1 = 0
    rows = row1 - row0 + 1
    cols = col1 - col0 + 1

    # (cell index, segment) pairs for every cell a segment's box touches.
    cell_ids = []
    seg_rows = []
    for k_index, k in enumerate(KINDS):
        r0, r1, c0, c1 = ranges[k]
        if not len(micro[k]):
            continue
        heights = (r1 - r0 + 1)
        widths = (c1 - c0 + 1)
        counts = heights * widths
        seg_index = np.repeat(np.arange(len(counts)), counts)
        # Position inside each segment's own box, enumerated row-major.
        offsets = np.arange(counts.sum()) - np.repeat(np.cumsum(counts) - counts, counts)
        w = np.repeat(widths, counts)
        rr = np.repeat(r0, counts) + offsets // w
        cc = np.repeat(c0, counts) + offsets % w
        cell_ids.append((k_index * rows + (rr - row0)) * cols + (cc - col0))
        seg_rows.append(micro[k][seg_index])

    if cell_ids:
        all_cells = np.concatenate(cell_ids)
        all_segments = np.concatenate(seg_rows)
        order = np.argsort(all_cells, kind="stable")
        all_cells = all_cells[order]
        all_segments = all_segments[order]
    else:
        all_cells = np.zeros(0, dtype=np.int64)
        all_segments = np.zeros((0, 4), dtype=np.int64)

    entries = len(KINDS) * rows * cols
    counts = np.bincount(all_cells, minlength=entries).astype(np.uint32)
    starts = (np.cumsum(counts, dtype=np.uint64) - counts).astype(np.uint32)
    directory = np.empty(entries * 2, dtype="<u4")
    directory[0::2] = starts
    directory[1::2] = counts

    with open(path, "wb") as out:
        out.write(HEADER.pack(MAGIC, FORMAT_VERSION, cell, row0, col0, rows, cols, len(KINDS), len(all_segments)))
        out.write(directory.tobytes())
        out.write(all_segments.astype("<i4").tobytes())

    return {
        "cell_degrees": cell / MICRO,
        "rows": rows,
        "columns": cols,
        "segments": {k: int(len(micro[k])) for k in KINDS},
        "stored_segments": int(len(all_segments)),
    }


def read_cell(path: str, kind: str, row: int, col: int) -> np.ndarray:
    """The segments (N x 4 float degrees) of one cell - for tests and checks."""
    with open(path, "rb") as f:
        magic, version, cell, row0, col0, rows, cols, kinds, _total = HEADER.unpack(f.read(HEADER.size))
        assert magic == MAGIC and version == FORMAT_VERSION
        r, c = row - row0, col - col0
        if not (0 <= r < rows and 0 <= c < cols):
            return np.zeros((0, 4))
        entry = (KINDS.index(kind) * rows + r) * cols + c
        f.seek(HEADER.size + entry * 8)
        start, count = struct.unpack("<II", f.read(8))
        f.seek(HEADER.size + kinds * rows * cols * 8 + start * 16)
        data = np.frombuffer(f.read(count * 16), dtype="<i4").reshape(-1, 4)
        return data.astype(np.float64) / MICRO
