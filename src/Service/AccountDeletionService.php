<?php

declare(strict_types=1);

namespace Ytan\Service;

use PDO;
use Ytan\Domain\Tour\TourRepository;
use Ytan\Exception\NotFoundException;
use Ytan\Exception\ValidationException;

/**
 * Deletes a user account together with its content (todo.md "Kontolöschung",
 * done.md): PRIVATE POIs, routes, areas and tours - with their photos - are
 * deleted, PUBLIC ones stay visible and move to a successor admin, so a
 * shared route or tour doesn't vanish for everybody else. Successor: the
 * admin named "ytan" (any casing), else the admin with the smallest id -
 * never the deleted user. Used by the self-service flow (AuthController,
 * after password + mailed link) and by the admin's user deletion.
 *
 * A transferred route loses its recording data (recorded_at/duration):
 * when and how long someone paddled is about that person, and the route
 * would otherwise read "recorded by <successor>".
 */
final class AccountDeletionService
{
    /** entity => [table, image table] */
    private const ENTITIES = [
        'tour' => ['tour', 'tour_image'],
        'route' => ['route', 'route_image'],
        'area' => ['area', 'area_image'],
        'poi' => ['poi', 'poi_image'],
    ];

    /**
     * @param array<string, ImageStorageService> $imageStores keyed tour/route/area/poi
     */
    public function __construct(
        private readonly PDO $db,
        private readonly TourRepository $tours,
        private readonly array $imageStores,
    ) {
    }

    /** The admin that receives a deleted user's public content, null when there is none. */
    public function successor(int $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, username FROM user WHERE is_admin = 1 AND id <> ?
             ORDER BY LOWER(username) = 'ytan' DESC, id ASC LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'username' => (string) $row['username']];
    }

    /**
     * What a deletion would do - shown to the user before they ask for it.
     *
     * @return array{private: array<string,int>, public: array<string,int>, successor: ?string}
     */
    public function preview(int $userId): array
    {
        $private = [];
        $public = [];
        foreach (self::ENTITIES as $entity => [$table]) {
            $stmt = $this->db->prepare("SELECT public, COUNT(*) FROM `$table` WHERE user_id = ? GROUP BY public");
            $stmt->execute([$userId]);
            $counts = array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
            $private[$entity] = ($counts[0] ?? 0);
            $public[$entity] = array_sum($counts) - $private[$entity];
        }

        return ['private' => $private, 'public' => $public, 'successor' => $this->successor($userId)['username'] ?? null];
    }

    /**
     * Throws when the account can't be deleted: the last admin, or public
     * content without anybody to hand it to.
     */
    public function assertDeletable(int $userId): void
    {
        $stmt = $this->db->prepare('SELECT is_admin FROM user WHERE id = ?');
        $stmt->execute([$userId]);
        $isAdmin = $stmt->fetchColumn();
        if ($isAdmin === false) {
            throw new NotFoundException('User not found.');
        }

        if ($this->successor($userId) === null) {
            throw new ValidationException(
                (int) $isAdmin === 1 ? 'The last administrator account cannot be deleted.' : 'There is no administrator to take over the public content.',
                (int) $isAdmin === 1 ? 'account.last_admin' : 'account.no_successor'
            );
        }
    }

    /**
     * Deletes the account. One transaction; photo files of deleted content
     * are removed only after the commit.
     *
     * @return array{deleted: array<string,int>, transferred: array<string,int>, successor: string}
     */
    public function delete(int $userId): array
    {
        $this->assertDeletable($userId);
        $successor = $this->successor($userId);
        $preview = $this->preview($userId);

        $files = [];
        foreach (self::ENTITIES as $entity => [$table, $imageTable]) {
            $stmt = $this->db->prepare(
                "SELECT i.{$entity}_id AS entity_id, i.filename FROM `$imageTable` i
                 JOIN `$table` e ON e.id = i.{$entity}_id WHERE e.user_id = ? AND e.public = 0"
            );
            $stmt->execute([$userId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $files[] = [$entity, (int) $row['entity_id'], (string) $row['filename']];
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Tours of OTHER owners (or the user's public ones) that lose a deleted private route.
            $stmt = $this->db->prepare(
                'SELECT DISTINCT tr.tour_id FROM tour_route tr
                 JOIN route r ON r.id = tr.route_id
                 JOIN tour t ON t.id = tr.tour_id
                 WHERE r.user_id = ? AND r.public = 0 AND NOT (t.user_id = ? AND t.public = 0)'
            );
            $stmt->execute([$userId, $userId]);
            $affectedTours = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            foreach (self::ENTITIES as [$table]) {
                $this->db->prepare("DELETE FROM `$table` WHERE user_id = ? AND public = 0")->execute([$userId]);
            }

            $this->db->prepare(
                'UPDATE route SET user_id = ?, recorded_at = NULL, recording_duration_seconds = NULL, updated_at = updated_at WHERE user_id = ?'
            )->execute([$successor['id'], $userId]);
            foreach (['poi', 'area', 'tour'] as $table) {
                $this->db->prepare("UPDATE `$table` SET user_id = ?, updated_at = updated_at WHERE user_id = ?")
                    ->execute([$successor['id'], $userId]);
            }

            $this->db->prepare('DELETE FROM serviceuser WHERE user_id = ?')->execute([$userId]);
            // user_token and watch_link go with it (ON DELETE CASCADE), ai_usage keeps its name snapshot.
            $this->db->prepare('DELETE FROM user WHERE id = ?')->execute([$userId]);

            foreach ($affectedTours as $tourId) {
                $this->tours->recalculateTotalLength($tourId);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }

        foreach ($files as [$entity, $entityId, $filename]) {
            if (isset($this->imageStores[$entity])) {
                $this->imageStores[$entity]->delete($entityId, $filename);
            }
        }

        return ['deleted' => $preview['private'], 'transferred' => $preview['public'], 'successor' => $successor['username']];
    }
}
