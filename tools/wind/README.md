# Wind job

Fetches the newest complete runs of the DWD's ICON-D2 (0.02 degrees, +48 h)
and ICON-EU (0.0625 degrees, up to +120 h) 10 m wind from opendata.dwd.de -
and, for regions outside ICON-EU's coverage (29.5-70.5 N, 23.5 W-62.5 E), ICON
global (13 km, up to +72 h) -
cuts the regions out and writes small fixed-grid blocks for the wind layer
(`public/js/wind.js`, served by `WindController`). Background, measurements
and decisions: `windkarte.md` in the repo root.

```
wind_job.py               cron entry point (lock, logging)
ytanwind/grib2.py         GRIB2 reader (simple packing 5.0, CCSDS 5.42, bitmap) - no eccodes
ytanwind/icon_global.py   ICON global's icosahedral grid -> 0.25 degree field (KD tree, index cached)
ytanwind/meps.py          MET Norway MEPS (2.5 km) over OPeNDAP: Lambert grid -> 0.02 degree fields, wind rotated to east/north
ytanwind/arome.py         Météo-France AROME (2.5 km) over the API portal's WCS: one GRIB2 slice per hour and field, API key
ytanwind/dwd.py           file names, run listings ("is this run complete?"), download
ytanwind/blocks.py        levels L0/L1/L2, sampling, region mask, uint8 encoding
ytanwind/job.py           one run: plan, hours in parallel, manifest, cleanup, status
ytanwind/settings.py      regions + wind settings from storage/radar/config.json
tests/test_wind.py        python -m unittest discover -s tools/wind/tests
```

**ICON global** (`icon_global.py`) is the world-wide fallback, on by default (`wind.world` in
`config.json`, admin checkbox on /admin/wind, migration 049): used only when the area is not completely inside ICON-EU
and only for the first 72 hours (`job.GLOBAL_MAX_HOURS`); where ICON-EU/D2 have a value they win.
The DWD publishes it only on the icosahedral grid (grid template 3.101, 2,949,120 cells, ~3.5 MB per
field - 3x ICON-EU), the cell centres are in the separate CLAT/CLON files. The job builds a nearest-cell
index to a 0.25 degree grid once (5 s, `storage/wind/icon-global-index.npy`) and regrids every field
with it. The model runs every 6 hours. Measured 2026-10-19 (72 h, Australia): 528 MB download,
30 s, 2.5 MB written; Canaries/Morocco (EU + global): 470 MB, 30 s, 5 MB. Compared with ICON-EU over
Germany: correlation 0.98, bias 0.0 m/s, rmse 0.6 m/s (so the u/v are east/north components).
If the global files fail for an hour and ICON-EU has the hour, the hour is shown without the fallback.

**MET Norway MEPS** (`meps.py`, 2026-11-19, CC BY 4.0) is an extra L2 source around Scandinavia, the Baltic and
the North Sea (`meps.AREA`), on by default (`wind.meps`, checkbox on /admin/wind, migration 079). Order at a
point: ICON-D2, MEPS, ICON-EU (global). It is NetCDF behind THREDDS, not GRIB2, so the job asks OPeNDAP
(`thredds.met.no/thredds/dodsC/mepslatest/meps_det_2_5km_YYYYMMDDTHHZ.ncml.dods?...`) for the cut-out of
`x_wind_10m`/`y_wind_10m` (`wind_speed_of_gust`) one hour at a time and reads the DAP2 binary answer itself
(`parse_dods()`, no netCDF/HDF5 library). A run exists in `mepslatest/catalog.xml` ~2.5 h after its run time
(every 3 h, 67 hourly steps); the job takes the newest of the last three whose last step is written
(`latest_complete_run()`). Traps: the arrays come in the DDS's order, not the order asked for, so they are read
by name; the grid is Lambert conformal conic on a sphere (63.3 N twice, 15 E, R 6,371 km) whose x/y axes equal
the projected metres to 0.1 m, so the job projects its own 0.02 degree points; the wind components are along the
grid axes and rotated by n*(lon-15 E) to east/north (against the file's own `wind_direction`: median 0.27 degrees
off); the outer 10 cells (relaxation zone) are not used, and where ICON-D2 runs its area is left out. A MEPS
failure (catalog, outage, a step) only costs resolution: the hour is built from ICON. Measured 2026-11-19 (southern
Norway, 6 h): ~1 MB of OPeNDAP data per hour for the cut-out, whole run 38 MB incl. ICON, 31 s; MEPS against raw
ICON-EU over southern Norway: east component identical on average, correlation 0.75 (terrain detail).

**Météo-France AROME** (`arome.py`, 2026-11-19, Licence Ouverte 2.0) is the third L2 source (order ICON-D2, MEPS, AROME,
ICON-EU/global), over France and its surroundings (`arome.AREA`, 37.5-55.4 N, 12 W-16 E), on by default (`wind.arome`,
checkbox on /admin/wind, migration 080). It needs an API key from `portail-api.meteofrance.fr` (token type "API Key",
chosen lifetime): `AROME_API_KEY` in the environment or the repository's `.env`; on the YTAN worker (Home Assistant) the
add-on option `arome_api_key` (run.sh exports it). Without a key, or with an expired one (HTTP 401), the job goes on
without AROME (one log line). The portal's WCS service `MF-NWP-HIGHRES-AROME-0025-FRANCE-WCS`: a coverage is one
parameter of ONE run (`U_COMPONENT_OF_WIND__SPECIFIC_HEIGHT_LEVEL_ABOVE_GROUND___2026-11-19T09.00.00Z`, V, and the gust
`WIND_SPEED_GUST__...`), a run every 3 hours with 51 hourly steps; `GetCoverage` cuts `lat(a,b)`/`long(a,b)` and
returns GRIB2 on the regular lat/lon grid (simple packing with 11 bits - `grib2.py` reads any bit width since this
source), but only 2D slices: one `time(...)` (no quotes) and `height(10)` per request, a time range is refused - so
one request per hour and field. `GetCapabilities` is 2.9 MB and is never used: a run's existence is `DescribeCoverage`
(404 `NoSuchCoverage` = not there yet), its last step one 185-byte request. The gust starts one hour after the run
(step 0: the mean wind). Rate limit: 100 requests per minute for this account - requests are spaced `MIN_GAP_S` = 0.75 s
apart across all threads, HTTP 429 waits and retries. The grid is plain lat/lon, so u/v are east/north already; the
outer 0.2 degrees of the domain and ICON-D2's area are left out. `status.json`'s `last_processing.traffic` lists bytes
and requests per extra source. Measured 2026-11-19 (Brittany, 6 h with gusts): 26 AROME requests, 1.3 MB, the whole run
23 s; against raw ICON-EU: correlation 0.93, direction median 4.6 degrees off, gusts above the mean wind everywhere.

The area: the rain radar's regions by default, or - `wind.regions` in the same file, set on /admin/wind (up to 10
rectangles anywhere on the globe, the whole world included - measured 570 MB download and 480 MB written for the world at 72 h, 4-5 minutes - migration 050) - the wind layer's own.
The regions are the rain radar's (`/admin/radar`), but the data is cut to the one
rectangle enclosing all of them, grown by 1 degree (`settings.PAD_DEGREES`) - no holes
over the sea between countries; the job-specific settings
(forecast length 24/48/72/120 h, process every 3rd or 6th model run, gusts,
fine ICON-D2 grid, on/off) sit in the `wind` object of the same
`storage/radar/config.json`, written by PHP when an admin saves them (page
/admin/wind). Missing file = DE/DK/SE, 48 h, every 3 hours, no
gusts, fine grid on. Switching the layer off deletes `manifest.json`: no
manifest = the app says "no wind data".

Output in `storage/wind/` (see `job.py`'s docstring): `manifest.json`,
`runs/{runId}/L0/{hhh}.bin.gz`, `runs/{runId}/L1|L2/{hhh}/{bx}_{by}.bin.gz`,
`status.json` (shown on /admin/wind), `.lock`. A run is published by
renaming a finished temp directory; a replaced run stays 30 minutes.

Block format: 129 x 129 points per block (the last row/column is the
neighbour's first), rows south to north, per point u, v [, gust] as bytes
0..254 on -40..40 m/s (gust 0..60), 255 = no data; gzip. L0 is one file for
the whole area (0.25 degrees), L1 blocks are 8 degrees, L2 blocks 2.56
degrees, all on one global grid (origin -90 / -180).

## Setup

It uses the jobs' shared Python environment (`tools/.venv`, see
`tools/README.md`); the only extra package is `imagecodecs` (CCSDS
decoding for ICON-EU), already in `tools/requirements.txt`:

```bash
tools/.venv/bin/python -m pip install --only-binary=:all: -r tools/requirements.txt
tools/.venv/bin/python tools/wind/wind_job.py -v   # first run: ~25 s, ~200 MB download
```

Scheduling: no cron entry of its own. `tools/ytan_jobs.py` (the one cron entry, every
5 minutes, see `tools/README.md`) runs this job after the rain radar job, at the
first tick of every quarter hour (the job only computes when a model run it hasn't
processed is complete, otherwise it ends within a second). Started by hand it runs on
its own:

```
tools/.venv/bin/python tools/wind/wind_job.py -v
```

**It never runs together with the rain radar job**: both hold `storage/.jobs.lock`
while they work - the dispatcher holds it for both, a job started by hand takes it
itself (this job waits up to 6 minutes for the radar to finish, `radar_job.py` skips
its run when this job is working). The radar needs up to ~1 GB and all its processes
for ~2 minutes, the wind job ~200 MB and 4 threads for ~40 s.

"Update now" on /admin/wind starts it with `--force` through
`RadarJobRunner($root, 'wind')`.

Measured 2026-10-18 on a development PC (8 countries: be, de, dk, es, fr, it, nl, se): 194
downloads (211 MB), 24 s, 29 MB written. About 1.7 GB of download per day at
one run every 3 hours; "every 6 hours" halves it.
