"""YTAN rain radar: OPERA composites -> Web Mercator PNG tiles + nowcast."""

import os as _os
import sys as _sys

# tools/ (ytanjob.py, shared by all jobs) - importable however this package was reached
_TOOLS = _os.path.normpath(_os.path.join(_os.path.dirname(_os.path.abspath(__file__)), "..", ".."))
if _TOOLS not in _sys.path:
    _sys.path.append(_TOOLS)
