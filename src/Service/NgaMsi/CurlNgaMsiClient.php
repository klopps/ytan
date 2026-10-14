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
    private const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';

    public function __construct(private readonly string $endpoint = self::ENDPOINT, private readonly int $timeoutSeconds = 180)
    {
    }

    public function fetchVolume(int $volume): array
    {
        $ch = curl_init($this->endpoint . '?' . http_build_query(['volume' => $volume, 'includeRemovals' => 'false', 'output' => 'json']));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '', // accept and decode gzip
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new NgaMsiException('NGA Maritime Safety Information not available (' . ($status > 0 ? "HTTP $status" : ($error !== '' ? $error : 'no answer')) . ').');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !isset($decoded['ngalol']) || !is_array($decoded['ngalol'])) {
            throw new NgaMsiException('NGA Maritime Safety Information answered with something that is not the List of Lights.');
        }

        return array_values($decoded['ngalol']);
    }
}
