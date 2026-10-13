<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AppSettingModel
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'site_name' => 'ANTOBELL IMS',
        'theme_color' => '#0d6efd',
    ];

    public function getAll(): array
    {
        $this->ensureTableExists();

        $stmt = Database::connection()->query('SELECT setting_key, setting_value FROM app_settings');
        $rows = $stmt->fetchAll() ?: [];

        $settings = self::DEFAULTS;
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $settings[$key] = (string) ($row['setting_value'] ?? '');
        }

        return $settings;
    }

    public function upsertMany(array $settings, ?int $updatedBy = null): void
    {
        if ($settings === []) {
            return;
        }

        $this->ensureTableExists();

        $sql = 'INSERT INTO app_settings (setting_key, setting_value, updated_by)
                VALUES (:setting_key, :setting_value, :updated_by)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP';

        $stmt = Database::connection()->prepare($sql);

        foreach ($settings as $key => $value) {
            $stmt->execute([
                'setting_key' => (string) $key,
                'setting_value' => (string) $value,
                'updated_by' => $updatedBy,
            ]);
        }
    }

    private function ensureTableExists(): void
    {
        Database::connection()->exec('CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by INT UNSIGNED NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_app_settings_updated_by FOREIGN KEY (updated_by)
                REFERENCES users(id)
                ON DELETE RESTRICT
                ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
