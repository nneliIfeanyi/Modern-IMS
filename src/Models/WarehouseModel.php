<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class WarehouseModel
{
    public function all(?string $search = null): array
    {
        $sql = 'SELECT id, name, location, created_at, updated_at
                FROM warehouses
                WHERE 1 = 1';

        $params = [];
        $term = trim((string) $search);
        if ($term !== '') {
            $sql .= ' AND (
                        name LIKE :search_name
                        OR COALESCE(location, \'\') LIKE :search_location
                    )';
            $params['search_name'] = '%' . $term . '%';
            $params['search_location'] = '%' . $term . '%';
        }

        $sql .= ' ORDER BY name ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO warehouses (name, location)
                VALUES (:name, :location)';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data['id'] = $id;

        $sql = 'UPDATE warehouses
                SET
                    name = :name,
                    location = :location,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $sql = 'DELETE FROM warehouses WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM warehouses WHERE name = :name';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function hasDependencies(int $id): bool
    {
        $sql = 'SELECT
                    (
                        SELECT COUNT(*)
                        FROM purchase_orders
                        WHERE warehouse_id = :id_po
                    )
                    +
                    (
                        SELECT COUNT(*)
                        FROM stock
                        WHERE warehouse_id = :id_stock
                    )
                    +
                    (
                        SELECT COUNT(*)
                        FROM stock_movements
                        WHERE warehouse_id = :id_sm_main
                           OR source_warehouse_id = :id_sm_source
                           OR destination_warehouse_id = :id_sm_dest
                    ) AS total_refs';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id_po' => $id,
            'id_stock' => $id,
            'id_sm_main' => $id,
            'id_sm_source' => $id,
            'id_sm_dest' => $id,
        ]);

        $row = $stmt->fetch();
        return (int) ($row['total_refs'] ?? 0) > 0;
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, name, location, created_at, updated_at
                FROM warehouses
                WHERE id = :id
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();
        return $row ?: null;
    }
}
