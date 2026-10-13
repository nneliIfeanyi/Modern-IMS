<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class DashboardModel
{
    public function summary(): array
    {
        $skuSql = 'SELECT COUNT(*) AS total_skus FROM products WHERE is_active = 1';
        $skuRow = Database::connection()->query($skuSql)->fetch();
        $totalSkus = (int) ($skuRow['total_skus'] ?? 0);

        $stockValueSql = 'SELECT COALESCE(SUM(s.quantity * p.cost_price), 0) AS total_stock_value
                          FROM stock s
                          INNER JOIN products p ON p.id = s.product_id
                          WHERE p.is_active = 1';
        $stockValueRow = Database::connection()->query($stockValueSql)->fetch();
        $totalStockValue = (float) ($stockValueRow['total_stock_value'] ?? 0);

        $lowStockSql = 'SELECT COUNT(*) AS low_stock_count
                        FROM (
                            SELECT p.id, p.reorder_level, COALESCE(SUM(s.quantity), 0) AS qty_on_hand
                            FROM products p
                            LEFT JOIN stock s ON s.product_id = p.id
                            WHERE p.is_active = 1
                            GROUP BY p.id, p.reorder_level
                        ) t
                        WHERE t.qty_on_hand <= t.reorder_level';
        $lowStockRow = Database::connection()->query($lowStockSql)->fetch();
        $lowStockCount = (int) ($lowStockRow['low_stock_count'] ?? 0);

        $purchasePendingSql = "SELECT COUNT(*) AS purchase_pending
                               FROM purchase_orders
                               WHERE status IN ('draft', 'ordered', 'partially_received')";
        $purchasePendingRow = Database::connection()->query($purchasePendingSql)->fetch();
        $purchasePending = (int) ($purchasePendingRow['purchase_pending'] ?? 0);

        $salesPendingSql = "SELECT COUNT(*) AS sales_pending
                            FROM sales_orders
                            WHERE status IN ('draft', 'confirmed', 'partially_fulfilled')";
        $salesPendingRow = Database::connection()->query($salesPendingSql)->fetch();
        $salesPending = (int) ($salesPendingRow['sales_pending'] ?? 0);

        return [
            'total_skus' => $totalSkus,
            'total_stock_value' => number_format($totalStockValue, 2, '.', ''),
            'low_stock_count' => $lowStockCount,
            'pending_orders' => [
                'total' => $purchasePending + $salesPending,
                'purchase' => $purchasePending,
                'sales' => $salesPending,
            ],
        ];
    }

    public function movementsTrend(int $days): array
    {
        $days = max(1, min(365, $days));

        $sql = 'SELECT
                    DATE(created_at) AS day,
                    COALESCE(SUM(CASE WHEN movement_type = \'in\' THEN quantity ELSE 0 END), 0) AS total_in,
                    COALESCE(SUM(CASE WHEN movement_type = \'out\' THEN ABS(quantity) ELSE 0 END), 0) AS total_out
                FROM stock_movements
                WHERE DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL :days_minus_one DAY)
                GROUP BY DATE(created_at)
                ORDER BY day ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['days_minus_one' => $days - 1]);

        $rows = $stmt->fetchAll() ?: [];
        $lookup = [];

        foreach ($rows as $row) {
            $lookup[(string) $row['day']] = [
                'in' => (float) $row['total_in'],
                'out' => (float) $row['total_out'],
            ];
        }

        $items = [];
        $start = new \DateTimeImmutable('today');
        $start = $start->modify('-' . ($days - 1) . ' days');

        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify('+' . $i . ' days')->format('Y-m-d');
            $item = $lookup[$date] ?? ['in' => 0.0, 'out' => 0.0];
            $items[] = [
                'date' => $date,
                'stock_in' => number_format((float) $item['in'], 2, '.', ''),
                'stock_out' => number_format((float) $item['out'], 2, '.', ''),
            ];
        }

        return [
            'days' => $days,
            'items' => $items,
        ];
    }

    public function lowStockItems(): array
    {
        $sql = 'SELECT
                    p.id,
                    p.name,
                    p.sku,
                    p.reorder_level,
                    COALESCE(SUM(s.quantity), 0) AS qty_on_hand,
                    (p.reorder_level - COALESCE(SUM(s.quantity), 0)) AS shortage,
                    p.updated_at
                FROM products p
                LEFT JOIN stock s ON s.product_id = p.id
                WHERE p.is_active = 1
                GROUP BY p.id, p.name, p.sku, p.reorder_level, p.updated_at
                HAVING qty_on_hand <= p.reorder_level
                ORDER BY shortage DESC, p.name ASC';

        $stmt = Database::connection()->query($sql);
        return $stmt->fetchAll() ?: [];
    }
}
