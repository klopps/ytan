"""Minimal GRIB2 reader for the DWD ICON files (regular lat/lon grid, one field per file).

Why not eccodes: it has no Windows wheel and pygrib has none at all. The
DWD files use only what is handled here, anything else is refused loudly:

  grid template 3.0 (regular lat/lon), scanning mode 0 or 64
  (and 3.101, ICON's icosahedral grid, through decode_unstructured())
  packing 5.0 (simple, ICON-D2) and 5.42 (CCSDS/AEC, ICON-EU), <= 16 bit
  bitmap section 6 (indicator 0 = present, 255 = none)
"""

from __future__ import annotations

import bz2
import struct
from dataclasses import dataclass

import numpy as np


class Grib2Error(ValueError):
    pass


@dataclass
class Field:
    values: np.ndarray  # float32 (rows, cols), row 0 = southernmost; NaN = no data
    lat0: float         # latitude of row 0
    lon0: float         # longitude of column 0, -180..180
    dlat: float
    dlon: float

    @property
    def lat1(self) -> float:
        return self.lat0 + (self.values.shape[0] - 1) * self.dlat

    @property
    def lon1(self) -> float:
        return self.lon0 + (self.values.shape[1] - 1) * self.dlon


def _signed(raw: int) -> int:
    """GRIB sign-and-magnitude (the sign is the top bit)."""
    return -(raw & 0x7FFF) if raw & 0x8000 else raw


def _sections(b: bytes) -> dict[int, bytes]:
    if b[:4] != b"GRIB" or b[7] != 2:
        raise Grib2Error("not a GRIB edition 2 message")
    total = struct.unpack(">Q", b[8:16])[0]
    if total > len(b):
        raise Grib2Error("truncated GRIB message (%d of %d bytes)" % (len(b), total))
    p, out = 16, {}
    while b[p:p + 4] != b"7777":
        if p + 5 > len(b):
            raise Grib2Error("GRIB message without end marker")
        length, num = struct.unpack(">IB", b[p:p + 5])
        if num in out:
            raise Grib2Error("more than one field per message is not supported")
        out[num] = b[p:p + length]
        p += length
    return out


def _unpack(s5: bytes, s7: bytes, max_points: int) -> np.ndarray:
    """Data representation + data section -> float32 values (one per data point)."""
    npts, tpl = struct.unpack(">IH", s5[5:11])
    if npts > max_points:
        raise Grib2Error("more data points than grid points")
    ref = struct.unpack(">f", s5[11:15])[0]
    e = _signed(struct.unpack(">H", s5[15:17])[0])
    d = _signed(struct.unpack(">H", s5[17:19])[0])
    nbits = s5[19]
    data = s7[5:]

    if nbits == 0:
        x = np.zeros(npts, np.float32)
    elif tpl == 0:
        if nbits != 16:
            raise Grib2Error("simple packing with %d bits not supported" % nbits)
        x = np.frombuffer(data, ">u2", npts).astype(np.float32)
    elif tpl == 42:
        if nbits > 16:
            raise Grib2Error("CCSDS packing with %d bits not supported" % nbits)
        import imagecodecs
        flags, block, rsi = s5[21], s5[22], struct.unpack(">H", s5[23:25])[0]
        width = 1 if nbits <= 8 else 2
        # the output buffer must be larger than npts * width or libaec complains
        raw = imagecodecs.aec_decode(data, bitspersample=nbits, flags=flags, blocksize=block,
                                     rsi=rsi, out=npts * width * 2)[:npts * width]
        dtype = (">" if flags & 4 else "<") + ("u1" if width == 1 else "u2")
        x = np.frombuffer(raw, dtype).astype(np.float32)
    else:
        raise Grib2Error("data representation template %d not supported" % tpl)

    values = ((ref + x * np.float32(2.0 ** e)) / np.float32(10.0 ** d)).astype(np.float32)

    return values


def decode(b: bytes) -> Field:
    sec = _sections(b)
    for n in (3, 5, 6, 7):
        if n not in sec:
            raise Grib2Error("section %d missing" % n)
    s3, s5, s6, s7 = sec[3], sec[5], sec[6], sec[7]

    if struct.unpack(">H", s3[12:14])[0] != 0:
        raise Grib2Error("grid template %d, only 3.0 (regular lat/lon) is supported" % struct.unpack(">H", s3[12:14])[0])
    ni, nj = struct.unpack(">II", s3[30:38])
    la1, lo1, _res, la2, lo2, di, dj = struct.unpack(">iiBiiII", s3[46:71])
    scan = s3[71]
    if scan not in (0, 64):
        raise Grib2Error("scanning mode %d not supported" % scan)
    la1, lo1, la2, lo2 = la1 / 1e6, lo1 / 1e6, la2 / 1e6, lo2 / 1e6
    di, dj = di / 1e6, dj / 1e6
    if lo1 > 180:
        lo1 -= 360

    values = _unpack(s5, s7, ni * nj)
    npts = len(values)

    indicator = s6[5]
    if indicator == 255:
        if npts != ni * nj:
            raise Grib2Error("no bitmap but fewer data points than grid points")
        full = values
    elif indicator == 0:
        present = np.unpackbits(np.frombuffer(s6, np.uint8, offset=6))[:ni * nj].astype(bool)
        if int(present.sum()) != npts:
            raise Grib2Error("bitmap does not match the number of data points")
        full = np.full(ni * nj, np.nan, np.float32)
        full[present] = values
    else:
        raise Grib2Error("bitmap indicator %d not supported" % indicator)
    grid = full.reshape(nj, ni)

    if scan == 0:  # first row = north: make row 0 the south
        grid = grid[::-1]
        lat0, dlat = min(la1, la2), dj
    else:
        lat0, dlat = la1, dj
    return Field(np.ascontiguousarray(grid), lat0, lo1, dlat, di)


def decode_bz2(compressed: bytes) -> Field:
    return decode(bz2.decompress(compressed))


def decode_unstructured(b: bytes) -> np.ndarray:
    """Values of a field on ICON's icosahedral grid (grid template 3.101): one
    float32 per cell, no bitmap. Where the cells are is in the CLAT/CLON files."""
    sec = _sections(b)
    for n in (3, 5, 6, 7):
        if n not in sec:
            raise Grib2Error("section %d missing" % n)
    tpl = struct.unpack(">H", sec[3][12:14])[0]
    if tpl != 101:
        raise Grib2Error("grid template %d, only 3.101 (unstructured) is supported here" % tpl)
    if sec[6][5] != 255:
        raise Grib2Error("bitmap on an unstructured grid is not supported")
    npts = struct.unpack(">I", sec[3][6:10])[0]
    values = _unpack(sec[5], sec[7], npts)
    if len(values) != npts:
        raise Grib2Error("no bitmap but fewer data points than grid points")
    return values
