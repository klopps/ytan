<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Deletes stale entries from a plain-file disk cache directory under
 * storage/ (todo.md's "Cache-Bereinigung" item) - used by
 * bin/cleanup-file-caches.php for storage/geocoding-cache and
 * storage/static-maps-cache, the two disk caches with no cleanup routine
 * of their own. storage/weather-cache is deliberately out of scope: its
 * data moved into the weather_forecast DB table a while ago (see
 * bin/cleanup-weather-data.php), the directory itself is now unused dead
 * weight. storage/{area,poi,route,tour}-images are also out of scope -
 * persistent user uploads, not a cache, already deleted through their own
 * POI/Route/Area/Tour delete endpoints.
 */
final class FileCacheCleanupService
{
    /**
     * @return int number of files deleted
     */
    public function cleanDirectory(string $dir, int $maxAgeSeconds): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $cutoff = time() - $maxAgeSeconds;
        $deleted = 0;

        foreach (scandir($dir) as $name) {
            // .gitkeep keeps the otherwise-empty directory tracked in git -
            // never a real cache entry.
            if ($name === '.' || $name === '..' || $name === '.gitkeep') {
                continue;
            }

            $file = $dir . '/' . $name;
            if (is_file($file) && filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
