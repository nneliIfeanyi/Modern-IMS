<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ExpiryTracking;
use App\Core\Database;
use App\Models\InventoryBatchModel;
use App\Models\StockMovementModel;
use PDO;

final class StockMovementService
{
    private StockMovementModel $movements;
    private InventoryBatchModel $batches;

    public function __construct()
    {
        $this->movements = new StockMovementModel();
        $this->batches = new InventoryBatchModel();
    }

    public function deductBatchFefo(int $productId, int $warehouseId, float $quantity): void
    {
        if (!ExpiryTracking::enabled()) {
            return;
        }

        $result = $this->batches->deductFefo($productId, $warehouseId, $quantity);
        if ($result === false) {
            throw new StockMovementException('Insufficient non-expired batch stock for this sale.', 409);
        }
    }

    public function record(array $payload, int $userId, bool $manageTransaction = true): array
    {
        $pdo = Database::connection();
        if ($manageTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $type = $payload['type'];
            $productId = (int) $payload['product_id'];
            $warehouseId = (int) $payload['warehouse_id'];
            $quantity = (float) $payload['quantity'];
            $reference = $payload['reference'] ?? null;
            $notes = $payload['notes'] ?? null;

            $movementIds = [];

            if ($type === 'transfer') {
                $destinationWarehouseId = (int) ($payload['destination_warehouse_id'] ?? 0);
                $this->ensureStockDelta($productId, $warehouseId, -$quantity);
                $this->ensureStockDelta($productId, $destinationWarehouseId, $quantity);

                $movementIds[] = $this->movements->create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => 'transfer',
                    'quantity' => -$quantity,
                    'reference' => $reference,
                    'source_warehouse_id' => $warehouseId,
                    'destination_warehouse_id' => $destinationWarehouseId,
                    'notes' => $notes,
                    'created_by' => $userId,
                ]);

                $movementIds[] = $this->movements->create([
                    'product_id' => $productId,
                    'warehouse_id' => $destinationWarehouseId,
                    'movement_type' => 'transfer',
                    'quantity' => $quantity,
                    'reference' => $reference,
                    'source_warehouse_id' => $warehouseId,
                    'destination_warehouse_id' => $destinationWarehouseId,
                    'notes' => $notes,
                    'created_by' => $userId,
                ]);
            } else {
                $delta = match ($type) {
                    'in' => $quantity,
                    'out' => -$quantity,
                    'adjustment' => $quantity,
                    default => throw new StockMovementException('Unsupported movement type.', 422),
                };

                $this->ensureStockDelta($productId, $warehouseId, $delta);

                $movementIds[] = $this->movements->create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'movement_type' => $type,
                    'quantity' => $delta,
                    'reference' => $reference,
                    'source_warehouse_id' => null,
                    'destination_warehouse_id' => null,
                    'notes' => $notes,
                    'created_by' => $userId,
                ]);
            }

            if ($manageTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return [
                'movement_ids' => $movementIds,
                'movements' => $this->movements->findByIds($movementIds),
            ];
        } catch (StockMovementException $exception) {
            if ($manageTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($manageTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function ensureStockDelta(int $productId, int $warehouseId, float $delta): void
    {
        $row = $this->lockStockRow($productId, $warehouseId);
        $current = (float) $row['quantity'];
        $next = $current + $delta;

        if ($next < 0) {
            throw new StockMovementException(
                'Insufficient stock for this movement.',
                409,
                [
                    'current_quantity' => $current,
                    'requested_delta' => $delta,
                ]
            );
        }

        $sql = 'UPDATE stock SET quantity = :quantity, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'quantity' => $next,
            'id' => (int) $row['id'],
        ]);
    }

    private function lockStockRow(int $productId, int $warehouseId): array
    {
        $sql = 'SELECT id, quantity
                FROM stock
                WHERE product_id = :product_id AND warehouse_id = :warehouse_id
                FOR UPDATE';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $row;
        }

        $insertSql = 'INSERT INTO stock (product_id, warehouse_id, quantity) VALUES (:product_id, :warehouse_id, 0)';
        $insert = Database::connection()->prepare($insertSql);
        $insert->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);

        $stmt->execute([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
        ]);

        $locked = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($locked === false) {
            throw new StockMovementException('Unable to initialize stock row.', 500);
        }

        return $locked;
    }
}
