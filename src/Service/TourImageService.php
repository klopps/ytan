<?php

declare(strict_types=1);

namespace Ytan\Service;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Ytan\Exception\ValidationException;

/**
 * Reads/writes tour photo files on disk, outside the public webroot -
 * unlike WsiRenderer's cache (public/images/wsi, fine since WSI icons are
 * unowned/non-sensitive), tour photos can belong to a private tour and
 * must not be reachable via a guessable static URL. TourController serves
 * them back through GET /tours/{id}/images/{imageId} after a visibility
 * check instead. Filenames are always server-generated random hex (never
 * taken from the client), so there's no path-traversal surface the way a
 * client-supplied filename would create.
 */
final class TourImageService
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly string $storageDir)
    {
    }

    /**
     * @return array{filename:string, mime_type:string, size_bytes:int}
     */
    public function store(int $tourId, UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('Upload failed.');
        }

        $size = $file->getSize() ?? 0;
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new ValidationException('Image must be 5 MB or smaller.');
        }

        $contents = (string) $file->getStream();

        // Trust the actual file bytes, not the client-supplied Content-Type.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new ValidationException('Only JPEG, PNG or WebP images are allowed.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        $dir = $this->tourDir($tourId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $filename, $contents);

        return ['filename' => $filename, 'mime_type' => $mime, 'size_bytes' => strlen($contents)];
    }

    /**
     * @return array{path:string, contents:string}|null
     */
    public function read(int $tourId, string $filename): ?array
    {
        $path = $this->tourDir($tourId) . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        return ['path' => $path, 'contents' => (string) file_get_contents($path)];
    }

    public function delete(int $tourId, string $filename): void
    {
        $path = $this->tourDir($tourId) . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function tourDir(int $tourId): string
    {
        return rtrim($this->storageDir, '/\\') . '/' . $tourId;
    }
}
