-- The same lighthouse may exist more than once (the lighthouse list import warns
-- about a repeated international number and lets the admin choose per entry
-- whether to add, overwrite or skip), so international_id is no longer unique.
ALTER TABLE lighthouse
    DROP KEY uq_lighthouse_international_id,
    ADD KEY idx_lighthouse_international_id (international_id);
