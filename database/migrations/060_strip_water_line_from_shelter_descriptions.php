<?php

declare(strict_types=1);

// Removes the paragraph "Abstand zu Gewässern: Meer 39 m, Fluss 10851 m, ..."
// that the shelter import (ShelterImportService::description()) used to write
// into the description of every imported shelter POI (type 10). The distances
// are in poi_water_distance and the POI InfoWindow shows them from there, so
// the text only repeated them. Only a paragraph of exactly that generated
// form is removed; updated_at is kept, so this does not show up as an edit.

return function (PDO $pdo): void {
    $rows = $pdo->query("SELECT id, description FROM poi WHERE poitype_id = 10 AND description LIKE '%Abstand zu Gewässern: %'")
        ->fetchAll(PDO::FETCH_ASSOC);
    $update = $pdo->prepare('UPDATE poi SET description = ?, updated_at = updated_at WHERE id = ?');

    $changed = 0;
    foreach ($rows as $row) {
        $paragraphs = explode("\n\n", (string) $row['description']);
        $kept = array_filter($paragraphs, static fn (string $paragraph): bool =>
            preg_match('/^Abstand zu Gewässern: (Meer|Fluss|See) \d+ m(, (Meer|Fluss|See) \d+ m)*$/u', trim($paragraph)) !== 1);
        if (count($kept) !== count($paragraphs)) {
            $update->execute([implode("\n\n", $kept), $row['id']]);
            $changed++;
        }
    }

    echo '  shelter descriptions: ' . count($rows) . " with the line, $changed changed\n";
};
