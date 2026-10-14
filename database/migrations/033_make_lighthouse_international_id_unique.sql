-- A lighthouse's international number identifies it worldwide, so it can only
-- belong to one lighthouse POI (NULL = none, any number of those allowed).
ALTER TABLE lighthouse
    DROP KEY idx_lighthouse_international_id,
    ADD UNIQUE KEY uq_lighthouse_international_id (international_id);
