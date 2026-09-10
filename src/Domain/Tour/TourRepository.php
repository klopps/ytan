<?php

declare(strict_types=1);

namespace Ytan\Domain\Tour;

use PDO;
use Ytan\Exception\NotFoundException;

final class TourRepository
{
    private const SELECT_ONE = 'SELECT tour.*, user.username AS creator_username FROM tour JOIN user ON user.id = tour.user_id WHERE tour.id = ?';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Replaces the old findPublic()/findByUser()/findByUserWithPublic() -
     * one query builder covering scope plus the optional search/length
     * filters from the Tours list screen (Touren.md: search by name,
     * description, creator, length range).
     *
     * @param array{search?:string,min_length?:int,max_length?:int} $filters
     */
    public function search(string $scope, ?int $userId, array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = $this->buildSearchWhere($scope, $userId, $filters);

        $sql = 'SELECT tour.*, user.username AS creator_username,
                (SELECT COUNT(*) FROM tour_route WHERE tour_route.tour_id = tour.id) AS route_count
                FROM tour JOIN user ON user.id = tour.user_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY tour.name' . $this->limitSuffix($limit, $offset);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Total matching count for search()'s same scope/filters, ignoring
     * limit/offset - what the Tours panel's pagination bar needs to know
     * how many pages exist.
     *
     * @param array{search?:string,min_length?:int,max_length?:int} $filters
     */
    public function countSearch(string $scope, ?int $userId, array $filters = []): int
    {
        [$where, $params] = $this->buildSearchWhere($scope, $userId, $filters);

        $sql = 'SELECT COUNT(*) FROM tour JOIN user ON user.id = tour.user_id WHERE ' . implode(' AND ', $where);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{search?:string,min_length?:int,max_length?:int} $filters
     * @return array{0: string[], 1: array<int,mixed>}
     */
    private function buildSearchWhere(string $scope, ?int $userId, array $filters): array
    {
        $where = [];
        $params = [];

        if ($scope === 'mine') {
            $where[] = 'tour.user_id = ?';
            $params[] = $userId;
        } elseif ($scope === 'mine_public') {
            $where[] = '(tour.user_id = ? OR tour.public = 1)';
            $params[] = $userId;
        } else {
            $where[] = 'tour.public = 1';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(tour.name LIKE ? OR tour.description LIKE ? OR user.username LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (isset($filters['min_length'])) {
            $where[] = 'tour.total_length >= ?';
            $params[] = (int) $filters['min_length'];
        }
        if (isset($filters['max_length'])) {
            $where[] = 'tour.total_length <= ?';
            $params[] = (int) $filters['max_length'];
        }

        return [$where, $params];
    }

    private function limitSuffix(?int $limit, int $offset): string
    {
        return $limit !== null ? ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset) : '';
    }

    public function findById(int $id): array
    {
        $stmt = $this->db->prepare(self::SELECT_ONE);
        $stmt->execute([$id]);
        $tour = $stmt->fetch();

        if ($tour === false) {
            throw new NotFoundException("Tour $id not found.");
        }

        return $tour;
    }

    public function create(int $userId, array $data): array
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tour (user_id, name, description, public, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([$userId, $data['name'], $data['description'] ?? null]);

        return $this->findById((int) $this->db->lastInsertId());
    }

    /**
     * Metadata only (name/description) - publishing is a separate action
     * with its own right (setPublished() below), deliberately not folded
     * in here so a tour_create-only user can never sneak a publish through
     * the edit form.
     */
    public function update(int $id, array $data): array
    {
        $this->findById($id); // 404s if missing

        $stmt = $this->db->prepare('UPDATE tour SET name=?, description=?, updated_at=NOW() WHERE id=?');
        $stmt->execute([$data['name'], $data['description'] ?? null, $id]);

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->findById($id); // 404s if missing
        $this->db->prepare('DELETE FROM tour WHERE id = ?')->execute([$id]);
    }

    public function setPublished(int $id, bool $public, ?int $publishedBy): array
    {
        $this->findById($id); // 404s if missing

        if ($public) {
            $this->db->prepare('UPDATE tour SET public=1, published_by=?, published_at=NOW(), updated_at=NOW() WHERE id=?')
                ->execute([$publishedBy, $id]);
        } else {
            $this->db->prepare('UPDATE tour SET public=0, updated_at=NOW() WHERE id=?')->execute([$id]);
        }

        return $this->findById($id);
    }

    /**
     * Deep-copies a tour's metadata, route membership (same routes, not
     * duplicated ones) and tags for $newOwnerId - the copy always starts
     * private, never inheriting the source's public status. Images are
     * deliberately not copied (kept as a v1 simplification).
     */
    public function copy(int $sourceId, int $newOwnerId): array
    {
        $source = $this->findById($sourceId);
        $name = $this->uniqueCopyName($source['name'], $newOwnerId);

        $stmt = $this->db->prepare(
            'INSERT INTO tour (user_id, name, description, public, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())'
        );
        $stmt->execute([$newOwnerId, $name, $source['description']]);
        $newId = (int) $this->db->lastInsertId();

        $stmt = $this->db->prepare('SELECT route_id, sort_order FROM tour_route WHERE tour_id = ? ORDER BY sort_order');
        $stmt->execute([$sourceId]);
        $insertRoute = $this->db->prepare('INSERT INTO tour_route (tour_id, route_id, sort_order) VALUES (?, ?, ?)');
        foreach ($stmt->fetchAll() as $row) {
            $insertRoute->execute([$newId, $row['route_id'], $row['sort_order']]);
        }

        $this->setTags($newId, $this->getTags($sourceId));
        $this->recalculateTotalLength($newId);

        return $this->findById($newId);
    }

    /**
     * "$baseName (Copy)", or "$baseName (Copy 2)"/"(Copy 3)"/... if that (or
     * an earlier-numbered candidate) already exists among $ownerId's own
     * tours - only the first copy is left unnumbered, matching how a user
     * would expect their own copies to read in a list. Scoped to the new
     * owner's tours rather than globally, since that's the only list a
     * clashing name would actually be confusing in.
     */
    private function uniqueCopyName(string $baseName, int $ownerId): string
    {
        $candidate = $baseName . ' (Copy)';
        if (!$this->nameExistsForOwner($candidate, $ownerId)) {
            return $candidate;
        }

        for ($n = 2; ; $n++) {
            $candidate = $baseName . ' (Copy ' . $n . ')';
            if (!$this->nameExistsForOwner($candidate, $ownerId)) {
                return $candidate;
            }
        }
    }

    private function nameExistsForOwner(string $name, int $ownerId): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tour WHERE user_id = ? AND name = ?');
        $stmt->execute([$ownerId, $name]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Adds a route to a tour (appended after the current last route) and
     * recalculates the tour's cached total_length. Duplicate adds are
     * silently ignored (tour_route has a UNIQUE(tour_id, route_id) key).
     */
    public function addRoute(int $tourId, int $routeId): array
    {
        $this->findById($tourId); // 404s if missing

        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tour_route WHERE tour_id = ?');
        $stmt->execute([$tourId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $this->db->prepare('INSERT IGNORE INTO tour_route (tour_id, route_id, sort_order) VALUES (?, ?, ?)')
            ->execute([$tourId, $routeId, $nextOrder]);

        $this->recalculateTotalLength($tourId);

        return $this->findById($tourId);
    }

    public function removeRoute(int $tourId, int $routeId): array
    {
        $this->findById($tourId); // 404s if missing

        $this->db->prepare('DELETE FROM tour_route WHERE tour_id = ? AND route_id = ?')
            ->execute([$tourId, $routeId]);

        $this->recalculateTotalLength($tourId);

        return $this->findById($tourId);
    }

    /**
     * @param int[] $routeIds the tour's route ids in their new order
     */
    public function reorderRoutes(int $tourId, array $routeIds): array
    {
        $this->findById($tourId); // 404s if missing

        $stmt = $this->db->prepare('UPDATE tour_route SET sort_order = ? WHERE tour_id = ? AND route_id = ?');
        foreach (array_values($routeIds) as $order => $routeId) {
            $stmt->execute([$order, $tourId, (int) $routeId]);
        }

        return $this->findById($tourId);
    }

    public function recalculateTotalLength(int $tourId): void
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(SUM(route.length), 0) FROM tour_route
             JOIN route ON route.id = tour_route.route_id
             WHERE tour_route.tour_id = ?'
        );
        $stmt->execute([$tourId]);
        $total = (int) $stmt->fetchColumn();

        $this->db->prepare('UPDATE tour SET total_length = ? WHERE id = ?')->execute([$total, $tourId]);
    }

    /**
     * Tours that contain a given route - used both to recalculate
     * total_length and to notify each tour's creator when the route's own
     * length changes or the route is deleted (RouteController).
     *
     * @return array<int,array<string,mixed>> full tour rows
     */
    public function findByRoute(int $routeId): array
    {
        $stmt = $this->db->prepare(
            'SELECT tour.* FROM tour_route JOIN tour ON tour.id = tour_route.tour_id WHERE tour_route.route_id = ?'
        );
        $stmt->execute([$routeId]);

        return $stmt->fetchAll();
    }

    /* ------------------------------------------------------------- Tags */

    public function getTags(int $tourId): array
    {
        $stmt = $this->db->prepare('SELECT tag FROM tour_tag WHERE tour_id = ? ORDER BY tag');
        $stmt->execute([$tourId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param int[] $tourIds
     * @return array<int,string[]> tour id => tags, for bulk-attaching to a
     *         search()/index() result without one query per row
     */
    public function getTagsForTours(array $tourIds): array
    {
        if ($tourIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tourIds), '?'));
        $stmt = $this->db->prepare("SELECT tour_id, tag FROM tour_tag WHERE tour_id IN ($placeholders) ORDER BY tag");
        $stmt->execute($tourIds);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['tour_id']][] = $row['tag'];
        }

        return $result;
    }

    /**
     * Replaces a tour's full tag set. Trims whitespace, drops empties and
     * anything over 50 chars, and dedupes case-insensitively (keeping the
     * first casing seen) rather than rejecting near-duplicates outright.
     */
    public function setTags(int $tourId, array $tags): void
    {
        $this->db->prepare('DELETE FROM tour_tag WHERE tour_id = ?')->execute([$tourId]);

        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || mb_strlen($tag) > 50) {
                continue;
            }
            $key = mb_strtolower($tag);
            if (!isset($clean[$key])) {
                $clean[$key] = $tag; // first casing seen wins, not the last
            }
        }

        if ($clean === []) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO tour_tag (tour_id, tag) VALUES (?, ?)');
        foreach ($clean as $tag) {
            $stmt->execute([$tourId, $tag]);
        }
    }

    /* ---------------------------------------------------------- Images */

    public function getImages(int $tourId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, filename, sort_order, mime_type, size_bytes, uploaded_at FROM tour_image WHERE tour_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$tourId]);

        return $stmt->fetchAll();
    }

    public function findImage(int $tourId, int $imageId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tour_image WHERE id = ? AND tour_id = ?');
        $stmt->execute([$imageId, $tourId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countImages(int $tourId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tour_image WHERE tour_id = ?');
        $stmt->execute([$tourId]);

        return (int) $stmt->fetchColumn();
    }

    public function addImage(int $tourId, string $filename, string $mimeType, int $sizeBytes): array
    {
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM tour_image WHERE tour_id = ?');
        $stmt->execute([$tourId]);
        $nextOrder = (int) $stmt->fetchColumn();

        $this->db->prepare(
            'INSERT INTO tour_image (tour_id, filename, sort_order, mime_type, size_bytes, uploaded_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$tourId, $filename, $nextOrder, $mimeType, $sizeBytes]);

        return $this->getImages($tourId);
    }

    public function removeImage(int $tourId, int $imageId): void
    {
        $this->db->prepare('DELETE FROM tour_image WHERE id = ? AND tour_id = ?')->execute([$imageId, $tourId]);
    }

    /**
     * @param int[] $imageIds
     */
    public function reorderImages(int $tourId, array $imageIds): void
    {
        $stmt = $this->db->prepare('UPDATE tour_image SET sort_order = ? WHERE tour_id = ? AND id = ?');
        foreach (array_values($imageIds) as $order => $imageId) {
            $stmt->execute([$order, $tourId, (int) $imageId]);
        }
    }
}
