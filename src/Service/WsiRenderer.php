<?php

declare(strict_types=1);

namespace Ytan\Service;

use SVG\Nodes\Shapes\SVGLine;
use SVG\Nodes\Shapes\SVGPath;
use SVG\Nodes\Shapes\SVGPolyline;
use SVG\SVG;
use Ytan\Exception\ValidationException;

/**
 * Renders a "wind shelter indicator" compass-rose SVG for a 16-sector
 * direction code (e.g. "0000111222211100"), where each character is
 * 0 = no shelter, 1 = partial shelter, 2 = full shelter, one per 22.5deg
 * sector clockwise from north. Results are cached to disk since the
 * same code is requested repeatedly for every map marker of that shape.
 *
 * Ported from the legacy WindIndicator class in PESR's wsi.php. Unlike the
 * legacy version, the code is strictly validated against ^[012]{16}$
 * before it is ever used to build a filesystem path (the legacy code only
 * checked the length, which allowed path traversal via the "code" param).
 */
final class WsiRenderer
{
    private const SIZE_X = 100;
    private const SIZE_Y = 120;
    private const CENTER_X = 50;
    private const CENTER_Y = 75;
    private const RADIUS = 40;
    private const CROSS_HEIGHT = 90;
    private const CROSS_WIDTH = 90;
    private const COLOR_LIGHT = '#ccc';
    private const COLOR_DARK = '#777';

    public function __construct(private readonly string $cacheDir)
    {
    }

    public function render(string $code): string
    {
        if (preg_match('/^[012]{16}$/', $code) !== 1) {
            throw new ValidationException('code must be a 16-character string of 0/1/2.');
        }

        $cacheFile = rtrim($this->cacheDir, '/\\') . '/wsi_' . $code . '.svg';
        if (is_file($cacheFile)) {
            return file_get_contents($cacheFile);
        }

        $svg = $this->draw($code);

        if (!is_dir(dirname($cacheFile))) {
            mkdir(dirname($cacheFile), 0775, true);
        }
        file_put_contents($cacheFile, $svg);

        return $svg;
    }

    private function draw(string $code): string
    {
        $image = new SVG(self::SIZE_X, self::SIZE_Y);
        $doc = $image->getDocument();

        $first = 0;
        $lastMarker = $code[0];

        for ($x = 1; $x <= 16; $x++) {
            $marker = $x > 15 ? '' : $code[$x];

            if ($marker === $lastMarker) {
                continue;
            }

            $color = match ($lastMarker) {
                '2' => self::COLOR_DARK,
                '1' => self::COLOR_LIGHT,
                default => null,
            };

            if ($color !== null) {
                $doc->addChild(
                    (new SVGPath($this->describeArc(self::CENTER_X, self::CENTER_Y, self::RADIUS, 22.5 * $first, 22.5 * $x - 0.01)))
                        ->setStyle('fill', $color)
                );
            }

            $lastMarker = $marker;
            $first = $x;
        }

        $doc->addChild(
            (new SVGLine(self::CENTER_X, self::CENTER_Y - self::CROSS_HEIGHT / 2, self::CENTER_X, self::CENTER_Y + self::CROSS_HEIGHT / 2))
                ->setStyle('stroke', '#000')
                ->setStyle('stroke-width', '2px')
        );
        $doc->addChild(
            (new SVGLine(self::CENTER_X - self::CROSS_WIDTH / 2, self::CENTER_Y, self::CENTER_X + self::CROSS_WIDTH / 2, self::CENTER_Y))
                ->setStyle('stroke', '#000')
                ->setStyle('stroke-width', '2px')
        );

        $doc->addChild(
            (new SVGPolyline([
                [self::CENTER_X - 5, self::CENTER_Y - self::RADIUS - 12],
                [self::CENTER_X - 5, self::CENTER_Y - self::RADIUS - 32],
                [self::CENTER_X + 5, self::CENTER_Y - self::RADIUS - 12],
                [self::CENTER_X + 5, self::CENTER_Y - self::RADIUS - 32],
            ]))
                ->setStyle('stroke', '#000')
                ->setStyle('fill', 'none')
                ->setStyle('stroke-width', '3px')
                ->setStyle('stroke-linejoin', 'bevel')
        );

        return (string) $image;
    }

    private function polarToCartesian(float $cx, float $cy, float $radius, float $angleDeg): array
    {
        $angleRad = ($angleDeg - 90) * M_PI / 180;

        return [
            'x' => $cx + $radius * cos($angleRad),
            'y' => $cy + $radius * sin($angleRad),
        ];
    }

    private function describeArc(float $x, float $y, float $radius, float $startAngle, float $endAngle): string
    {
        $start = $this->polarToCartesian($x, $y, $radius, $endAngle);
        $end = $this->polarToCartesian($x, $y, $radius, $startAngle);
        $arcSweep = ($endAngle - $startAngle) <= 180 ? 0 : 1;

        return implode(' ', [
            'M', $start['x'], $start['y'],
            'A', $radius, $radius, 0, $arcSweep, 0, $end['x'], $end['y'],
            'L', $x, $y,
            'L', $start['x'], $start['y'],
        ]);
    }
}
