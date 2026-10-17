<?php

declare(strict_types=1);

namespace Ytan\Service;

use GdImage;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;

/**
 * Serves the rain radar written by the Python job in tools/radar/
 * (storage/radar/manifest.json + frames/{id}/{z}/{x}/{y}.png, see
 * tools/radar/ytanradar/job.py) - read-only except for the overzoom cache.
 *
 * The job writes tiles only up to the manifest's zoom.max (8, ~350 m per
 * pixel - the composite has 1 km), anything closer is cut out of the
 * zoom.max tile and enlarged here, pixel-exact (nearest neighbour keeps the
 * legend's colours; the job already smoothed the data when it reprojected
 * it). Those tiles are cached INSIDE the frame's own directory, so the job
 * deletes them together with the frame and no separate cleanup is needed.
 *
 * Paths are built only from a frame id matching FRAME_ID and integer z/x/y,
 * never from raw request text (same idea as WsiRenderer's code check).
 */
final class RadarTileService
{
    /** o = observation, f = forecast (base time + lead minutes), see job.py */
    public const FRAME_ID = '/^(o\d{12}|f\d{12}-\d{3})$/';
    public const MAX_ZOOM = 20;
    private const TILE = 256;

    private ?string $emptyTile = null;

    public function __construct(private readonly string $radarDir)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        $json = @file_get_contents($this->radarDir . '/manifest.json');
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data)) {
            throw new NotFoundException('No radar data available.', 'radar.not_available');
        }

        return $data;
    }

    /**
     * PNG bytes of one tile; a transparent tile where there is no rain or no data.
     */
    public function tile(string $frameId, int $z, int $x, int $y): string
    {
        if (preg_match(self::FRAME_ID, $frameId) !== 1) {
            throw new ValidationException('Invalid radar frame.', 'radar.invalid_frame');
        }
        if ($z < 0 || $z > self::MAX_ZOOM || $x < 0 || $y < 0 || $x >= (1 << $z) || $y >= (1 << $z)) {
            throw new ValidationException('Invalid tile coordinates.', 'radar.invalid_tile');
        }
        $frameDir = $this->radarDir . '/frames/' . $frameId;
        if (!is_dir($frameDir)) {
            // expired (the job keeps unreferenced frames ~10 minutes) or never existed
            throw new NotFoundException('Radar frame not found.', 'radar.frame_not_found');
        }

        $zoom = $this->manifestZoom();
        if ($z < $zoom['min']) {
            return $this->emptyTile();
        }

        $file = $this->tilePath($frameDir, $z, $x, $y);
        if (is_file($file)) {
            return (string) file_get_contents($file);
        }
        if ($z <= $zoom['max']) {
            return $this->emptyTile(); // the job skips tiles without rain
        }

        $shift = $z - $zoom['max'];
        $parent = $this->tilePath($frameDir, $zoom['max'], $x >> $shift, $y >> $shift);
        if (!is_file($parent)) {
            return $this->emptyTile();
        }
        $png = $this->overzoom($parent, $shift, $x & ((1 << $shift) - 1), $y & ((1 << $shift) - 1));
        if ($png === null) {
            return $this->emptyTile();
        }
        $this->store($file, $png);

        return $png;
    }

    /**
     * @return array{min:int, max:int}
     */
    private function manifestZoom(): array
    {
        try {
            $zoom = $this->manifest()['zoom'] ?? [];
        } catch (NotFoundException) {
            $zoom = [];
        }

        return ['min' => (int) ($zoom['min'] ?? 5), 'max' => (int) ($zoom['max'] ?? 8)];
    }

    private function tilePath(string $frameDir, int $z, int $x, int $y): string
    {
        return $frameDir . '/' . $z . '/' . $x . '/' . $y . '.png';
    }

    /**
     * Enlarge the ($dx, $dy) sub-square of the 2^$shift x 2^$shift grid of a
     * parent tile to a full tile. Null if that part of the parent is empty.
     */
    private function overzoom(string $parentFile, int $shift, int $dx, int $dy): ?string
    {
        $src = @imagecreatefrompng($parentFile);
        if (!$src instanceof GdImage) {
            return null;
        }
        $size = self::TILE >> $shift;
        $sx = $dx * $size;
        $sy = $dy * $size;
        if ($size < 1) {
            // deeper than 1 source pixel per tile (z > max + 8): pick the pixel
            $size = 1;
            $sx = intdiv($dx, 1 << ($shift - 8));
            $sy = intdiv($dy, 1 << ($shift - 8));
        }

        $transparent = imagecolortransparent($src);
        $empty = true;
        for ($py = $sy; $py < $sy + $size && $empty; $py++) {
            for ($px = $sx; $px < $sx + $size; $px++) {
                if (imagecolorat($src, $px, $py) !== $transparent) {
                    $empty = false;
                    break;
                }
            }
        }
        if ($empty) {
            return null;
        }

        // Palette in, palette out: copy the palette, then nearest-neighbour.
        $dst = imagecreate(self::TILE, self::TILE);
        imagepalettecopy($dst, $src);
        if ($transparent >= 0) {
            imagefill($dst, 0, 0, $transparent);
            imagecolortransparent($dst, $transparent);
        }
        imagecopyresized($dst, $src, 0, 0, $sx, $sy, self::TILE, self::TILE, $size, $size);

        ob_start();
        imagepng($dst, null, 9);

        return (string) ob_get_clean();
    }

    private function store(string $file, string $png): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // cache only - serving works without it
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $png) !== false) {
            @rename($tmp, $file);
        }
    }

    private function emptyTile(): string
    {
        if ($this->emptyTile === null) {
            $img = imagecreate(self::TILE, self::TILE);
            $bg = imagecolorallocate($img, 0, 0, 0);
            imagecolortransparent($img, $bg);
            ob_start();
            imagepng($img, null, 9);
            $this->emptyTile = (string) ob_get_clean();
        }

        return $this->emptyTile;
    }
}
