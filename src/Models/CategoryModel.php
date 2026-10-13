<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class CategoryModel
{
    public function all(): array
    {
        $sql = 'SELECT id, name, description, created_at, updated_at
                FROM categories
                ORDER BY name ASC';

        $stmt = Database::connection()->query($sql);
        return $stmt->fetchAll() ?: [];
    }

    public function create(string $name, ?string $description): int
    {
        $sql = 'INSERT INTO categories (name, description) VALUES (:name, :description)';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'description' => $description,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description): bool
    {
        $stmt = Database::connection()->prepare('UPDATE categories
            SET name = :name,
                description = :description,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'description' => $description,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function findByName(string $name): ?array
    {
        $sql = 'SELECT id, name FROM categories WHERE name = :name LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['name' => $name]);

        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM categories WHERE name = :name';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }

        $stmt = Database::connection()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function hasDependencies(int $id): bool
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS total_refs FROM products WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return (int) ($row['total_refs'] ?? 0) > 0;
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, name, description, created_at, updated_at FROM categories WHERE id = :id LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
