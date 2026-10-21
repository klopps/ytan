<?php

declare(strict_types=1);

namespace Ytan\Service\Water;

/**
 * Read access to the water distance index (storage/water/water-index.bin),
 * written by tools/water/build_index.py - format in docs/water-index.md.
 * Reads only what a query needs: one directory slab and the segments of a
 * run of neighbouring cells per call (they lie next to each other in the file).
 */
final class WaterIndex
{
    public const KINDS = ['sea', 'river', 'lake'];

    private const MAGIC = 'YTANWATR';
    private const FORMAT_VERSION = 1;
    private const HEADER_BYTES = 64;
    private const SEGMENT_BYTES = 16;

    /** @var resource */
    private $file;
    private int $rows;
    private int $columns;
    private int $row0;
    private int $column0;
    private int $cell;
    private int $segmentsOffset;

    /** @var array<string, list<array{float,float,float,float}>> cells read so far (batch runs read neighbours again) */
    private array $cache = [];

    private const CACHE_CELLS = 20000;

    /** @param resource $file */
    private function __construct($file, array $header)
    {
        $this->file = $file;
        $this->cell = $header['cell'];
        $this->row0 = $header['row0'];
        $this->column0 = $header['col0'];
        $this->rows = $header['rows'];
        $this->columns = $header['cols'];
        $this->segmentsOffset = self::HEADER_BYTES + count(self::KINDS) * $this->rows * $this->columns * 8;
    }

    /** null when the file is missing or not an index of this format. */
    public static function open(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }
        $file = fopen($path, 'rb');
        if ($file === false) {
            return null;
        }
        $raw = fread($file, self::HEADER_BYTES);
        if ($raw === false || strlen($raw) < self::HEADER_BYTES || substr($raw, 0, 8) !== self::MAGIC) {
            fclose($file);

            return null;
        }
        $header = unpack('Vversion/Vcell/lrow0/lcol0/Vrows/Vcols/Vkinds/Vtotal', substr($raw, 8, 32));
        if ($header['version'] !== self::FORMAT_VERSION || $header['kinds'] !== count(self::KINDS) || $header['cell'] <= 0) {
            fclose($file);

            return null;
        }

        return new self($file, $header);
    }

    /** Cell size in degrees (latitude and longitude). */
    public function cellDegrees(): float
    {
        return $this->cell / 1e6;
    }

    /**
     * Segments [lat1, lng1, lat2, lng2] in degrees of the cells
     * (row, column0..column1) of one kind; cells outside the grid are empty.
     *
     * @return list<array{float,float,float,float}>
     */
    public function segments(int $kind, int $row, int $column0, int $column1): array
    {
        $r = $row - $this->row0;
        if ($r < 0 || $r >= $this->rows) {
            return [];
        }
        $c0 = max(0, $column0 - $this->column0);
        $c1 = min($this->columns - 1, $column1 - $this->column0);
        if ($c0 > $c1) {
            return [];
        }

        $result = [];
        $missing = [];
        for ($c = $c0; $c <= $c1; $c++) {
            $key = $kind . ':' . $r . ':' . $c;
            if (isset($this->cache[$key])) {
                array_push($result, ...$this->cache[$key]);
            } else {
                $missing[] = $c;
            }
        }
        if ($missing === []) {
            return $result;
        }

        // One read for the directory entries of the missing run, one for its segments.
        $m0 = $missing[0];
        $m1 = $missing[count($missing) - 1];
        $entry = ($kind * $this->rows + $r) * $this->columns + $m0;
        fseek($this->file, self::HEADER_BYTES + $entry * 8);
        $directory = array_values(unpack('V*', (string) fread($this->file, ($m1 - $m0 + 1) * 8)));
        $first = null;
        $last = null;
        for ($i = 0; $i <= $m1 - $m0; $i++) {
            $start = $directory[$i * 2];
            $count = $directory[$i * 2 + 1];
            if ($count > 0) {
                $first ??= $start;
                $last = $start + $count;
            }
        }
        $values = [];
        if ($first !== null) {
            fseek($this->file, $this->segmentsOffset + $first * self::SEGMENT_BYTES);
            $values = array_values(unpack('l*', (string) fread($this->file, ($last - $first) * self::SEGMENT_BYTES)));
        }

        if (count($this->cache) > self::CACHE_CELLS) {
            $this->cache = [];
        }
        foreach ($missing as $c) {
            $i = $c - $m0;
            $start = $directory[$i * 2];
            $count = $directory[$i * 2 + 1];
            $cellSegments = [];
            for ($s = 0; $s < $count; $s++) {
                $o = ($start - (int) $first + $s) * 4;
                $cellSegments[] = [$values[$o] / 1e6, $values[$o + 1] / 1e6, $values[$o + 2] / 1e6, $values[$o + 3] / 1e6];
            }
            $this->cache[$kind . ':' . $r . ':' . $c] = $cellSegments;
            array_push($result, ...$cellSegments);
        }

        return $result;
    }
}
