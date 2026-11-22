<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Domain\LighthouseImport\LighthouseImportRepository;
use Ytan\Exception\ApiException;
use Ytan\Exception\ValidationException;
use Ytan\Service\LighthouseList\FinnishLightMapper;
use Ytan\Service\LighthouseList\NorwegianLightMapper;
use Ytan\Service\NationalLights\NationalLightsClient;

/**
 * Loads the lights of Finland (Väylävirasto) or Norway (Kystverket) from the national open WFS services into the
 * staging table of the lighthouse list import (source "fi" / "no") - from there the admin tool filters, selects
 * and imports them like the entries of the lists. A whole country per request (a few MB, ~2-6 s), and a gap
 * between requests out of consideration for the services.
 */
final class NationalLightsImportService
{
    public const COUNTRIES = ['fi', 'no'];
    public const MIN_SECONDS_BETWEEN_REQUESTS = 30;

    /** @var callable():int */
    private $clock;

    /**
     * @param string $throttleFile where the time of the last request is kept
     * @param (callable():int)|null $clock current Unix time, injectable for tests
     */
    public function __construct(
        private readonly NationalLightsClient $client,
        private readonly LighthouseImportRepository $staging,
        private readonly FinnishLightMapper $finnish,
        private readonly NorwegianLightMapper $norwegian,
        private readonly string $throttleFile,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array{fetched:int, lights:int, skipped:int, inserted:int, updated:int} */
    public function import(string $country): array
    {
        if (!in_array($country, self::COUNTRIES, true)) {
            throw new ValidationException('country must be one of ' . implode(', ', self::COUNTRIES) . '.');
        }

        $this->throttle();

        if ($country === 'fi') {
            $data = $this->client->fetchFinland();
            $entries = $this->finnish->map($data['aids'], $data['lights'], $data['sectors']);
            $fetched = count($data['aids']);
            $source = FinnishLightMapper::SOURCE;
        } else {
            $data = $this->client->fetchNorway();
            $entries = $this->norwegian->map($data['lights'], $data['sectors']);
            $fetched = count($data['lights']);
            $source = NorwegianLightMapper::SOURCE;
        }

        $saved = $this->staging->upsertMany($source, $entries);

        return [
            'fetched' => $fetched,
            'lights' => count($entries),
            'skipped' => max(0, $fetched - count($entries)),
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
            throw new ApiException('The national lights services are asked at most once per ' . self::MIN_SECONDS_BETWEEN_REQUESTS . " seconds - please wait $wait s.", 429, 'lighthouse_import.national_too_soon');
        }

        $dir = dirname($this->throttleFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->throttleFile, (string) $now);
    }
}
