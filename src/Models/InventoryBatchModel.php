<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class InventoryBatchModel
{
    public function deductFefo(int $productId, int $warehouseId, float $quantity): ?bool
    {
        $stockStmt = Database::connection()->prepare('SELECT quantity
            FROM stock
            WHERE product_id = :product_id AND warehouse_id = :warehouse_id
            LIMIT 1');
        $stockStmt->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);
        $stock = $stockStmt->fetch();

        $batchTotalStmt = Database::connection()->prepare('SELECT COUNT(*) AS batch_count, COALESCE(SUM(quantity), 0) AS quantity
            FROM inventory_batches
            WHERE product_id = :product_id AND warehouse_id = :warehouse_id');
        $batchTotalStmt->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);
        $batchTotal = $batchTotalStmt->fetch();

        if (!$batchTotal || (int) $batchTotal['batch_count'] === 0) {
            return null;
        }

        if ((float) ($stock['quantity'] ?? 0) > (float) $batchTotal['quantity']) {
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT id, quantity
            FROM inventory_batches
                        WHERE product_id = :batch_product_id
                            AND warehouse_id = :batch_warehouse_id
              AND status = \'active\'
              AND quantity > 0
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            ORDER BY expiry_date IS NULL ASC, expiry_date ASC, id ASC
            FOR UPDATE');
        $stmt->execute([
            'batch_product_id' => $productId,
            'batch_warehouse_id' => $warehouseId,
        ]);
        $batches = $stmt->fetchAll() ?: [];

        $available = 0.0;
        foreach ($batches as $batch) {
            $available += (float) $batch['quantity'];
        }

        if ($available < $quantity) {
            return false;
        }

        $remaining = $quantity;
        $update = Database::connection()->prepare('UPDATE inventory_batches
            SET quantity = :new_quantity,
                status = CASE WHEN :status_quantity <= 0 THEN \'depleted\' ELSE status END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id');

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $current = (float) $batch['quantity'];
            $deducted = min($current, $remaining);
            $update->execute([
                'id' => (int) $batch['id'],
                'new_quantity' => $current - $deducted,
                'status_quantity' => $current - $deducted,
            ]);
            $remaining -= $deducted;
        }

        return true;
    }

    public function receive(array $data): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO inventory_batches (
                product_id, warehouse_id, batch_number, expiry_date, quantity, received_date, supplier_id
            ) VALUES (
                :product_id, :warehouse_id, :batch_number, :expiry_date, :quantity, :received_date, :supplier_id
            ) ON DUPLICATE KEY UPDATE
                expiry_date = VALUES(expiry_date),
                quantity = quantity + VALUES(quantity),
                status = \'active\',
                updated_at = CURRENT_TIMESTAMP');
        $stmt->execute($data);
    }

    public function expiringForAlert(): array
    {
        $sql = 'SELECT
                    b.id,
                    b.batch_number,
                    b.expiry_date,
                    b.quantity,
                    p.name AS product_name,
                    p.sku,
                    w.name AS warehouse_name
                FROM inventory_batches b
                INNER JOIN products p ON p.id = b.product_id AND p.is_active = 1
                INNER JOIN warehouses w ON w.id = b.warehouse_id
                WHERE b.status = \'active\'
                  AND b.quantity > 0
                  AND b.expiry_date >= CURDATE()
                  AND b.expiry_date < DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), INTERVAL 7 DAY)
                ORDER BY b.expiry_date ASC, p.name ASC';

        return Database::connection()->query($sql)->fetchAll() ?: [];
    }
}