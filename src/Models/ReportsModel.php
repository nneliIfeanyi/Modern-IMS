<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class ReportsModel
{
    public function stockValuation(?int $warehouseId = null, string $search = ''): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        $stockJoin = 'LEFT JOIN stock s ON s.product_id = p.id';
        if ($warehouseId !== null && $warehouseId > 0) {
            $stockJoin .= ' AND s.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = $warehouseId;
        }

        $term = trim($search);
        if ($term !== '') {
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku OR COALESCE(c.name, \'\') LIKE :search_category)';
            $params['search_name'] = '%' . $term . '%';
            $params['search_sku'] = '%' . $term . '%';
            $params['search_category'] = '%' . $term . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                    p.id,
                    p.sku,
                    p.name,
                    c.name AS category_name,
                    p.cost_price,
                    p.reorder_level,
                    COALESCE(SUM(s.quantity), 0) AS qty_on_hand,
                    (COALESCE(SUM(s.quantity), 0) * p.cost_price) AS stock_value
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                {$stockJoin}
                {$whereSql}
                GROUP BY p.id, p.sku, p.name, c.name, p.cost_price, p.reorder_level
                ORDER BY stock_value DESC, p.name ASC";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $totalQty = 0.0;
        $totalValue = 0.0;
        foreach ($rows as $row) {
            $totalQty += (float) $row['qty_on_hand'];
            $totalValue += (float) $row['stock_value'];
        }

        return [
            'items' => $rows,
            'summary' => [
                'item_count' => count($rows),
                'total_quantity' => $totalQty,
                'total_value' => $totalValue,
            ],
        ];
    }

    public function lowStock(string $search = '', ?int $warehouseId = null): array
    {
        $where = ['p.is_active = 1'];
        $params = [];

        $stockJoin = 'LEFT JOIN stock s ON s.product_id = p.id';
        if ($warehouseId !== null && $warehouseId > 0) {
            $stockJoin .= ' AND s.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = $warehouseId;
        }

        $term = trim($search);
        if ($term !== '') {
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku)';
            $params['search_name'] = '%' . $term . '%';
            $params['search_sku'] = '%' . $term . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT
                    p.id,
                    p.sku,
                    p.name,
                    p.reorder_level,
                    COALESCE(SUM(s.quantity), 0) AS quantity_on_hand
                FROM products p
                {$stockJoin}
                {$whereSql}
                GROUP BY p.id, p.sku, p.name, p.reorder_level
                HAVING COALESCE(SUM(s.quantity), 0) <= p.reorder_level
                ORDER BY quantity_on_hand ASC, p.name ASC";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function movementHistory(array $filters, bool $paginate = true): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 50)));
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
            $where[] = '(p.name LIKE :search_name OR p.sku LIKE :search_sku OR COALESCE(sm.reference, \'\') LIKE :search_reference)';
            $params['search_name'] = '%' . $search . '%';
            $params['search_sku'] = '%' . $search . '%';
            $params['search_reference'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $baseSql = "FROM stock_movements sm
                    INNER JOIN products p ON p.id = sm.product_id
                    INNER JOIN warehouses w ON w.id = sm.warehouse_id
                    LEFT JOIN users u ON u.id = sm.created_by
                    {$whereSql}";

        $countStmt = Database::connection()->prepare("SELECT COUNT(*) AS total {$baseSql}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = "SELECT
                    sm.id,
                    sm.created_at,
                    sm.movement_type,
                    sm.quantity,
                    sm.reference,
                    p.sku,
                    p.name AS product_name,
                    w.name AS warehouse_name,
                    u.full_name AS created_by_name,
                    sm.notes
                {$baseSql}
                ORDER BY sm.created_at DESC, sm.id DESC";

        if ($paginate) {
            $sql .= ' LIMIT :limit OFFSET :offset';
        }

        $stmt = Database::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }

        if ($paginate) {
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll() ?: [];

        return [
            'items' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $paginate ? $perPage : count($rows),
                'total' => $total,
                'total_pages' => $paginate ? max(1, (int) ceil($total / $perPage)) : 1,
            ],
        ];
    }
}
