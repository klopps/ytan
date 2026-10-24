<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * The index.json of the data field downloads (public/app/watch/, written by
 * bin/watch-datafields-json.php for bin\publish-watch.bat, read by watch.js's
 * showWatchDatafields()):
 *
 *   {"version":"202610241200","fields":[{"device":"venu3s","name":"Venu 3S","file":"ytan-venu3s.prg","size":29324}, ...]}
 *
 * The models are the products of watch/manifest.xml, the display names come
 * from the Connect IQ SDK's device files (compiler.json "displayName").
 */
final class WatchDatafieldIndex
{
    /** @return list<string> product ids of watch/manifest.xml, in its order */
    public static function devices(string $manifestXml): array
    {
        preg_match_all('/<iq:product\s+id="([a-z0-9]+)"/', $manifestXml, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Display name of a device from its compiler.json, the trademark signs
     * dropped ("Venu® 3S" -> "Venu 3S"); the product id when unknown.
     */
    public static function displayName(string $device, ?string $compilerJson): string
    {
        $data = $compilerJson !== null ? json_decode($compilerJson, true) : null;
        $name = is_array($data) && is_string($data['displayName'] ?? null) ? $data['displayName'] : '';
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['®', '™'], '', $name)) ?? '');

        return $name !== '' ? $name : $device;
    }

    /**
     * @param array<string, string> $names device => display name
     * @param array<string, int> $sizes device => size of ytan-DEVICE.prg (missing = not built)
     * @return array{version: string, fields: list<array{device: string, name: string, file: string, size: int}>}
     */
    public static function build(array $names, array $sizes, string $version): array
    {
        $fields = [];
        foreach ($names as $device => $name) {
            if (!isset($sizes[$device])) {
                continue;
            }
            $fields[] = ['device' => $device, 'name' => $name, 'file' => 'ytan-' . $device . '.prg', 'size' => $sizes[$device]];
        }
        // Garmin writes "fēnix" - sorted as "fenix", not behind every plain name.
        $key = fn (string $name) => strtr($name, ['ē' => 'e', 'ā' => 'a', 'ī' => 'i', 'ō' => 'o', 'ū' => 'u']);
        usort($fields, fn (array $a, array $b) => strnatcasecmp($key($a['name']), $key($b['name'])));

        return ['version' => $version, 'fields' => $fields];
    }
}
