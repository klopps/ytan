-- Code Check 2026-11-15: POIs of one type near a position are looked up by
-- the shelter/campsite import's duplicate test (a dependent subquery per
-- staging row - 16 s for the shelter list's count on 31,000 entries with
-- only the poitype_id index), the Routencheck (PoiRepository::findInBox)
-- and the tour PDF (findByUserWithPublicInBox). The old single-column
-- poitype_id index (FK_poi_poitype) stays, the foreign key uses it.
ALTER TABLE poi ADD INDEX idx_poi_type_position (poitype_id, latitude, longitude);
ALTER TABLE poi ADD INDEX idx_poi_position (latitude, longitude);
