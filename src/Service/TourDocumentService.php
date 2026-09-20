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
 * Assembles a Touren-Dokument PDF for a tour: the tour's own
 * description/photos/map, then one section per POI within
 * POI_PROXIMITY_METERS of any of the tour's routes, then one section per
 * Area any route segment crosses (or starts/ends inside) - see
 * GeometryService for the actual geometry tests. There is no
 * route_poi/route_area relationship in the schema, so both selections are
 * computed fresh on every call from route.points/area.points.
 *
 * POI/Area candidates are scoped to the TOUR OWNER's visibility
 * (PoiRepository/AreaRepository::findByUserWithPublic($tour['user_id'])),
 * not the requesting viewer's - a tour's content is whatever its owner
 * placed, the same way tour_route already includes routes regardless of
 * the route's own public flag.
 */
final class TourDocumentService
{
    private const POI_PROXIMITY_METERS = 200.0;

    public function __construct(
        private readonly TourRepository $tours,
        private readonly RouteRepository $routes,
        private readonly PoiRepository $pois,
        private readonly AreaRepository $areas,
        private readonly ImageStorageService $tourImages,
        private readonly ImageStorageService $poiImages,
        private readonly ImageStorageService $areaImages,
        private readonly StaticMapImageService $staticMaps,
        private readonly ConverterInterface $markdown,
    ) {
    }

    public function generate(int $tourId, string $mapType): string
    {
        $tour = $this->tours->findById($tourId);
        $routes = $this->routes->findByTour($tourId);

        $routePolylines = [];
        foreach ($routes as $route) {
            $polyline = self::decodePoints($route['points'] ?? null);
            if ($polyline !== []) {
                $routePolylines[] = $polyline;
            }
        }

        $pois = $this->selectPois($tour, $routePolylines);
        $areas = $this->selectAreas($tour, $routePolylines);

        $html = $this->buildHtml($tour, $routePolylines, $pois, $areas, $mapType);

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
    public function selectPois(array $tour, array $routePolylines): array
    {
        if ($routePolylines === []) {
            return [];
        }

        $selected = [];
        foreach ($this->pois->findByUserWithPublic((int) $tour['user_id']) as $poi) {
            $point = ['lat' => (float) $poi['latitude'], 'lng' => (float) $poi['longitude']];
            foreach ($routePolylines as $polyline) {
                if (GeometryService::isPointNearPolyline($point, $polyline, self::POI_PROXIMITY_METERS)) {
                    $selected[] = $poi;
                    continue 2;
                }
            }
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
            foreach ($routePolylines as $polyline) {
                if (GeometryService::doesPolylineIntersectPolygon($polyline, $polygon)) {
                    $selected[] = $area;
                    continue 2;
                }
            }
        }

        usort($selected, static fn (array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        return $selected;
    }

    /**
     * @param array<int,array<int,array{lat:float,lng:float}>> $routePolylines
     * @param list<array<string,mixed>> $pois
     * @param list<array<string,mixed>> $areas
     */
    private function buildHtml(array $tour, array $routePolylines, array $pois, array $areas, string $mapType): string
    {
        $html = '<html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>';

        $html .= '<section>';
        $html .= '<h1>' . self::esc((string) $tour['name']) . '</h1>';
        $html .= '<p class="meta">' . self::esc(number_format(((float) ($tour['total_length'] ?? 0)) / 1000.0, 2)) . ' km</p>';
        $html .= '<div class="description">' . $this->markdownToHtml($tour['description'] ?? null) . '</div>';
        $html .= $this->galleryHtml($this->tourImages, (int) $tour['id'], $this->tours->getImages((int) $tour['id']));

        $overviewMap = $this->staticMaps->forRoutes($routePolylines, $mapType);
        if ($overviewMap !== null) {
            $html .= '<img class="map" src="' . self::pngDataUri($overviewMap) . '">';
        }
        $html .= '</section>';

        foreach ($pois as $poi) {
            $html .= '<section class="page-break">';
            $html .= '<h2>' . self::esc((string) $poi['name']) . '</h2>';
            $html .= '<div class="description">' . $this->markdownToHtml($poi['description'] ?? null) . '</div>';
            $html .= $this->galleryHtml($this->poiImages, (int) $poi['id'], $this->pois->getImages((int) $poi['id']));

            $poiMap = $this->staticMaps->forPoint((float) $poi['latitude'], (float) $poi['longitude'], $mapType);
            if ($poiMap !== null) {
                $html .= '<img class="map" src="' . self::pngDataUri($poiMap) . '">';
            }
            $html .= '</section>';
        }

        foreach ($areas as $area) {
            $html .= '<section class="page-break">';
            $html .= '<h2>' . self::esc((string) $area['name']) . '</h2>';
            $html .= '<div class="description">' . $this->markdownToHtml($area['description'] ?? null) . '</div>';
            $html .= $this->galleryHtml($this->areaImages, (int) $area['id'], $this->areas->getImages((int) $area['id']));

            $polygon = self::decodePoints($area['points'] ?? null);
            $areaMap = $this->staticMaps->forPolygon($polygon, $mapType, (string) ($area['color'] ?? '#00FF30'));
            if ($areaMap !== null) {
                $html .= '<img class="map" src="' . self::pngDataUri($areaMap) . '">';
            }
            $html .= '</section>';
        }

        $html .= '</body></html>';

        return $html;
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
            .meta { color: #666; font-size: 9pt; margin-top: 0; }
            .description { margin: 8pt 0; }
            .gallery { margin: 8pt 0; }
            .photo { max-width: 45%; max-height: 160pt; margin: 2pt; }
            .map { width: 100%; max-height: 300pt; margin-top: 8pt; }
            section.page-break { page-break-before: always; }
            CSS;
    }
}
