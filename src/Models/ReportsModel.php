<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\ExpiryTracking;
use PDO;

final class ReportsModel
{
    public function movementRecap(): array
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');
        $weekStart = $today->modify('monday this week');
        $monthStart = $today->modify('first day of this month');
        $previousMonthStart = $monthStart->modify('-1 month');
        $previousMonthDays = (int) $previousMonthStart->format('t');
        $previousMonthEnd = $previousMonthStart->modify('+' . min((int) $today->format('j'), $previousMonthDays) . ' days');

        $periods = [
            'daily' => [
                'label' => 'Today',
                'current' => [$today, $tomorrow],
                'previous' => [$today->modify('-1 day'), $today],
            ],
            'weekly' => [
                'label' => 'Week to date',
                'current' => [$weekStart, $tomorrow],
                'previous' => [$weekStart->modify('-7 days'), $tomorrow->modify('-7 days')],
            ],
            'monthly' => [
                'label' => 'Month to date',
                'current' => [$monthStart, $tomorrow],
                'previous' => [$previousMonthStart, $previousMonthEnd],
            ],
        ];

        $rangeQueries = [];
        $params = [];
        foreach ($periods as $periodKey => $period) {
            foreach (['current', 'previous'] as $comparison) {
                $rangeKey = $periodKey . '_' . $comparison;
                [$rangeStart, $rangeEnd] = $period[$comparison];
                $rangeQueries[] = "SELECT '{$rangeKey}' AS period_key, :{$rangeKey}_start AS range_start, :{$rangeKey}_end AS range_end";
                $params[$rangeKey . '_start'] = $rangeStart->format('Y-m-d');
                $params[$rangeKey . '_end'] = $rangeEnd->format('Y-m-d');
            }
        }

        $sql = 'SELECT
                    report_period.period_key,
                    COUNT(sm.id) AS entry_count,
                    COALESCE(SUM(CASE WHEN sm.movement_type = \'in\' THEN sm.quantity ELSE 0 END), 0) AS stock_in,
                    COALESCE(SUM(CASE WHEN sm.movement_type = \'out\' THEN ABS(sm.quantity) ELSE 0 END), 0) AS stock_out,
                    COALESCE(SUM(CASE WHEN sm.movement_type = \'adjustment\' THEN sm.quantity ELSE 0 END), 0) AS adjustment_net,
                    COALESCE(SUM(CASE WHEN sm.movement_type = \'transfer\' AND sm.warehouse_id = sm.source_warehouse_id
                        THEN ABS(sm.quantity) ELSE 0 END), 0) AS transferred
                FROM (' . implode(' UNION ALL ', $rangeQueries) . ') report_period
                LEFT JOIN stock_movements sm
                    ON sm.created_at >= report_period.range_start
                    AND sm.created_at < report_period.range_end
                GROUP BY report_period.period_key';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        $metrics = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $metrics[(string) $row['period_key']] = [
                'entry_count' => (int) $row['entry_count'],
                'stock_in' => (float) $row['stock_in'],
                'stock_out' => (float) $row['stock_out'],
                'adjustment_net' => (float) $row['adjustment_net'],
                'transferred' => (float) $row['transferred'],
            ];
        }

        $result = [];
        foreach ($periods as $periodKey => $period) {
            $currentKey = $periodKey . '_current';
            $previousKey = $periodKey . '_previous';
            [$currentStart, $currentEnd] = $period['current'];
            [$previousStart, $previousEnd] = $period['previous'];
            $result[$periodKey] = [
                'label' => $period['label'],
                'current' => [
                    'from' => $currentStart->format('Y-m-d'),
                    'to' => $currentEnd->modify('-1 day')->format('Y-m-d'),
                    'metrics' => $metrics[$currentKey] ?? [],
                ],
                'previous' => [
                    'from' => $previousStart->format('Y-m-d'),
                    'to' => $previousEnd->modify('-1 day')->format('Y-m-d'),
                    'metrics' => $metrics[$previousKey] ?? [],
                ],
            ];
        }

        return $result;
    }

    public function expiringStock(int $days = 90): array
    {
        if (!ExpiryTracking::enabled()) {
            return [
                'tracking_enabled' => false,
                'days' => $days,
                'items' => [],
                'summary' => [
                    'batch_count' => 0,
                    'expired_batch_count' => 0,
                    'expiring_batch_count' => 0,
                    'total_quantity' => 0,
                ],
            ];
        }

        $days = in_array($days, [30, 60, 90], true) ? $days : 90;
        $today = new \DateTimeImmutable('today');
        $cutoff = $today->modify('+' . $days . ' days');

        $sql = 'SELECT
                    b.id,
                    b.batch_number,
                    b.expiry_date,
                    b.quantity,
                    DATEDIFF(b.expiry_date, report_date.today) AS days_remaining,
                    CASE
                        WHEN b.expiry_date < report_date.today THEN \'expired\'
                        WHEN b.expiry_date <= DATE_ADD(report_date.today, INTERVAL 30 DAY) THEN \'0-30\'
                        WHEN b.expiry_date <= DATE_ADD(report_date.today, INTERVAL 60 DAY) THEN \'31-60\'
                        ELSE \'61-90\'
                    END AS expiry_bucket,
                    p.sku,
                    p.name AS product_name,
                    w.name AS warehouse_name
                FROM inventory_batches b
                INNER JOIN products p ON p.id = b.product_id AND p.is_active = 1
                INNER JOIN warehouses w ON w.id = b.warehouse_id
                CROSS JOIN (SELECT :today AS today) report_date
                WHERE b.status = \'active\'
                  AND b.quantity > 0
                  AND b.expiry_date IS NOT NULL
                  AND b.expiry_date <= :cutoff
                ORDER BY b.expiry_date ASC, p.name ASC, b.batch_number ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'today' => $today->format('Y-m-d'),
            'cutoff' => $cutoff->format('Y-m-d'),
        ]);
        $items = $stmt->fetchAll() ?: [];

        $summary = [
            'batch_count' => count($items),
            'expired_batch_count' => 0,
            'expiring_batch_count' => 0,
            'total_quantity' => 0.0,
        ];
        foreach ($items as $item) {
            if ((int) $item['days_remaining'] < 0) {
                $summary['expired_batch_count']++;
            } else {
                $summary['expiring_batch_count']++;
            }
            $summary['total_quantity'] += (float) $item['quantity'];
        }

        return [
            'tracking_enabled' => true,
            'days' => $days,
            'items' => $items,
            'summary' => $summary,
        ];
    }

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
