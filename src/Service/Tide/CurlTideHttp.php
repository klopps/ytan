<?php

declare(strict_types=1);

namespace Ytan\Service\Tide;

/** Real TideHttp - plain curl, short timeouts (a Routencheck waits for it), never throws. */
final class CurlTideHttp implements TideHttp
{
    private const USER_AGENT = 'YTAN/1.0 (+https://ytan.pesr.org; no-reply@pesr.org)';

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
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);

        return $errno !== 0 || !is_string($body) || $status !== 200 ? null : $body;
    }
}
