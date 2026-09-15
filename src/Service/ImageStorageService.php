<?php

declare(strict_types=1);

namespace Ytan\Service;

use finfo;
use Psr\Http\Message\UploadedFileInterface;
use Ytan\Exception\ValidationException;

/**
 * Reads/writes an entity's photo files on disk, outside the public webroot -
 * a photo can belong to a private tour/POI/route/area and must not be
 * reachable via a guessable static URL. The owning controller serves them
 * back through its own GET .../images/{imageId} endpoint after a visibility
 * check instead. Filenames are always server-generated random hex (never
 * taken from the client), so there's no path-traversal surface the way a
 * client-supplied filename would create.
 *
 * Entity-agnostic by design (nothing here is Tour/POI/Route/Area-specific
 * beyond the caller-supplied $entityId and $storageDir) - one instance per
 * entity type is constructed in App.php, each pointed at its own storage
 * subdirectory (storage/tour-images, storage/poi-images, storage/
 * route-images, storage/area-images), rather than one class per entity.
 */
final class ImageStorageService
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
    public function store(int $entityId, UploadedFileInterface $file): array
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
        $dir = $this->entityDir($entityId);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/' . $filename, $contents);

        return ['filename' => $filename, 'mime_type' => $mime, 'size_bytes' => strlen($contents)];
    }

    /**
     * @return array{path:string, contents:string}|null
     */
    public function read(int $entityId, string $filename): ?array
    {
        $path = $this->entityDir($entityId) . '/' . $filename;
        if (!is_file($path)) {
            return null;
        }

        return ['path' => $path, 'contents' => (string) file_get_contents($path)];
    }

    public function delete(int $entityId, string $filename): void
    {
        $path = $this->entityDir($entityId) . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function entityDir(int $entityId): string
    {
        return rtrim($this->storageDir, '/\\') . '/' . $entityId;
    }
}
