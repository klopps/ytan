-- Shelter import: names for shelters that have none. type_label = the
-- source's word for the facility ("Gapahuk", "Shelter", "Hütte"), place = the
-- smallest protected area the shelter lies in, else the nearest named place
-- (found on dev from the OpenStreetMap extracts, tools/water/place_names.py,
-- php bin/import-shelters.php places). The name shown and imported is the own
-- name, else "type_label place".
ALTER TABLE shelter_import
    ADD COLUMN type_label VARCHAR(60) NOT NULL DEFAULT '' AFTER kind,
    ADD COLUMN place VARCHAR(120) NOT NULL DEFAULT '' AFTER type_label;
