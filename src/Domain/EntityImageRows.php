<?php

declare(strict_types=1);

namespace Ytan\Domain;

/**
 * The photo rows of a POI, an area, a route or a tour: table
 * `{entity}_image` with `{entity}_id`, `filename`, `sort_order`,
 * `mime_type`, `size_bytes`, `uploaded_at`. Until 2026-11-15 the same six
 * methods were copied into each of the four repositories.
 *
 * The using repository has the PDO in $this->db and names its entity
 * ('poi', 'area', 'route', 'tour' - a constant, never user input, since it
 * becomes part of the SQL).
 */
trait EntityImageRows
{
    abstract protected function imageEntity(): string;

    private function imageTable(): string
    {
        return $this->imageEntity() . '_image';
    }

    private function imageKey(): string
    {
        return $this->imageEntity() . '_id';
    }

    /** @return list<array<string,mixed>> the entity's photos in their order */
    public function getImages(int $entityId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, filename, sort_order, mime_type, size_bytes, uploaded_at FROM {$this->imageTable()} WHERE {$this->imageKey()} = ? ORDER BY sort_order"
        );
        $stmt->execute([$entityId]);

        return $stmt->fetchAll();
    }

    /**
     * Every photo row of every entity of this kind, for
     * ImageReconciliationService's admin cleanup tool.
     *
     * @return list<array{id:int, entity_id:int, filename:string, size_bytes:int}>
     */
    public function getAllImages(): array
    {
        return $this->db->query("SELECT id, {$this->imageKey()} AS entity_id, filename, size_bytes FROM {$this->imageTable()}")->fetchAll();
    }

    public function findImage(int $entityId, int $imageId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->imageTable()} WHERE id = ? AND {$this->imageKey()} = ?");
        $stmt->execute([$imageId, $entityId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countImages(int $entityId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->imageTable()} WHERE {$this->imageKey()} = ?");
        $stmt->execute([$entityId]);

        return (int) $stmt->fetchColumn();
    }

    /** Appends a photo behind the existing ones; returns all of them. */
    public function addImage(int $entityId, string $filename, string $mimeType, int $sizeBytes): array
    {
        $stmt = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM {$this->imageTable()} WHERE {$this->imageKey()} = ?");
        $stmt->execute([$entityId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $this->db->prepare(
            "INSERT INTO {$this->imageTable()} ({$this->imageKey()}, filename, sort_order, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$entityId, $filename, $nextOrder, $mimeType, $sizeBytes]);

        return $this->getImages($entityId);
    }

    public function removeImage(int $entityId, int $imageId): void
    {
        $this->db->prepare("DELETE FROM {$this->imageTable()} WHERE id = ? AND {$this->imageKey()} = ?")->execute([$imageId, $entityId]);
    }
}
