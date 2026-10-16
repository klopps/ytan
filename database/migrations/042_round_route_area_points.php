<?php

declare(strict_types=1);

// Rounds the coordinates of every stored route/area line to
// PointsJson::DECIMALS (6, ~11 cm) - new and edited lines are already
// rounded by RouteRepository/AreaRepository. updated_at is kept, so this
// neither shows up as an edit nor trips the stale-edit check of a form that
// is open while the migration runs.

use Ytan\Service\PointsJson;

return function (PDO $pdo): void {
    foreach (['route', 'area'] as $table) {
        $rows = $pdo->query("SELECT id, points FROM `$table` WHERE points IS NOT NULL AND points <> ''")
            ->fetchAll(PDO::FETCH_ASSOC);
        $update = $pdo->prepare("UPDATE `$table` SET points = ?, updated_at = updated_at WHERE id = ?");

        $before = 0;
        $after = 0;
        foreach ($rows as $row) {
            $rounded = PointsJson::round($row['points']);
            $before += strlen($row['points']);
            $after += strlen($rounded);
            if ($rounded !== $row['points']) {
                $update->execute([$rounded, $row['id']]);
            }
        }

        echo "  $table: " . count($rows) . " rows, $before -> $after bytes\n";
    }
};
