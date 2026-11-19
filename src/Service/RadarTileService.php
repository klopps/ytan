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
 * zoom.max tile and enlarged here - smoothly, but only in the legend's colours
 * (see overzoom(); until 2026-11-19 nearest neighbour, blocky 1 km squares from
 * zoom 13 on). Those tiles are cached INSIDE the frame's own directory, so the job
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
     *
     * Smooth, but only in legend colours: the palette index IS the rain class
     * (0 = transparent, a higher index = heavier rain - tools/radar/ytanradar/
     * palette.py), so the indices of the four nearest source pixels are mixed
     * bilinearly and rounded. Class borders become smooth contours instead of
     * 1 km squares (todo.md: blocky from zoom 13 on), and no colour appears
     * that the legend doesn't have - mixing the colours themselves would.
     * Pixels around the sub-square are taken from the parent tile too, so
     * neighbouring tiles of the same parent join without a seam (at the
     * parent's own edge the edge pixel repeats). Drawn as horizontal runs of
     * one class (imageline), not pixel by pixel.
     */
    private function overzoom(string $parentFile, int $shift, int $dx, int $dy): ?string
    {
        $src = @imagecreatefrompng($parentFile);
        if (!$src instanceof GdImage) {
            return null;
        }
        // source pixels per tile (below 1 deeper than zoom max + 8)
        $size = self::TILE / (1 << $shift);
        $sx = $dx * $size;
        $sy = $dy * $size;

        $transparent = imagecolortransparent($src);
        if (!$this->isClassPalette($src, $transparent)) {
            // not the job's palette (index 0 = transparent, index i = legend class i):
            // the indices mean nothing in order - enlarge pixel-exact instead
            return $this->overzoomNearest($src, $size, $sx, $sy, $transparent);
        }

        // the source pixels the interpolation can reach, one around the sub-square
        $x0 = max(0, (int) floor($sx - 1));
        $y0 = max(0, (int) floor($sy - 1));
        $x1 = min(self::TILE - 1, (int) ceil($sx + $size));
        $y1 = min(self::TILE - 1, (int) ceil($sy + $size));
        $values = [];
        $inside = false;
        for ($py = $y0; $py <= $y1; $py++) {
            for ($px = $x0; $px <= $x1; $px++) {
                $class = imagecolorat($src, $px, $py);
                $values[$py][$px] = $class;
                // rain inside the sub-square itself (the border alone fades in at most a pixel)
                $inside = $inside || ($class > 0 && $px >= floor($sx) && $px < $sx + $size && $py >= floor($sy) && $py < $sy + $size);
            }
        }
        if (!$inside) {
            return null;
        }

        $grid = self::TILE;
        $dst = imagecreate($grid, $grid);
        imagepalettecopy($dst, $src);
        imagefill($dst, 0, 0, 0);
        imagecolortransparent($dst, 0);
        $step = $size / $grid;
        // the source columns and weights are the same for every row
        $colA = [];
        $colB = [];
        $colF = [];
        for ($gx = 0; $gx < $grid; $gx++) {
            $u = min(max($sx + ($gx + 0.5) * $step - 0.5, $x0), $x1);
            $colA[$gx] = (int) floor($u);
            $colB[$gx] = min($colA[$gx] + 1, $x1);
            $colF[$gx] = $u - $colA[$gx];
        }
        // source rows without any rain in reach (most of a tile): nothing to draw
        $dryRow = [];
        foreach ($values as $py => $row) {
            $dryRow[$py] = max($row) === 0;
        }
        for ($gy = 0; $gy < $grid; $gy++) {
            $v = min(max($sy + ($gy + 0.5) * $step - 0.5, $y0), $y1);
            $vy = (int) floor($v);
            $vy2 = min($vy + 1, $y1);
            if ($dryRow[$vy] && $dryRow[$vy2]) {
                continue;
            }
            $fy = $v - $vy;
            $rowA = $values[$vy];
            $rowB = $values[$vy2];
            $runStart = 0;
            $runClass = -1;
            for ($gx = 0; $gx <= $grid; $gx++) {
                if ($gx < $grid) {
                    $ux = $colA[$gx];
                    $ux2 = $colB[$gx];
                    $fx = $colF[$gx];
                    $top = $rowA[$ux] + ($rowA[$ux2] - $rowA[$ux]) * $fx;
                    $bottom = $rowB[$ux] + ($rowB[$ux2] - $rowB[$ux]) * $fx;
                    $class = (int) round($top + ($bottom - $top) * $fy);
                } else {
                    $class = -2; // flush the last run
                }
                if ($class !== $runClass) {
                    // one line per run of the same class instead of one call per pixel
                    if ($runClass > 0) {
                        imageline($dst, $runStart, $gy, $gx - 1, $gy, $runClass);
                    }
                    $runStart = $gx;
                    $runClass = $class;
                }
            }
        }

        return self::png($dst);
    }

    /**
     * Whether the tile's palette is the job's: index 0 transparent and index i
     * the colour of legend class i (manifest "legend", lightest first) - only
     * then is a higher index heavier rain, which the smoothing relies on.
     */
    private function isClassPalette(GdImage $src, int $transparent): bool
    {
        if ($transparent !== 0) {
            return false;
        }
        try {
            $legend = $this->manifest()['legend'] ?? [];
        } catch (NotFoundException) {
            return false;
        }
        if (!is_array($legend) || $legend === [] || imagecolorstotal($src) < count($legend) + 1) {
            return false;
        }
        foreach (array_values($legend) as $i => $entry) {
            $c = imagecolorsforindex($src, $i + 1);
            if (strtolower(sprintf('#%02x%02x%02x', $c['red'], $c['green'], $c['blue'])) !== strtolower((string) ($entry['color'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    /** Pixel-exact enlargement (palette in, palette out), null if the sub-square is empty. */
    private function overzoomNearest(GdImage $src, float $size, float $sx, float $sy, int $transparent): ?string
    {
        $width = max(1, (int) $size);
        $px0 = (int) floor($sx);
        $py0 = (int) floor($sy);
        $empty = true;
        for ($py = $py0; $py < $py0 + $width && $empty; $py++) {
            for ($px = $px0; $px < $px0 + $width; $px++) {
                if (imagecolorat($src, $px, $py) !== $transparent) {
                    $empty = false;
                    break;
                }
            }
        }
        if ($empty) {
            return null;
        }
        $dst = imagecreate(self::TILE, self::TILE);
        imagepalettecopy($dst, $src);
        if ($transparent >= 0) {
            imagefill($dst, 0, 0, $transparent);
            imagecolortransparent($dst, $transparent);
        }
        imagecopyresized($dst, $src, 0, 0, $px0, $py0, self::TILE, self::TILE, $width, $width);

        return self::png($dst);
    }

    private static function png(GdImage $img): string
    {
        ob_start();
        imagepng($img, null, 9);

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
