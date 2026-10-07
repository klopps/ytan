<?php

declare(strict_types=1);

namespace Ytan\Service;

use Ytan\Exception\ValidationException;

/**
 * Colors of the Garmin watch data field (watch/), configured in YTAN per
 * element and per watch background ("dark" = shown on a black background,
 * "light" = on white):
 *
 *   {"bearing": {"dark": "#00aaff", "light": "#0000ff"}, "distance": ..., "text": ..., "north": ...}
 *
 * bearing = the direction value and the next-waypoint marker, distance = the
 * distance value, text = every other text, north = the north marker. The
 * defaults are what the data field drew before this was configurable
 * (Garmin's COLOR_BLUE/DK_BLUE, GREEN/DK_GREEN, WHITE/BLACK, RED).
 */
final class WatchColors
{
    public const ELEMENTS = ['bearing', 'distance', 'text', 'north'];
    public const BACKGROUNDS = ['dark', 'light'];

    public const DEFAULTS = [
        'bearing' => ['dark' => '#20c0ff', 'light' => '#0000c0'],
        'distance' => ['dark' => '#80ff80', 'light' => '#008000'],
        'text' => ['dark' => '#ffffff', 'light' => '#000000'],
        'north' => ['dark' => '#ff0000', 'light' => '#ff0000'],
    ];

    /**
     * Validates a client-supplied color set; elements/backgrounds it leaves
     * out fall back to the defaults.
     *
     * @return array<string, array<string, string>> the complete set
     */
    public static function normalize(mixed $input): array
    {
        if (!is_array($input)) {
            throw new ValidationException('colors must be an object.');
        }
        foreach ($input as $element => $_) {
            if (!in_array($element, self::ELEMENTS, true)) {
                throw new ValidationException('Unknown color element: ' . (string) $element);
            }
        }

        $colors = self::DEFAULTS;
        foreach (self::ELEMENTS as $element) {
            $pair = $input[$element] ?? [];
            if (!is_array($pair)) {
                throw new ValidationException('colors.' . $element . ' must be an object.');
            }
            foreach ($pair as $background => $_) {
                if (!in_array($background, self::BACKGROUNDS, true)) {
                    throw new ValidationException('Unknown background: ' . (string) $background);
                }
            }
            foreach (self::BACKGROUNDS as $background) {
                if (!isset($pair[$background])) {
                    continue;
                }
                $value = $pair[$background];
                if (!is_string($value) || !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                    throw new ValidationException('colors.' . $element . '.' . $background . ' must be a color like #00aaff.');
                }
                $colors[$element][$background] = strtolower($value);
            }
        }

        return $colors;
    }

    /**
     * The stored set (JSON column, may be NULL) completed with the defaults.
     */
    public static function effective(?string $json): array
    {
        $stored = $json === null ? null : json_decode($json, true);
        if (!is_array($stored)) {
            return self::DEFAULTS;
        }
        try {
            return self::normalize($stored);
        } catch (ValidationException) {
            return self::DEFAULTS;
        }
    }

    /**
     * Flat RGB integers for the watch, which has no use for JSON objects:
     * [bearing dark, bearing light, distance dark, distance light, text dark,
     * text light, north dark, north light].
     *
     * @return int[]
     */
    public static function flatten(array $colors): array
    {
        $flat = [];
        foreach (self::ELEMENTS as $element) {
            foreach (self::BACKGROUNDS as $background) {
                $flat[] = (int) hexdec(ltrim($colors[$element][$background], '#'));
            }
        }

        return $flat;
    }
}
