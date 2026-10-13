<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class StockMovementModel
{
    public function create(array $data): int
    {
        $sql = 'INSERT INTO stock_movements (
                    product_id,
                    warehouse_id,
                    movement_type,
                    quantity,
                    reference,
                    source_warehouse_id,
                    destination_warehouse_id,
                    notes,
                    created_by
                ) VALUES (
                    :product_id,
                    :warehouse_id,
                    :movement_type,
                    :quantity,
                    :reference,
                    :source_warehouse_id,
                    :destination_warehouse_id,
                    :notes,
                    :created_by
                )';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT
                    sm.id,
                    sm.product_id,
                    p.name AS product_name,
                    p.sku,
                    sm.warehouse_id,
                    w.name AS warehouse_name,
                    sm.movement_type,
                    sm.quantity,
                    sm.reference,
                    sm.source_warehouse_id,
                    sm.destination_warehouse_id,
                    sm.notes,
                    sm.created_by,
                    u.full_name AS created_by_name,
                    sm.created_at
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                INNER JOIN warehouses w ON w.id = sm.warehouse_id
                LEFT JOIN users u ON u.id = sm.created_by
                WHERE sm.id IN ($placeholders)
                ORDER BY sm.created_at DESC, sm.id DESC";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(array_values($ids));

        return $stmt->fetchAll() ?: [];
    }

    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['1 = 1'];
        $params = [];

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '' && $type !== 'all') {
            $where[] = 'sm.movement_type = :movement_type';
            $params['movement_type'] = $type;
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $where[] = 'sm.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = $warehouseId;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'DATE(sm.created_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'DATE(sm.created_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku OR sm.reference LIKE :search_reference)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_sku'] = '%' . $search . '%';
            $params['search_reference'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total
                     FROM stock_movements sm
                     INNER JOIN products p ON p.id = sm.product_id
                     {$whereSql}";
        $countStmt = Database::connection()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = "SELECT
                    sm.id,
                    sm.product_id,
                    p.name AS product_name,
                    p.sku,
                    sm.warehouse_id,
                    w.name AS warehouse_name,
                    sm.movement_type,
                    sm.quantity,
                    sm.reference,
                    sm.source_warehouse_id,
                    sm.destination_warehouse_id,
                    sm.notes,
                    sm.created_by,
                    u.full_name AS created_by_name,
                    sm.created_at
                FROM stock_movements sm
                INNER JOIN products p ON p.id = sm.product_id
                INNER JOIN warehouses w ON w.id = sm.warehouse_id
                LEFT JOIN users u ON u.id = sm.created_by
                {$whereSql}
                ORDER BY sm.created_at DESC, sm.id DESC
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
}
