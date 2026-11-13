<?php

declare(strict_types=1);

namespace Ytan\Domain\Ai;

use PDO;

/**
 * ai_usage (migration 070): one row per Routencheck request - also cache hits
 * (0 tokens) and failures. The daily limit and /admin/ai's usage statistics
 * are counted from here. Times are UTC.
 *
 * "Requests" in the statistics = answered by the model (status ok, no cache
 * hit) - those are the ones that cost tokens; cache hits and errors are
 * counted separately.
 */
final class AiUsageRepository
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';
    public const STATUS_LIMIT = 'limit';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array{user_id: ?int, username: string, route_id: ?int, model: string, input_tokens?: int, output_tokens?: int,
     *              cache_read_tokens?: int, cache_write_tokens?: int, cache_hit?: bool, status: string, duration_ms?: int} $row
     */
    public function record(array $row): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ai_usage (user_id, username_snapshot, route_id, created_at, model, input_tokens, output_tokens,
                cache_read_tokens, cache_write_tokens, cache_hit, status, duration_ms)
             VALUES (?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $row['user_id'],
            mb_substr($row['username'], 0, 255),
            $row['route_id'],
            mb_substr($row['model'], 0, 64),
            (int) ($row['input_tokens'] ?? 0),
            (int) ($row['output_tokens'] ?? 0),
            (int) ($row['cache_read_tokens'] ?? 0),
            (int) ($row['cache_write_tokens'] ?? 0),
            (int) ($row['cache_hit'] ?? false),
            $row['status'],
            (int) ($row['duration_ms'] ?? 0),
        ]);
    }

    /** Model-answered requests of a user since UTC midnight (what the daily limit counts). */
    public function countToday(int $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM ai_usage WHERE user_id = ? AND status = 'ok' AND cache_hit = 0 AND created_at >= UTC_DATE()"
        );
        $stmt->execute([$userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Totals for one month ("YYYY-MM") or, with null, for all time.
     *
     * @return array{requests: int, cache_hits: int, errors: int, limited: int, input_tokens: int, output_tokens: int,
     *               total_tokens: int, avg_input: ?float, avg_output: ?float, avg_total: ?float}
     */
    public function summary(?string $month): array
    {
        [$where, $params] = self::monthWhere($month, 'created_at');
        $stmt = $this->db->prepare(
            "SELECT
                SUM(status = 'ok' AND cache_hit = 0) AS requests,
                SUM(cache_hit = 1) AS cache_hits,
                SUM(status = 'error') AS errors,
                SUM(status = 'limit') AS limited,
                SUM(input_tokens + cache_read_tokens + cache_write_tokens) AS input_tokens,
                SUM(output_tokens) AS output_tokens
             FROM ai_usage" . ($where !== '' ? " WHERE $where" : '')
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $requests = (int) ($row['requests'] ?? 0);
        $input = (int) ($row['input_tokens'] ?? 0);
        $output = (int) ($row['output_tokens'] ?? 0);

        return [
            'requests' => $requests,
            'cache_hits' => (int) ($row['cache_hits'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'limited' => (int) ($row['limited'] ?? 0),
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'avg_input' => $requests > 0 ? round($input / $requests, 1) : null,
            'avg_output' => $requests > 0 ? round($output / $requests, 1) : null,
            'avg_total' => $requests > 0 ? round(($input + $output) / $requests, 1) : null,
        ];
    }

    /**
     * One row per user for a month: everybody who may use the Routencheck
     * (ai_use or admin, with 0 when they didn't) plus anybody who used it that
     * month (right revoked since = eligible false; deleted user = user_id null,
     * name from the snapshot).
     *
     * @return list<array{user_id: ?int, username: string, name: string, eligible: bool, deleted: bool, requests: int,
     *                    input_tokens: int, output_tokens: int, total_tokens: int, avg_total: ?float, last_used: ?string}>
     */
    public function monthlyByUser(string $month): array
    {
        [$where, $params] = self::monthWhere($month, 'u.created_at');
        $stmt = $this->db->prepare(
            "SELECT u.user_id, MAX(u.username_snapshot) AS snapshot,
                    SUM(u.status = 'ok' AND u.cache_hit = 0) AS requests,
                    SUM(u.input_tokens + u.cache_read_tokens + u.cache_write_tokens) AS input_tokens,
                    SUM(u.output_tokens) AS output_tokens,
                    MAX(u.created_at) AS last_used
             FROM ai_usage u
             WHERE $where
             GROUP BY u.user_id, CASE WHEN u.user_id IS NULL THEN u.username_snapshot ELSE '' END"
        );
        $stmt->execute($params);
        $usage = $stmt->fetchAll();

        $users = $this->db->query(
            'SELECT id, username, firstname, lastname, (ai_use = 1 OR is_admin = 1) AS eligible FROM user'
        )->fetchAll();
        $byId = [];
        foreach ($users as $user) {
            $byId[(int) $user['id']] = $user;
        }

        $rows = [];
        $seen = [];
        foreach ($usage as $u) {
            $id = $u['user_id'] !== null ? (int) $u['user_id'] : null;
            $user = $id !== null ? ($byId[$id] ?? null) : null;
            if ($id !== null) {
                $seen[$id] = true;
            }
            $rows[] = self::row($id, $user, (string) $u['snapshot'], $u);
        }
        foreach ($users as $user) {
            $id = (int) $user['id'];
            if (!isset($seen[$id]) && (bool) $user['eligible']) {
                $rows[] = self::row($id, $user, (string) $user['username'], null);
            }
        }
        usort($rows, static fn ($a, $b) => [$b['total_tokens'], $a['username']] <=> [$a['total_tokens'], $b['username']]);

        return $rows;
    }

    /** @return list<string> months with usage, newest first ("YYYY-MM") */
    public function months(): array
    {
        return array_map('strval', $this->db->query(
            "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS month FROM ai_usage ORDER BY month DESC"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function row(?int $id, ?array $user, string $snapshot, ?array $usage): array
    {
        $requests = (int) ($usage['requests'] ?? 0);
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $name = $user !== null ? trim(((string) $user['firstname']) . ' ' . ((string) $user['lastname'])) : '';

        return [
            'user_id' => $id,
            'username' => $user !== null ? (string) $user['username'] : $snapshot,
            'name' => $name,
            'eligible' => $user !== null && (bool) $user['eligible'],
            'deleted' => $user === null,
            'requests' => $requests,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
            'avg_total' => $requests > 0 ? round(($input + $output) / $requests, 1) : null,
            'last_used' => isset($usage['last_used']) ? str_replace(' ', 'T', (string) $usage['last_used']) . 'Z' : null,
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private static function monthWhere(?string $month, string $column): array
    {
        if ($month === null) {
            return ['', []];
        }
        $start = $month . '-01 00:00:00';

        return ["$column >= ? AND $column < ?", [$start, gmdate('Y-m-01 00:00:00', (int) strtotime($start . ' UTC +1 month'))]];
    }
}
