<?php

declare(strict_types=1);

namespace Ytan\Service;

/**
 * Compares each entity type's DB image rows (tour_image/poi_image/
 * route_image/area_image, via each repository's getAllImages()) against
 * what's actually on disk (ImageStorageService::listStoredFiles()) for the
 * /admin/image-cleanup tool (AdminController). Stateless, re-scans on every
 * call - same reasoning as TranslationUsageScanner: cheap enough at this
 * data volume that caching would only add staleness risk for no real gain.
 *
 * Two kinds of drift are possible and both are reported:
 * - "orphaned file": a file on disk with no matching DB row (e.g. a photo
 *   left behind because an entity's delete() never cleaned up its files -
 *   the bug this tool was built to clean up after).
 * - "dangling row": a DB row whose file is missing on disk (e.g. the file
 *   write succeeded but the DB insert never committed) - would only ever
 *   404 if served, so it's equally safe to remove.
 *
 * A file and a row are matched by entity_id+filename, since a poi_image
 * row's filename always corresponds 1:1 with a real file's name (never
 * client-supplied, see ImageStorageService's own doc comment) - matching
 * on this pair is exact, not a heuristic.
 */
final class ImageReconciliationService
{
    /**
     * @param array<string, array{repository: object, images: ImageStorageService}> $entities
     *        Keyed by entity type ('tour'/'poi'/'route'/'area'); each
     *        repository must provide getAllImages() (id, entity_id,
     *        filename, size_bytes) and removeImage(int $entityId, int $id).
     */
    public function __construct(private readonly array $entities)
    {
    }

    /**
     * @return array{
     *     orphaned_files: list<array{type:string, entity_id:int, filename:string, size_bytes:int}>,
     *     dangling_rows: list<array{type:string, entity_id:int, image_id:int, filename:string}>
     * }
     */
    public function scan(): array
    {
        $orphanedFiles = [];
        $danglingRows = [];

        foreach ($this->entities as $type => $config) {
            $dbRows = $config['repository']->getAllImages();
            $diskFiles = $config['images']->listStoredFiles();

            $dbByKey = [];
            foreach ($dbRows as $row) {
                $dbByKey[$row['entity_id'] . ':' . $row['filename']] = true;
            }
            $diskByKey = [];
            foreach ($diskFiles as $file) {
                $diskByKey[$file['entity_id'] . ':' . $file['filename']] = true;
            }

            foreach ($diskFiles as $file) {
                if (!isset($dbByKey[$file['entity_id'] . ':' . $file['filename']])) {
                    $orphanedFiles[] = [
                        'type' => $type,
                        'entity_id' => $file['entity_id'],
                        'filename' => $file['filename'],
                        'size_bytes' => $file['size_bytes'],
                    ];
                }
            }
            foreach ($dbRows as $row) {
                if (!isset($diskByKey[$row['entity_id'] . ':' . $row['filename']])) {
                    $danglingRows[] = [
                        'type' => $type,
                        'entity_id' => $row['entity_id'],
                        'image_id' => $row['id'],
                        'filename' => $row['filename'],
                    ];
                }
            }
        }

        return ['orphaned_files' => $orphanedFiles, 'dangling_rows' => $danglingRows];
    }

    public function deleteOrphanedFile(string $type, int $entityId, string $filename): void
    {
        $this->entities[$type]['images']->delete($entityId, $filename);
    }

    public function deleteDanglingRow(string $type, int $entityId, int $imageId): void
    {
        $this->entities[$type]['repository']->removeImage($entityId, $imageId);
    }
}
