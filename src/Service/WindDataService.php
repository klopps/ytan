<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;

/**
 * Serves the wind blocks written by the Python job in tools/wind/
 * (storage/wind/manifest.json + runs/{run}/{L0|L1|L2}/..., see
 * tools/wind/ytanwind/job.py) - read-only.
 *
 * The block files are already gzipped by the job and go out unchanged
 * (Content-Encoding: gzip), so a request costs a file read and nothing else.
 *
 * Paths are built only from a run id of 12 digits, a level from a fixed list,
 * a 3-digit hour index and two integers (WindController's route patterns say
 * the same; this class checks again since it is the one touching the disk).
 */
final class WindDataService
{
    public const RUN_ID = '/^\d{12}$/';
    public const LEVELS = ['L0', 'L1', 'L2'];

    public function __construct(private readonly string $windDir)
    {
    }

    /**
     * @return array<string, mixed>|null null before the job's first run
     */
    public function manifest(): ?array
    {
        $json = @file_get_contents($this->windDir . '/manifest.json');
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Gzipped bytes of one block (L1/L2: $block = "bx_by") or of the L0 overview ($block null).
     *
     * @throws ValidationException 422 wind.invalid_block
     * @throws NotFoundException 404 wind.block_not_found
     */
    public function block(string $run, string $level, int $hour, ?string $block): string
    {
        if (preg_match(self::RUN_ID, $run) !== 1 || !in_array($level, self::LEVELS, true)
            || $hour < 0 || $hour > 999
            || ($level === 'L0') !== ($block === null)
            || ($block !== null && preg_match('/^\d{1,4}_\d{1,4}$/', $block) !== 1)) {
            throw new ValidationException('Invalid wind block.', 'wind.invalid_block');
        }
        $name = sprintf('%03d', $hour);
        $path = $level === 'L0'
            ? sprintf('%s/runs/%s/L0/%s.bin.gz', $this->windDir, $run, $name)
            : sprintf('%s/runs/%s/%s/%s/%s.bin.gz', $this->windDir, $run, $level, $name, $block);
        $bytes = is_file($path) ? @file_get_contents($path) : false;
        if ($bytes === false) {
            // replaced and expired (the job keeps a replaced run ~30 minutes), or a block without data
            throw new NotFoundException('Wind block not found.', 'wind.block_not_found');
        }

        return $bytes;
    }
}
