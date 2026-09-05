<?php

declare(strict_types=1);

namespace Ytan\Domain\Settings;

use PDO;

final class SettingsRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function googleSearchRequiresLogin(): bool
    {
        $stmt = $this->db->query('SELECT google_search_requires_login FROM app_settings WHERE id = 1');

        return (bool) $stmt->fetchColumn();
    }

    public function setGoogleSearchRequiresLogin(bool $enabled): void
    {
        $stmt = $this->db->prepare('UPDATE app_settings SET google_search_requires_login = ? WHERE id = 1');
        $stmt->execute([(int) $enabled]);
    }
}
