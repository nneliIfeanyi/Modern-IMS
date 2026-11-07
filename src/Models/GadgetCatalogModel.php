<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
final class GadgetCatalogModel
{
    private const SELECT_COLUMNS = 'id, name, category, brand, model, description, image_path, display_price, is_published, sort_order, created_at, updated_at';

    public function listPublished(): array
    {
        $sql = 'SELECT id, name, category, brand, model, description, image_path, display_price, sort_order
                FROM gadget_catalog
                WHERE is_published = 1
                ORDER BY sort_order ASC, created_at DESC, id DESC';

        return Database::connection()->query($sql)->fetchAll() ?: [];
    }

    public function listForAdmin(): array
    {
        $sql = 'SELECT ' . self::SELECT_COLUMNS . '
                FROM gadget_catalog
                ORDER BY sort_order ASC, created_at DESC, id DESC';

        return Database::connection()->query($sql)->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::SELECT_COLUMNS . ' FROM gadget_catalog WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO gadget_catalog (
                name, category, brand, model, description, image_path, display_price, is_published, sort_order
            ) VALUES (
                :name, :category, :brand, :model, :description, :image_path, :display_price, :is_published, :sort_order
            )'
        );
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $data['id'] = $id;
        $stmt = Database::connection()->prepare(
            'UPDATE gadget_catalog
             SET name = :name,
                 category = :category,
                 brand = :brand,
                 model = :model,
                 description = :description,
                 image_path = :image_path,
                 display_price = :display_price,
                 is_published = :is_published,
                 sort_order = :sort_order,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute($data);
    }

    public function delete(int $id): bool
    {
        $stmt = Database::connection()->prepare('DELETE FROM gadget_catalog WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
