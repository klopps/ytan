# Rain radar job

Fetches the EUMETNET OPERA maximum-reflectivity composite (`DBZH`, every
5 minutes, ~7 minutes behind real time, CC BY 4.0) from the Open Radar Data
S3 cache, computes a 2-hour extrapolation nowcast and writes PNG tiles for
the map overlay (`public/js/radar.js`, served by `RadarController`).
Background, measurements and decisions: `wetterradar.md` in the repo root.

```
radar_job.py              cron entry point (lock, logging)
ytanradar/opera.py        download, ODIM HDF5 decoding, grid geometry
ytanradar/reproject.py    LAEA composite -> Web Mercator mosaic (cv2.remap),
                          1.5 km display smoothing (less flicker in the animation)
ytanradar/palette.py      dBZ -> mm/h classes (Marshall-Palmer) and colours
ytanradar/tiles.py        mosaic -> {z}/{x}/{y}.png pyramid, zoom 5-8
ytanradar/nowcast.py      optical flow + semi-Lagrangian extrapolation, CSI
ytanradar/job.py          one run: fetch, tile, forecast, manifest, cleanup, verify
ytanradar/settings.py     regions + forecast length from config.json (admin settings)
tests/test_radar.py       python -m unittest discover -s tools/radar/tests
```

Settings come from `storage/radar/config.json`, written by PHP when an admin
saves them in /admin/radar (`ytanradar/settings.py`): regions, history (5-60
min), forecast (0-120 min), fetch interval (5/10/15 min - cron keeps running
every 5 minutes, the job skips the runs in between) and parallel processes
(1-12). Missing file = DE, DK, SE, 60/60/5 minutes, 4 processes. Changed
regions make the next run rebuild every frame. Each run reports in
`storage/radar/status.json` (outcome, download and processing phase with
start/end/bytes, processes, total size of storage/radar), shown on
/admin/radar.

Output in `storage/radar/` (see `job.py`'s docstring): `manifest.json`,
`frames/{id}/{z}/{x}/{y}.png`, `raw/*.npz` (cropped fields, 80 minutes),
`verify.csv` (nowcast vs. persistence skill per lead time).

## Setup

The production host has Python 3.13 but no pip, no `ensurepip` and no C
compiler, and `/tmp` is `noexec` - hence a venv without pip, bootstrapped
with `get-pip.py`, and only packages with binary wheels (no pysteps):

```bash
cd /path/to/ytan
python3 -m venv --without-pip tools/radar/.venv
curl -sSo tools/radar/.venv/get-pip.py https://bootstrap.pypa.io/get-pip.py
tools/radar/.venv/bin/python tools/radar/.venv/get-pip.py
tools/radar/.venv/bin/python -m pip install --only-binary=:all: -r tools/radar/requirements.txt
tools/radar/.venv/bin/python tools/radar/radar_job.py   # first run: ~2 min (13 observations)
```

Locally (Windows): `python -m venv tools/radar/.venv` and
`tools/radar/.venv/Scripts/python -m pip install -r tools/radar/requirements.txt`.

/admin/radar can also start a run by hand ("Jetzt aktualisieren": `--force`,
ignores the fetch interval; refused while `storage/radar/.lock` is held).

Cron, every 5 minutes (overlapping runs skip themselves via
`storage/radar/.lock`). The number of rendering processes comes from the
admin setting; `--workers N` overrides it for one run (`1` = sequential). On
Linux they share the reprojection lookup via fork, on Windows (spawn) each
builds its own (~5 s, and needs the regions too):

```
*/5 * * * * /path/to/ytan/tools/radar/.venv/bin/python /path/to/ytan/tools/radar/radar_job.py >> /path/to/ytan/storage/logs/radar.log 2>&1
```

The deploy scripts upload `tools/radar/` but neither `.venv` nor
`storage/radar`.

## Numbers (measured 2026-10-17, frontal rain over DK/DE)

- ~480 tiles per frame, ~3 KB each; 37 frames (13 observations + 24
  forecasts) = ~57 MB / 17,500 files, plus up to two older forecast sets
  for 10 minutes after they left the manifest.
- Reprojection lookup ~5 s per run, ~2.7 s per frame locally (mostly PNG
  writing), ~8 s per frame on the production host.
- Full first run (37 frames) on the production host: 324 s sequential,
  110 s with 4 workers; locally 120 s -> 75 s (downloads stay sequential).
- Verification (`verify.csv`, CSI at 1 mm/h): +15 min 0.68 vs. 0.51 for
  persistence, +30 min 0.58 vs. 0.42.
