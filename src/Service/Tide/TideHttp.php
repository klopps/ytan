<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/**
 * The HTTP the national tide sources need beyond JsonHttpClient's plain JSON
 * GET: raw bodies (Kartverket answers XML) and a JSON POST (Rijkswaterstaat's
 * services). Never throws - null = network error / non-200.
 */
interface TideHttp
{
    /** @param list<string> $headers extra request headers ("Name: value") */
    public function get(string $url, array $headers = []): ?string;

    /** @param array<string, mixed> $body sent as JSON */
    public function postJson(string $url, array $body): ?string;
}
