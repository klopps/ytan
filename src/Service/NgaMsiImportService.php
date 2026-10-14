<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthouseList\NgaMsiLightMapper;
use Ytan\Service\NgaMsi\NgaMsiClient;

/**
 * Loads one volume of the NGA List of Lights (Pub. 110-116) from the NGA
 * Maritime Safety Information web service into the staging table of the
 * lighthouse list import as source "ngaapi" - from there the admin tool
 * filters, selects and imports it like the entries of the PDF lists. Needs
 * no PDF and no dev-only library, so it works on the production server too.
 *
 * One volume per request (2-9 MB of JSON), and a short gap between requests
 * out of consideration for the service.
 */
final class NgaMsiImportService
{
    /** NGA Pub. 110-116 with the sea areas they cover (the web service's `volume` parameter). */
    public const VOLUMES = [110, 111, 112, 113, 114, 115, 116];
    public const MIN_SECONDS_BETWEEN_REQUESTS = 10;

    /** @var callable():int */
    private $clock;

    /**
     * @param string $throttleFile where the time of the last request is kept
     * @param (callable():int)|null $clock current Unix time, injectable for tests
     */
    public function __construct(
        private readonly NgaMsiClient $client,
        private readonly LighthouseImportRepository $staging,
        private readonly NgaMsiLightMapper $mapper,
        private readonly string $throttleFile,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{fetched:int, lights:int, skipped:int, inserted:int, updated:int} */
    public function import(int $volume): array
    {
        if (!in_array($volume, self::VOLUMES, true)) {
            throw new ValidationException('volume must be one of ' . implode(', ', self::VOLUMES) . '.');
        }

        $this->throttle();

        $rows = $this->client->fetchVolume($volume);
        $entries = $this->mapper->mapVolume($rows);
        $saved = $this->staging->upsertMany(NgaMsiLightMapper::SOURCE, $entries);

        return [
            'fetched' => count($rows),
            'lights' => count($entries),
            'skipped' => count($rows) - count($entries),
            'inserted' => $saved['inserted'],
            'updated' => $saved['updated'],
        ];
    }

    /** Refuses a request right after the previous one; records this one's time (also if it then fails). */
    private function throttle(): void
    {
        $now = ($this->clock)();
        $last = is_file($this->throttleFile) ? (int) @file_get_contents($this->throttleFile) : 0;
        $wait = $last + self::MIN_SECONDS_BETWEEN_REQUESTS - $now;
        if ($wait > 0) {
            throw new ApiException('The NGA service is asked for at most one volume per ' . self::MIN_SECONDS_BETWEEN_REQUESTS . " seconds - please wait $wait s.", 429, 'lighthouse_import.nga_too_soon');
        }

        $dir = dirname($this->throttleFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->throttleFile, (string) $now);
    }
}
