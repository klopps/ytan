# YTAN's scheduled jobs

Everything in `tools/` that runs on a schedule shares ONE Python environment
(`tools/.venv`) and ONE cron entry (`tools/ytan_jobs.py`):

```
ytan_jobs.py         the dispatcher: the only cron entry, runs the due jobs one after the other
ytan_worker.py       the YTAN worker (--worker-id NAME): runs the jobs the priority lists of Admin > Workers give it, on an external machine
ytanjob.py           shared job helpers (lock, atomic JSON)
ytanlog.py           shared log helpers (time stamps, rotation)
requirements.txt     the packages of ALL jobs below
radar/               rain radar job       - see radar/README.md
wind/                wind job             - see wind/README.md
tests/               python -m unittest discover -s tools/tests
```

`tools/water/` (the water distance index, built on a developer machine only) is
not a scheduled job and has its own venv - see `water/README.md`.

## Python environment (`tools/.venv`)

The production host has Python 3.13 but no pip, no `ensurepip` and no C
compiler, and `/tmp` is `noexec` - hence a venv without pip, bootstrapped
with `get-pip.py`, and only packages with binary wheels (no pysteps):

```bash
cd /path/to/ytan
python3 -m venv --without-pip tools/.venv
curl -sSo tools/.venv/get-pip.py https://bootstrap.pypa.io/get-pip.py
tools/.venv/bin/python tools/.venv/get-pip.py
tools/.venv/bin/python -m pip install --only-binary=:all: -r tools/requirements.txt
```

Locally (Windows): `python -m venv tools/.venv` and
`tools/.venv/Scripts/python -m pip install -r tools/requirements.txt`.

The venv can be deleted and rebuilt with these commands at any time (it need not
be backed up). `requirements.txt` only gives minimum versions, so a rebuilt venv
gets the current packages; a new job's packages go into that one file.

Until 2026-11-15 the venv and `requirements.txt` lived in `tools/radar/`. An
existing `tools/radar/.venv` can simply be moved (`mv tools/radar/.venv
tools/.venv`): the jobs call `.venv/bin/python` directly, which finds its
environment from its own location (only pip's own launcher scripts keep the old
path - use `python -m pip`).

The deploy scripts upload `tools/` but not `tools/.venv`.

## Cron

Every 5 minutes - ONE entry for all of YTAN's jobs:

```
*/5 * * * * /path/to/ytan/tools/.venv/bin/python /path/to/ytan/tools/ytan_jobs.py >> /path/to/ytan/storage/logs/console.log 2>&1
```

A new job is one more `JobSpec` line in `ytan_jobs.py`'s `JOBS`, never another
crontab entry.

## Logs

The jobs write their logs themselves (`tools/ytanlog.py`: `storage/logs/jobs.log`,
`radar.log`, `wind.log`; date and time in front of every line, the oldest 1000
lines moved to `NAME.N.log` once a file has more than 2000). `console.log` only
catches what goes wrong before that, e.g. a missing package. An old cron line
that still redirects into `radar.log` does no harm (nothing is printed when the
output is not a terminal).

## Scheduling

`tools/ytan_jobs.py` is the only cron entry. Each tick takes `storage/.jobs.lock`
(a tick that finds it taken - the previous one still working, or a job started by
hand - just ends) and runs the due jobs one after the other, each in its own
process: the rain radar on every tick, the wind job once per quarter-hour slot
(decided from `storage/wind/status.json`), the daily cleanups once per UTC day. A
failing job does not stop the next one. The single jobs take the same lock
themselves when started by hand or from /admin/radar, unless
`YTAN_JOBS_LOCK_HELD=1` says the dispatcher holds it - so they never run together
(the radar needs up to ~1 GB). Tests: `python -m unittest discover -s tools/tests`.
