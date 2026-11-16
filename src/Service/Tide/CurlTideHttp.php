<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/** Real TideHttp - plain curl, short timeouts (a Routencheck waits for it), never throws. */
final class CurlTideHttp implements TideHttp
{
    public function get(string $url, array $headers = []): ?string
    {
        return $this->request($url, $headers === [] ? [] : [CURLOPT_HTTPHEADER => $headers]);
    }

    public function postJson(string $url, array $body): ?string
    {
        return $this->request($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
    }

    /** @param array<int, mixed> $options */
    private function request(string $url, array $options): ?string
    {
        return \Ytan\Service\Http\HttpFetch::body($url, $options, 4, 12);
    }
}
