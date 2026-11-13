<?php

declare(strict_types=1);

namespace Ytan\Service\Ai;

/**
 * Generated Routencheck texts on disk (storage/ai-cache/{key}.json), reused
 * for the configured number of hours. The key is the hash of exactly what was
 * sent to the model, so the same input - also from another user - gives the
 * stored text back without a new request. No user data in the files; the
 * daily bin/cleanup-file-caches.php removes files older than a day.
 */
final class AiTextCache
{
    /** Longest cache age the cleanup keeps (the admin setting can only be shorter). */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(private readonly string $directory)
    {
    }

    public static function key(string $system, string $user, string $model): string
    {
        return hash('sha256', $model . "\0" . $system . "\0" . $user);
    }

    /** @return array{text: string, created_at: string}|null */
    public function get(string $key, int $maxAgeSeconds): ?array
    {
        if ($maxAgeSeconds <= 0 || !preg_match('/^[a-f0-9]{64}$/', $key)) {
            return null;
        }
        $file = $this->directory . '/' . $key . '.json';
        if (!is_file($file) || time() - (int) filemtime($file) > $maxAgeSeconds) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && is_string($data['text'] ?? null) && is_string($data['created_at'] ?? null) ? $data : null;
    }

    public function put(string $key, string $text): string
    {
        $createdAt = gmdate('Y-m-d\TH:i:s\Z');
        if (preg_match('/^[a-f0-9]{64}$/', $key) && (is_dir($this->directory) || @mkdir($this->directory, 0775, true))) {
            $file = $this->directory . '/' . $key . '.json';
            @file_put_contents($file . '.tmp', json_encode(['text' => $text, 'created_at' => $createdAt], JSON_UNESCAPED_UNICODE));
            @rename($file . '.tmp', $file);
        }

        return $createdAt;
    }
}
