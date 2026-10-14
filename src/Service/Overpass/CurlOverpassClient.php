<?php

declare(strict_types=1);

namespace Ytan\Service\Overpass;

use Ytan\Exception\OverpassException;

/**
 * Real OverpassClient - plain curl POST (`data=<query>`), the way the public
 * instances expect it.
 *
 * The public instances are shared and not always up (the main one answered
 * HTTP 504 once and 200 on the very next request while this was written),
 * so the endpoints are a list (.env's OVERPASS_ENDPOINTS, comma separated;
 * default: the main instance) tried in order. An endpoint that answers
 * "busy" (HTTP 502/503/504 - measured: about every second request to the
 * main instance, whose answer then takes 6-8 s) is asked up to
 * BUSY_RETRIES more times, a few seconds apart; any other failure - 429/406
 * above all, where the usage policy asks to wait 30 s - goes straight on to
 * the next endpoint, and the caller (OsmImportService) enforces a minimum
 * gap between requests.
 * The User-Agent identifies the application, which every instance's policy
 * asks for.
 */
final class CurlOverpassClient implements OverpassClient
{
    public const DEFAULT_ENDPOINT = 'https://overpass-api.de/api/interpreter';
    private const BUSY_RETRIES = 2;
    private const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';

    /** @param list<string> $endpoints */
    public function __construct(
        private readonly array $endpoints = [self::DEFAULT_ENDPOINT],
        private readonly int $timeoutSeconds = 90,
        private readonly int $busyRetryDelaySeconds = 6,
    ) {
    }

    /** @param string $configured the OVERPASS_ENDPOINTS setting (comma separated), may be empty */
    public static function fromSetting(string $configured): self
    {
        $endpoints = array_values(array_filter(array_map('trim', explode(',', $configured)), fn ($e) => $e !== ''));

        return new self($endpoints === [] ? [self::DEFAULT_ENDPOINT] : $endpoints);
    }

    public function query(string $query): array
    {
        $problems = [];
        foreach ($this->endpoints as $endpoint) {
            [$body, $status, $error] = $this->post($endpoint, $query);
            for ($retry = 0; $retry < self::BUSY_RETRIES && in_array($status, [502, 503, 504], true) && $this->busyRetryDelaySeconds > 0; $retry++) {
                sleep($this->busyRetryDelaySeconds);
                [$body, $status, $error] = $this->post($endpoint, $query);
            }

            if ($body !== false && $status === 200) {
                $decoded = json_decode((string) $body, true);
                if (is_array($decoded) && isset($decoded['elements']) && is_array($decoded['elements'])) {
                    return $decoded;
                }
                $problems[] = parse_url($endpoint, PHP_URL_HOST) . ': no usable JSON';
                continue;
            }
            $problems[] = parse_url($endpoint, PHP_URL_HOST) . ': ' . ($status > 0 ? "HTTP $status" : ($error !== '' ? $error : 'no answer'));
        }

        throw new OverpassException('Overpass API not available (' . implode('; ', $problems) . ').');
    }

    /** @return array{0: string|false, 1: int, 2: string} body, HTTP status (0 = none), curl error */
    private function post(string $endpoint, string $query): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$body, $status, $error];
    }
}
