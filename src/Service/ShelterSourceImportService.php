<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\ShelterImport\ShelterImportRepository;
use Ytan\Exception\ValidationException;
use Ytan\Service\ShelterList\ShelterSource;

/**
 * Loads one official shelter list (ShelterSource) completely into the staging
 * table. A refreshed entry keeps its water check result unless its position
 * changed (see ShelterImportRepository::upsertMany()).
 */
final class ShelterSourceImportService
{
    /** @var array<string, ShelterSource> */
    private array $sources = [];

    /** @param list<ShelterSource> $sources */
    public function __construct(private readonly ShelterImportRepository $staging, array $sources)
    {
        foreach ($sources as $source) {
            $this->sources[$source->code()] = $source;
        }
    }

    /** @return list<string> the codes of the configured sources */
    public function codes(): array
    {
        return array_keys($this->sources);
    }

    /** @return array{fetched:int, skipped:int, inserted:int, updated:int} */
    public function import(string $code): array
    {
        $source = $this->sources[$code] ?? null;
        if ($source === null) {
            throw new ValidationException('Unknown shelter source: ' . $code);
        }

        $entries = $source->fetch();
        $saved = $this->staging->upsertMany($entries);

        return ['fetched' => count($entries), 'skipped' => 0, 'inserted' => $saved['inserted'], 'updated' => $saved['updated']];
    }
}
