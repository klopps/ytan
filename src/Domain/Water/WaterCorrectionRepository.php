<?php

declare(strict_types=1);

namespace Ytan\Domain\Water;

use PDO;
use Ytan\Exception\ValidationException;
use Ytan\Service\Water\WaterCorrections;
use Ytan\Service\Water\WaterIndex;

/**
 * The water_correction table (migration 088): the admin's corrections of the
 * water index, see WaterCorrections for what each type does. Nothing reads
 * them from here while distances are computed - writeFile() puts them into
 * storage/water/water-corrections.json after every change, and
 * WaterDistanceService reads that file (no DB needed in CLIs and jobs).
 */
final class WaterCorrectionRepository
{
    public const MAX_POLYGON_POINTS = 200;
    public const MAX_LINE_POINTS = 2000;
    public const MAX_NOTE = 500;
    /** A replaced line's original: the chain of segments the page found (up to 2,000 segments). */
    public const MAX_ORIGINAL_POINTS = 2001;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Checks and cleans a correction from a request or a transfer file.
     *
     * @param array<string, mixed> $data
     * @return array{type: string, kinds: list<string>, points: list<array{0: float, 1: float}>, note: string}
     */
    public static function normalize(array $data): array
    {
        $type = $data['type'] ?? null;
        if (!in_array($type, WaterCorrections::TYPES, true)) {
            throw new ValidationException('type must be one of: ' . implode(', ', WaterCorrections::TYPES) . '.');
        }
        $kinds = $data['kinds'] ?? null;
        if (!is_array($kinds) || $kinds === []) {
            throw new ValidationException('kinds must be a non-empty list.');
        }
        $kinds = array_values(array_unique(array_map('strval', $kinds)));
        foreach ($kinds as $kind) {
            if (!in_array($kind, WaterIndex::KINDS, true)) {
                throw new ValidationException('kinds may only contain: ' . implode(', ', WaterIndex::KINDS) . '.');
            }
        }
        if (($type === 'add' || $type === 'replace') && count($kinds) !== 1) {
            throw new ValidationException('A line has exactly one kind.');
        }
        $vertices = self::vertices($data['points'] ?? null, 'points');
        $original = null;
        if ($type === 'replace') {
            // the new line: none (= delete the old one) or at least 2 points
            if ($vertices !== [] && count($vertices) < 2) {
                throw new ValidationException('points: none or at least 2 needed.');
            }
            if (count($vertices) > self::MAX_LINE_POINTS) {
                throw new ValidationException('points: at most ' . self::MAX_LINE_POINTS . '.');
            }
            $original = self::vertices($data['original'] ?? null, 'original');
            if (count($original) < 2 || count($original) > self::MAX_ORIGINAL_POINTS) {
                throw new ValidationException('original: between 2 and ' . self::MAX_ORIGINAL_POINTS . ' needed.');
            }
        } else {
            [$min, $max] = $type === 'add' ? [2, self::MAX_LINE_POINTS] : [3, self::MAX_POLYGON_POINTS];
            if (count($vertices) < $min || count($vertices) > $max) {
                throw new ValidationException("points: between $min and $max needed.");
            }
        }
        $note = self::normalizeNote($data['note'] ?? '');

        return ['type' => $type, 'kinds' => $kinds, 'points' => $vertices, 'original' => $original, 'note' => $note];
    }

    /** @return list<array{0: float, 1: float}> rounded to 6 decimals */
    private static function vertices(mixed $points, string $name): array
    {
        if (!is_array($points)) {
            throw new ValidationException("$name must be a list of [lat, lng].");
        }
        $vertices = [];
        foreach ($points as $point) {
            if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])
                || abs((float) $point[0]) > 90 || abs((float) $point[1]) > 180
            ) {
                throw new ValidationException("$name must be a list of [lat, lng] within ±90/±180.");
            }
            $vertices[] = [round((float) $point[0], 6), round((float) $point[1], 6)];
        }

        return $vertices;
    }

    /** @return list<array<string, mixed>> every correction in id order (= the order they apply in) */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT * FROM water_correction ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        return array_map([self::class, 'row'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM water_correction WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::row($row);
    }

    /**
     * @param array{type: string, kinds: list<string>, points: list<array{0: float, 1: float}>, note: string} $correction normalized
     * @return array<string, mixed> the stored row
     */
    public function create(array $correction, ?int $userId, string $username, ?string $createdAt = null): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO water_correction (type, kinds, points, original, note, user_id, username, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))'
        );
        $stmt->execute([
            $correction['type'],
            implode(',', $correction['kinds']),
            json_encode($correction['points']),
            ($correction['original'] ?? null) === null ? null : json_encode($correction['original']),
            $correction['note'],
            $userId,
            mb_substr($username, 0, 100),
            $createdAt,
        ]);

        return (array) $this->find((int) $this->pdo->lastInsertId());
    }

    /**
     * Changes the line of a correction (an own line edited or deleted on the
     * page) and its kinds (normalized; the controller lets only an added line
     * change them) - type and original stay.
     *
     * @param list<array{0: float, 1: float}> $points normalized
     * @param list<string> $kinds normalized
     * @return array<string, mixed>|null the stored row
     */
    public function updateLine(int $id, array $points, string $note, array $kinds): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE water_correction SET points = ?, note = ?, kinds = ? WHERE id = ?');
        $stmt->execute([json_encode($points), $note, implode(',', $kinds), $id]);

        return $this->find($id);
    }

    /**
     * Changes only the note of a correction (any type).
     *
     * @return array<string, mixed>|null the stored row
     */
    public function updateNote(int $id, string $note): ?array
    {
        $stmt = $this->pdo->prepare('UPDATE water_correction SET note = ? WHERE id = ?');
        $stmt->execute([$note, $id]);

        return $this->find($id);
    }

    /** A note as stored: trimmed, at most MAX_NOTE characters. */
    public static function normalizeNote(mixed $note): string
    {
        $note = trim((string) ($note ?? ''));
        if (mb_strlen($note) > self::MAX_NOTE) {
            throw new ValidationException('note is limited to ' . self::MAX_NOTE . ' characters.');
        }

        return $note;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM water_correction WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Whether an identical correction (type, kinds, points) exists already -
     * reading the same transfer file twice must not apply it twice.
     *
     * @param array{type: string, kinds: list<string>, points: list<array{0: float, 1: float}>} $correction normalized
     */
    public function exists(array $correction): bool
    {
        $original = ($correction['original'] ?? null) === null ? null : json_encode($correction['original']);
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM water_correction WHERE type = ? AND kinds = ? AND points = ? AND original <=> ?'
        );
        $stmt->execute([$correction['type'], implode(',', $correction['kinds']), json_encode($correction['points']), $original]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Writes every correction to the file WaterDistanceService reads
     * (atomically: temp file + rename). No corrections = the file is removed.
     */
    public function writeFile(string $path): void
    {
        $corrections = array_map(
            static fn (array $row): array => ['id' => $row['id'], 'type' => $row['type'], 'kinds' => $row['kinds'], 'points' => $row['points']]
                + ($row['original'] === null ? [] : ['original' => $row['original']]),
            $this->all()
        );
        if ($corrections === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['version' => 1, 'corrections' => $corrections]);
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $path);
        }
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'kinds' => array_values(array_filter(explode(',', (string) $row['kinds']))),
            'points' => json_decode((string) $row['points'], true) ?: [],
            'original' => $row['original'] === null ? null : (json_decode((string) $row['original'], true) ?: []),
            'note' => (string) $row['note'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'username' => (string) $row['username'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
