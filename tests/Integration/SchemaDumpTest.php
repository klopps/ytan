<?php

declare(strict_types=1);

namespace Ytan\Tests\Integration;

use Ytan\Database\SchemaDump;
use Ytan\Tests\TestCase;

final class SchemaDumpTest extends TestCase
{
    public function testNormalizeCreateDropsCounterAndMariaDbCollation(): void
    {
        $create = "CREATE TABLE `x` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n)"
            . ' ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci';

        $this->assertSame(
            "CREATE TABLE `x` (\n  `id` int(11) NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n)"
                . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            SchemaDump::normalizeCreate($create)
        );
    }

    public function testGenerateContainsEveryTableReferenceRowsAndDefaultSettings(): void
    {
        $this->seedDataSources();
        $this->pdo->exec("INSERT IGNORE INTO poitype (id, name, icon) VALUES (0, 'Test', 'x.png')");

        $sql = SchemaDump::generate($this->pdo, '2026-11-18');

        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $this->assertStringContainsString('CREATE TABLE `' . $table . '`', $sql);
        }
        $this->assertStringNotContainsString('AUTO_INCREMENT=', $sql);
        $this->assertStringNotContainsString('uca1400', $sql);
        $this->assertStringContainsString('INSERT INTO `poitype`', $sql);
        $this->assertStringContainsString('INSERT INTO `data_source`', $sql);
        $this->assertStringContainsString('INSERT INTO `app_settings` (`id`) VALUES (1);', $sql);
        $this->assertStringContainsString('INSERT INTO `schema_migrations`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `user`', $sql);
    }

    public function testCheckedInSchemaLoadsTheCoreTables(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');

        foreach (['poi', 'route', 'area', 'tour', 'user', 'schema_migrations'] as $table) {
            $this->assertStringContainsString('CREATE TABLE `' . $table . '`', $sql);
        }
    }

    public function testIsEmptyIsFalseForTheTestDatabase(): void
    {
        $this->assertFalse(SchemaDump::isEmpty($this->pdo));
    }
}
