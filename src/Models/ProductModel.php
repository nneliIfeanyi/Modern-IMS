<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class ProductModel
{
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['p.is_active = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_sku'] = '%' . $search . '%';
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM products p {$whereSql}";
        $countStmt = Database::connection()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = "SELECT
                    p.id,
                    p.name,
                    p.sku,
                    p.unit,
                    p.cost_price,
                    p.selling_price,
                    p.reorder_level,
                    p.category_id,
                    c.name AS category_name,
                    COALESCE(SUM(s.quantity), 0) AS qty_on_hand,
                    p.created_at,
                    p.updated_at
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                LEFT JOIN stock s ON s.product_id = p.id
                {$whereSql}
                GROUP BY
                    p.id,
                    p.name,
                    p.sku,
                    p.unit,
                    p.cost_price,
                    p.selling_price,
                    p.reorder_level,
                    p.category_id,
                    c.name,
                    p.created_at,
                    p.updated_at
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT
                    p.id,
                    p.name,
                    p.sku,
                    p.unit,
                    p.cost_price,
                    p.selling_price,
                    p.reorder_level,
                    p.category_id,
                    c.name AS category_name,
                    p.is_active,
                    p.created_at,
                    p.updated_at
                FROM products p
                INNER JOIN categories c ON c.id = p.category_id
                WHERE p.id = :id
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO products (
                    category_id,
                    name,
                    sku,
                    unit,
                    cost_price,
                    selling_price,
                    reorder_level,
                    is_active
                ) VALUES (
                    :category_id,
                    :name,
                    :sku,
                    :unit,
                    :cost_price,
                    :selling_price,
                    :reorder_level,
                    1
                )';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data['id'] = $id;

        $sql = 'UPDATE products
                SET
                    category_id = :category_id,
                    name = :name,
                    sku = :sku,
                    unit = :unit,
                    cost_price = :cost_price,
                    selling_price = :selling_price,
                    reorder_level = :reorder_level,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return $stmt->rowCount() > 0;
    }

    public function softDelete(int $id): bool
    {
        $sql = 'UPDATE products
                SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function skuExists(string $sku, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM products WHERE sku = :sku';
        $params = ['sku' => $sku];

        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }
}
