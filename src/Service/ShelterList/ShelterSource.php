<?php

declare(strict_types=1);

namespace Ytan\Service\ShelterList;

/**
 * One official list of shelters (GeoFA, Naturvardsverket, Kartverket, Lipas).
 * Delivers the whole list at once as entries for the staging table
 * shelter_import; the distances to water are computed while staging
 * (ShelterImportRepository::upsertMany(), WaterDistanceService).
 */
interface ShelterSource
{
    /** Short code stored in shelter_import.source. */
    public function code(): string;

    /** @return list<array<string,mixed>> entries for ShelterImportRepository::upsertMany() */
    public function fetch(): array;
}
