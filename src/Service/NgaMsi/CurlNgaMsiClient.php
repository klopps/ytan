<?php

declare(strict_types=1);

namespace Ytan\Service\NgaMsi;

use Ytan\Exception\NgaMsiException;

/**
 * Real NgaMsiClient - plain curl GET of
 * `https://msi.nga.mil/api/publications/ngalol/lights-buoys?volume=N&includeRemovals=false&output=json`
 * (without `includeRemovals` the service answers 400). The answers are big
 * (2-9 MB a volume) and take a few seconds, hence the long timeout; the
 * service rejects browser-like clients at its web page but serves its API
 * to an identifying User-Agent like the one every YTAN request carries.
 */
final class CurlNgaMsiClient implements NgaMsiClient
{
    public const ENDPOINT = 'https://msi.nga.mil/api/publications/ngalol/lights-buoys';

    public function __construct(private readonly string $endpoint = self::ENDPOINT, private readonly int $timeoutSeconds = 180)
    {
    }

    public function fetchVolume(int $volume): array
    {
        ['status' => $status, 'body' => $body, 'error' => $error] = \Ytan\Service\Http\HttpFetch::request(
            $this->endpoint . '?' . http_build_query(['volume' => $volume, 'includeRemovals' => 'false', 'output' => 'json']),
            [],
            15,
            $this->timeoutSeconds
        );

        if ($body === null || $status !== 200) {
            throw new NgaMsiException('NGA Maritime Safety Information not available (' . ($status > 0 ? "HTTP $status" : ($error !== '' ? $error : 'no answer')) . ').');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['ngalol']) || !is_array($decoded['ngalol'])) {
            throw new NgaMsiException('NGA Maritime Safety Information answered with something that is not the List of Lights.');
        }

        return array_values($decoded['ngalol']);
    }
}
