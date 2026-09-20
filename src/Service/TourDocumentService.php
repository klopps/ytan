<?php

declare(strict_types=1);

namespace Ytan\Service;

use Dompdf\Dompdf;
use League\CommonMark\ConverterInterface;
use Ytan\Domain\Area\AreaRepository;
use Ytan\Domain\Poi\PoiRepository;
use Ytan\Domain\Route\RouteRepository;
use Ytan\Domain\Tour\TourRepository;

/**
 * Assembles a Touren-Dokument PDF for a tour, structured to the fixed order
 * given in todo.md's "Tour-Dokument" item: a tour overview section (name,
 * total length, a table of each route's own name/length, description,
 * a map of every route combined), a single forced page break, then one
 * section per route (name, description, a map of just that route) each
 * followed by one sub-entry per POI within POI_PROXIMITY_METERS of that
 * specific route and per Area that route's line crosses (or starts/ends
 * inside) - see GeometryService for the actual geometry tests. A POI/Area
 * is only included if it has a description and/or at least one image;
 * a bare marker with neither would render as an empty sub-entry. There is
 * no route_poi/route_area relationship in the schema, so both selections
 * are computed fresh on every call from route.points/area.points, and
 * per-route rather than tour-wide - the same POI/Area can appear under
 * more than one route section if it qualifies for several, in which case
 * it keeps the same document-wide number (see $entryNumbers in
 * buildHtml()) on every map/sub-entry it shows up in.
 *
 * POI/Area candidates are scoped to the TOUR OWNER's visibility
 * (PoiRepository/AreaRepository::findByUserWithPublic($tour['user_id'])),
 * not the requesting viewer's - a tour's content is whatever its owner
 * placed, the same way tour_route already includes routes regardless of
 * the route's own public flag.
 */
final class TourDocumentService
{
    public const DEFAULT_POI_PROXIMITY_METERS = 200.0;

    // Hybrid/Satellite are 403-rejected by Google's Static Maps API for
    // EEA-region accounts (see StaticMapImageService), so the map type is
    // no longer a user choice - Terrain is the only one that reliably works.
    private const MAP_TYPE = 'terrain';

    /** @var array<int,string|null> */
    private array $poiIconCache = [];
    private ?string $areaIconCache = null;

    public function __construct(
        private readonly TourRepository $tours,
        private readonly RouteRepository $routes,
        private readonly PoiRepository $pois,
        private readonly AreaRepository $areas,
        private readonly ImageStorageService $poiImages,
        private readonly ImageStorageService $areaImages,
        private readonly StaticMapImageService $staticMaps,
        private readonly ConverterInterface $markdown,
        private readonly string $markersDir,
        private readonly Translator $translator,
    ) {
    }

    public function generate(int $tourId, float $poiProximityMeters = self::DEFAULT_POI_PROXIMITY_METERS): string
    {
        $tour = $this->tours->findById($tourId);
        $routes = $this->routes->findByTour($tourId);

        $routeEntries = [];
        foreach ($routes as $route) {
            $polyline = self::decodePoints($route['points'] ?? null);
            $routeEntries[] = ['route' => $route, 'polyline' => $polyline];
        }

        $html = $this->buildHtml($tour, $routeEntries, $poiProximityMeters);

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->setPaper('A4');
        $dompdf->loadHtml($html);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param array<int,array<int,array{lat:float,lng:float}>> $routePolylines
     * @return list<array<string,mixed>>
     */
    public function selectPois(array $tour, array $routePolylines, float $radiusMeters = self::DEFAULT_POI_PROXIMITY_METERS): array
    {
        if ($routePolylines === []) {
            return [];
        }

        $selected = [];
        foreach ($this->pois->findByUserWithPublic((int) $tour['user_id']) as $poi) {
            $point = ['lat' => (float) $poi['latitude'], 'lng' => (float) $poi['longitude']];
            $nearRoute = false;
            foreach ($routePolylines as $polyline) {
                if (GeometryService::isPointNearPolyline($point, $polyline, $radiusMeters)) {
                    $nearRoute = true;
                    break;
                }
            }

            if (!$nearRoute || !self::hasContent($poi['description'] ?? null, $this->pois->getImages((int) $poi['id']))) {
                continue;
            }

            $selected[] = $poi;
        }

        usort($selected, static fn (array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $selected;
    }

    /**
     * @param array<int,array<int,array{lat:float,lng:float}>> $routePolylines
     * @return list<array<string,mixed>>
     */
    public function selectAreas(array $tour, array $routePolylines): array
    {
        if ($routePolylines === []) {
            return [];
        }

        $selected = [];
        foreach ($this->areas->findByUserWithPublic((int) $tour['user_id']) as $area) {
            $polygon = self::decodePoints($area['points'] ?? null);
            if (count($polygon) < 3) {
                continue;
            }

            $crossed = false;
            foreach ($routePolylines as $polyline) {
                if (GeometryService::doesPolylineIntersectPolygon($polyline, $polygon)) {
                    $crossed = true;
                    break;
                }
            }

            if (!$crossed || !self::hasContent($area['description'] ?? null, $this->areas->getImages((int) $area['id']))) {
                continue;
            }

            $selected[] = $area;
        }

        usort($selected, static fn (array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $selected;
    }

    private static function hasContent(?string $description, array $images): bool
    {
        return trim((string) $description) !== '' || $images !== [];
    }

    /**
     * @param list<array{route:array<string,mixed>,polyline:list<array{lat:float,lng:float}>}> $routeEntries
     */
    private function buildHtml(array $tour, array $routeEntries, float $poiProximityMeters): string
    {
        // Pre-select every route's POIs/Areas up front rather than inline
        // per-route, so a POI/Area that qualifies for more than one route
        // gets assigned its document-wide number (see $entryNumbers below)
        // the first time it's encountered in route order, and keeps that
        // same number - and marker label - everywhere else it appears.
        $routeSelections = [];
        foreach ($routeEntries as $entry) {
            $routePolylines = $entry['polyline'] !== [] ? [$entry['polyline']] : [];
            $routeSelections[] = [
                'pois' => $this->selectPois($tour, $routePolylines, $poiProximityMeters),
                'areas' => $this->selectAreas($tour, $routePolylines),
            ];
        }

        $entryNumbers = [];
        $nextNumber = 1;
        foreach ($routeSelections as $selection) {
            foreach ($selection['pois'] as $poi) {
                $key = 'poi:' . $poi['id'];
                if (!isset($entryNumbers[$key])) {
                    $entryNumbers[$key] = $nextNumber++;
                }
            }
            foreach ($selection['areas'] as $area) {
                $key = 'area:' . $area['id'];
                if (!isset($entryNumbers[$key])) {
                    $entryNumbers[$key] = $nextNumber++;
                }
            }
        }

        $html = '<html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>';

        $html .= '<section>';
        $html .= '<h1>' . self::esc((string) $tour['name']) . '</h1>';
        $html .= '<p class="meta">' . self::esc(number_format(((float) ($tour['total_length'] ?? 0)) / 1000.0, 2)) . ' km</p>';

        $html .= '<table class="route-table"><thead><tr><th>Route</th><th>Länge</th></tr></thead><tbody>';
        foreach ($routeEntries as $entry) {
            $route = $entry['route'];
            $html .= '<tr><td>' . self::esc((string) $route['name']) . '</td><td>'
                . self::esc(number_format(((float) ($route['length'] ?? 0)) / 1000.0, 2)) . ' km</td></tr>';
        }
        $html .= '</tbody></table>';

        $html .= '<div class="description">' . $this->markdownToHtml($tour['description'] ?? null) . '</div>';

        $overviewRoutes = [];
        foreach ($routeEntries as $entry) {
            if ($entry['polyline'] === []) {
                continue;
            }
            $overviewRoutes[] = ['polyline' => $entry['polyline'], 'color' => (string) ($entry['route']['color'] ?? '#3388FF')];
        }
        $overviewMap = $this->staticMaps->render($overviewRoutes, [], [], self::MAP_TYPE);
        if ($overviewMap !== null) {
            $html .= '<img class="map" src="' . self::pngDataUri($overviewMap) . '">';
        }
        $html .= '</section>';

        $firstRoute = true;
        foreach ($routeEntries as $index => $entry) {
            $route = $entry['route'];
            $polyline = $entry['polyline'];
            $pois = $routeSelections[$index]['pois'];
            $areasForRoute = $routeSelections[$index]['areas'];

            $html .= '<section' . ($firstRoute ? ' class="page-break"' : '') . '>';
            $firstRoute = false;

            $html .= '<h2>' . self::esc((string) $route['name']) . '</h2>';
            $html .= '<p class="meta">' . self::esc(number_format(((float) ($route['length'] ?? 0)) / 1000.0, 2)) . ' km</p>';
            $html .= '<div class="description">' . $this->markdownToHtml($route['description'] ?? null) . '</div>';

            if ($polyline !== []) {
                $markers = [];
                foreach ($pois as $poi) {
                    $number = $entryNumbers['poi:' . $poi['id']];
                    $markers[] = [
                        'lat' => (float) $poi['latitude'],
                        'lng' => (float) $poi['longitude'],
                        'label' => self::markerLabel($number),
                    ];
                }

                $areaShapes = [];
                foreach ($areasForRoute as $area) {
                    $number = $entryNumbers['area:' . $area['id']];
                    $areaShapes[] = [
                        'polygon' => self::decodePoints($area['points'] ?? null),
                        'label' => self::markerLabel($number),
                        'color' => (string) ($area['color'] ?? '#00FF30'),
                    ];
                }

                $routeColor = (string) ($route['color'] ?? '#3388FF');
                $routeMap = $this->staticMaps->render(
                    [['polyline' => $polyline, 'color' => $routeColor]],
                    $markers,
                    $areaShapes,
                    self::MAP_TYPE
                );
                if ($routeMap !== null) {
                    $html .= '<img class="map" src="' . self::pngDataUri($routeMap) . '">';
                }
            }

            foreach ($pois as $poi) {
                $number = $entryNumbers['poi:' . $poi['id']];
                $icon = $this->poiIconDataUri((int) $poi['poitype_id']);
                $typeLabel = $this->poiTypeLabel((int) $poi['poitype_id']);
                $html .= '<div class="sub-entry">';
                $html .= '<h3><span class="entry-number">' . $number . '</span> '
                    . self::esc((string) $poi['name']) . '</h3>';
                $html .= '<div class="entry-type">'
                    . ($icon !== null ? '<img class="entry-icon" src="' . $icon . '">' : '')
                    . self::esc($typeLabel) . '</div>';
                $html .= '<div class="description">' . $this->markdownToHtml($poi['description'] ?? null) . '</div>';
                $html .= $this->galleryHtml($this->poiImages, (int) $poi['id'], $this->pois->getImages((int) $poi['id']));
                $html .= '</div>';
            }

            foreach ($areasForRoute as $area) {
                $number = $entryNumbers['area:' . $area['id']];
                $icon = $this->areaIconDataUri();
                $html .= '<div class="sub-entry">';
                $html .= '<h3><span class="entry-number">' . $number . '</span> '
                    . self::esc((string) $area['name']) . '</h3>';
                $html .= '<div class="entry-type">'
                    . ($icon !== null ? '<img class="entry-icon" src="' . $icon . '">' : '')
                    . self::esc($this->translator->t('app.tourdoc.area_type')) . '</div>';
                $html .= '<div class="description">' . $this->markdownToHtml($area['description'] ?? null) . '</div>';
                $html .= $this->galleryHtml($this->areaImages, (int) $area['id'], $this->areas->getImages((int) $area['id']));
                $html .= '</div>';
            }

            $html .= '</section>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * A Static Maps marker label must be a single uppercase alphanumeric
     * character - digits '1'-'9' cover the first nine entries, then
     * 'A'-'Z' the next 26 (35 distinct labels in total, comfortably more
     * than any real tour's POI+Area count). Beyond that the marker still
     * renders, just without a legible digit on the pin itself - the
     * document text next to it still shows the real number.
     */
    private static function markerLabel(int $number): string
    {
        if ($number >= 1 && $number <= 9) {
            return (string) $number;
        }
        if ($number >= 10 && $number <= 35) {
            return chr(ord('A') + ($number - 10));
        }

        return '';
    }

    /**
     * The same per-type marker icon shown on the map itself
     * (public/markers/poi_{poitype_id}_mapicons_square16.png, already
     * used client-side for map-search-result rows) placed in front of a
     * POI sub-entry's name, so its type is recognizable without having
     * to cross-reference the map. Cached per request since a POI type
     * commonly repeats across a document's sub-entries. Poitype 0
     * ("undefined") has no square16 asset - and any other type ID
     * without one on disk - simply renders without an icon rather than
     * a broken image.
     */
    private function poiTypeLabel(int $poitypeId): string
    {
        return $this->translator->t('app.poitype.' . $poitypeId);
    }

    private function poiIconDataUri(int $poitypeId): ?string
    {
        if (array_key_exists($poitypeId, $this->poiIconCache)) {
            return $this->poiIconCache[$poitypeId];
        }

        $file = rtrim($this->markersDir, '/\\') . '/poi_' . $poitypeId . '_mapicons_square16.png';
        $uri = is_file($file) ? self::fileDataUri($file, 'image/png') : null;

        return $this->poiIconCache[$poitypeId] = $uri;
    }

    /**
     * Areas have no per-instance type the way POIs do, so a single
     * generic pentagon icon (matching #editToolbar's "create area"
     * glyph) stands in for all of them.
     */
    private function areaIconDataUri(): ?string
    {
        if ($this->areaIconCache !== null) {
            return $this->areaIconCache;
        }

        $file = rtrim($this->markersDir, '/\\') . '/area_symbol.png';

        return $this->areaIconCache = is_file($file) ? self::fileDataUri($file, 'image/png') : null;
    }

    private static function fileDataUri(string $file, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . base64_encode((string) file_get_contents($file));
    }

    private function galleryHtml(ImageStorageService $service, int $entityId, array $images): string
    {
        if ($images === []) {
            return '';
        }

        $html = '<div class="gallery">';
        foreach ($images as $image) {
            $file = $service->read($entityId, $image['filename']);
            if ($file === null) {
                continue;
            }
            $uri = 'data:' . $image['mime_type'] . ';base64,' . base64_encode($file['contents']);
            $html .= '<img class="photo" src="' . $uri . '">';
        }
        $html .= '</div>';

        return $html;
    }

    private function markdownToHtml(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        return (string) $this->markdown->convert($text);
    }

    /**
     * @return list<array{lat:float,lng:float}>
     */
    private static function decodePoints(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $points = [];
        foreach ($decoded as $point) {
            if (is_array($point) && isset($point['lat'], $point['lng'])) {
                $points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        return $points;
    }

    private static function pngDataUri(string $bytes): string
    {
        return 'data:image/png;base64,' . base64_encode($bytes);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
            body { font-family: sans-serif; font-size: 11pt; color: #222; }
            h1 { font-size: 20pt; margin-bottom: 4pt; }
            h2 { font-size: 15pt; margin-bottom: 4pt; }
            h3 { font-size: 12pt; margin-bottom: 2pt; }
            .meta { color: #666; font-size: 9pt; margin-top: 0; }
            .description { margin: 8pt 0; }
            .gallery { margin: 8pt 0; }
            .photo { max-width: 45%; max-height: 160pt; margin: 2pt; }
            .map { width: 100%; max-height: 300pt; margin-top: 8pt; }
            .route-table { border-collapse: collapse; margin: 8pt 0; }
            .route-table th, .route-table td { border: 1px solid #ccc; padding: 3pt 8pt; text-align: left; font-size: 10pt; }
            .sub-entry { margin-top: 12pt; padding-top: 8pt; border-top: 1px solid #ddd; }
            .entry-number { display: inline-block; min-width: 16pt; padding: 0 3pt; margin-right: 2pt; background: #d33; color: #fff; border-radius: 8pt; text-align: center; font-size: 10pt; font-weight: bold; }
            .entry-type { margin-bottom: 4pt; color: #666; font-size: 9pt; }
            .entry-icon { width: 12pt; height: 12pt; vertical-align: middle; margin-right: 3pt; }
            section.page-break { page-break-before: always; }
            CSS;
    }
}
