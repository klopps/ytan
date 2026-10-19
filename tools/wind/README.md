# Wind job

Fetches the newest complete runs of the DWD's ICON-D2 (0.02 degrees, +48 h)
and ICON-EU (0.0625 degrees, up to +120 h) 10 m wind from opendata.dwd.de,
cuts the regions out and writes small fixed-grid blocks for the wind layer
(`public/js/wind.js`, served by `WindController`). Background, measurements
and decisions: `windkarte.md` in the repo root.

```
wind_job.py               cron entry point (lock, logging)
ytanwind/grib2.py         GRIB2 reader (simple packing 5.0, CCSDS 5.42, bitmap) - no eccodes
ytanwind/dwd.py           file names, run listings ("is this run complete?"), download
ytanwind/blocks.py        levels L0/L1/L2, sampling, region mask, uint8 encoding
ytanwind/job.py           one run: plan, hours in parallel, manifest, cleanup, status
ytanwind/settings.py      regions + wind settings from storage/radar/config.json
tests/test_wind.py        python -m unittest discover -s tools/wind/tests
```

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

It uses the rain radar's Python environment (`tools/radar/.venv`, see
`tools/radar/README.md`); the only extra package is `imagecodecs` (CCSDS
decoding for ICON-EU), already in `tools/radar/requirements.txt`:

```bash
tools/radar/.venv/bin/python -m pip install --only-binary=:all: -r tools/radar/requirements.txt
tools/radar/.venv/bin/python tools/wind/wind_job.py -v   # first run: ~25 s, ~200 MB download
```

Scheduling: no cron entry of its own. `tools/ytan_jobs.py` (the one cron entry, every
5 minutes, see `tools/radar/README.md`) runs this job after the rain radar job, at the
first tick of every quarter hour (the job only computes when a model run it hasn't
processed is complete, otherwise it ends within a second). Started by hand it runs on
its own:

```
tools/radar/.venv/bin/python tools/wind/wind_job.py -v
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
