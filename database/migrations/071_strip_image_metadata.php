<?php

declare(strict_types=1);

// Removes EXIF/GPS/XMP & co. from every photo already stored - new uploads
// are cleaned by ImageStorageService::store() (ImageMetadataStripper). Walks
// the files on disk (storage/{tour,poi,route,area}-images/{id}/), rewrites a
// file only when something was removed and keeps the image row's size_bytes
// in step. A file that can't be parsed is left alone and reported.

use Ytan\Service\ImageMetadataStripper;

return function (PDO $pdo): void {
    $root = dirname(__DIR__, 2);
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    foreach (['tour', 'poi', 'route', 'area'] as $entity) {
        $dir = $root . '/storage/' . $entity . '-images';
        $update = $pdo->prepare("UPDATE `{$entity}_image` SET size_bytes = ? WHERE {$entity}_id = ? AND filename = ?");
        $files = 0;
        $cleaned = 0;
        $saved = 0;

        foreach (glob($dir . '/*/*') ?: [] as $path) {
            $mime = $mimes[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
            if ($mime === null || !is_file($path)) {
                continue;
            }
            $files++;
            $before = (string) file_get_contents($path);
            try {
                $after = ImageMetadataStripper::strip($before, $mime);
            } catch (\Throwable $e) {
                echo "  skipped (unreadable): $path\n";
                continue;
            }
            if ($after === $before) {
                continue;
            }
            file_put_contents($path, $after);
            $update->execute([strlen($after), (int) basename(dirname($path)), basename($path)]);
            $cleaned++;
            $saved += strlen($before) - strlen($after);
        }

        echo "  $entity-images: $files files, $cleaned cleaned, " . round($saved / 1024) . " KB removed\n";
    }
};
