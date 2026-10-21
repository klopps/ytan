# Water distance index builder

Builds `storage/water/water-index.bin` (format: `docs/water-index.md`) from
Geofabrik's OpenStreetMap extracts of Denmark, Norway, Sweden and Finland.
Runs on dev only; the servers get the finished file with
`bin\publish-water.bat [test]` and never need Python for it.

## Setup (once)

```bash
# Python 3.12, its own venv (osmium has no wheels for every Python version)
python -m venv tools/water/.venv
tools/water/.venv/Scripts/python -m pip install osmium shapely pyproj numpy
```

## Build

```bash
tools/water/.venv/Scripts/python tools/water/build_index.py            # DK NO SE FI
tools/water/.venv/Scripts/python tools/water/build_index.py DK         # one country (quick test)
tools/water/.venv/Scripts/python tools/water/build_index.py --refresh  # download the extracts again
```

The extracts land in `storage/water/pbf/` (~3.5 GB in all) and are only
downloaded when missing (or with `--refresh`). Then:

1. `bin\publish-water.bat test` / `bin\publish-water.bat` - upload.
2. On the server (optional): `php bin/poi-water-distance.php` - POIs computed
   with an older index get the new distances.
3. On dev: `php bin/import-shelters.php distances --all` - the staged shelters.

## Place names for unnamed shelters

`place_names.py` finds, for positions, the smallest named protected area they
lie in, else the nearest named place (village, hamlet, locality, ... within
20 km), from the same extracts (cached per country in `storage/water/places-COUNTRY.pkl`, each country read in its own child process - one process for all ran out of memory at Norway).
`php bin/import-shelters.php places [--all]` calls it for every staged shelter
without a name; the admin tool then shows and imports "type place", e.g.
"Gapahuk Femundsmarka".

## Tests

```bash
tools/water/.venv/Scripts/python -m unittest discover -s tools/water/tests
tools/water/.venv/Scripts/python tools/water/make_test_fixture.py   # after a format change
```

`make_test_fixture.py` writes `tests/testdata/water-index-sample.bin`, which
PHP's `WaterDistanceServiceTest` reads - so both sides must agree on the format.
