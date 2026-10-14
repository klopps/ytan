-- International number of a lighthouse as printed in the official lists
-- (e.g. "B1804.42", "C0202.2", "L5554"), always without spaces. Filled by the
-- lighthouse list import (admin tool); empty for hand-drawn lighthouses.
ALTER TABLE lighthouse
    ADD COLUMN international_id VARCHAR(30) NULL DEFAULT NULL AFTER sector_characteristic,
    ADD KEY idx_lighthouse_international_id (international_id);
