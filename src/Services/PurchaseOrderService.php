<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ExpiryTracking;
use App\Core\Database;
use App\Models\InventoryBatchModel;
use App\Models\PurchaseOrderModel;

final class PurchaseOrderService
{
    private PurchaseOrderModel $orders;
    private StockMovementService $stockMovements;
    private InventoryBatchModel $batches;

    public function __construct()
    {
        $this->orders = new PurchaseOrderModel();
        $this->stockMovements = new StockMovementService();
        $this->batches = new InventoryBatchModel();
    }

    public function createDraft(array $header, array $items, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $poId = $this->orders->createDraft($header, $userId);
            $this->orders->replaceItems($poId, $items);

            $pdo->commit();
            return $this->hydrate($poId);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateDraft(int $poId, array $header, array $items): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $po = $this->orders->findById($poId, true);
            if ($po === null) {
                throw new \App\Services\PurchaseOrderException('Purchase order not found.', 404);
            }
            if ($po['status'] !== 'draft') {
                throw new \App\Services\PurchaseOrderException('Only draft purchase orders can be updated.', 409);
            }

            $this->orders->updateDraftHeader($poId, $header);
            $this->orders->replaceItems($poId, $items);

            $pdo->commit();
            return $this->hydrate($poId);
        } catch (\App\Services\PurchaseOrderException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function send(int $poId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $po = $this->orders->findById($poId, true);
            if ($po === null) {
                throw new \App\Services\PurchaseOrderException('Purchase order not found.', 404);
            }
            if ($po['status'] !== 'draft') {
                throw new \App\Services\PurchaseOrderException('Only draft purchase orders can be sent.', 409);
            }

            $items = $this->orders->itemsByPoId($poId, true);
            if ($items === []) {
                throw new \App\Services\PurchaseOrderException('Cannot send purchase order without items.', 422);
            }

            $this->orders->setStatus($poId, 'ordered');

            $pdo->commit();
            return $this->hydrate($poId);
        } catch (\App\Services\PurchaseOrderException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function receive(int $poId, array $receipts, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $po = $this->orders->findById($poId, true);
            if ($po === null) {
                throw new \App\Services\PurchaseOrderException('Purchase order not found.', 404);
            }
            if (!in_array($po['status'], ['ordered', 'partially_received'], true)) {
                throw new \App\Services\PurchaseOrderException('Only ordered or partially received purchase orders can be received.', 409);
            }

            $items = $this->orders->itemsByPoId($poId, true);
            if ($items === []) {
                throw new \App\Services\PurchaseOrderException('Purchase order has no items.', 422);
            }

            $itemsById = [];
            foreach ($items as $item) {
                $itemsById[(int) $item['id']] = $item;
            }

            $receivedAny = false;

            foreach ($receipts as $entry) {
                $itemId = (int) ($entry['item_id'] ?? 0);
                $qtyToReceive = (float) ($entry['quantity'] ?? 0);

                if ($qtyToReceive <= 0) {
                    continue;
                }

                if (!isset($itemsById[$itemId])) {
                    throw new \App\Services\PurchaseOrderException('One or more receipt lines do not belong to this purchase order.', 422);
                }

                $item = $itemsById[$itemId];
                $ordered = (float) $item['qty_ordered'];
                $alreadyReceived = (float) $item['qty_received'];
                $remaining = $ordered - $alreadyReceived;

                if ($qtyToReceive > $remaining) {
                    throw new \App\Services\PurchaseOrderException('Received quantity exceeds remaining quantity for at least one line item.', 422, [
                        'item_id' => $itemId,
                        'remaining' => $remaining,
                    ]);
                }

                $this->stockMovements->record([
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => (int) $po['warehouse_id'],
                    'type' => 'in',
                    'quantity' => $qtyToReceive,
                    'reference' => $po['po_number'],
                    'notes' => 'PO receipt item #' . $itemId,
                ], $userId, false);

                if (ExpiryTracking::enabled()) {
                    $this->batches->receive([
                        'product_id' => (int) $item['product_id'],
                        'warehouse_id' => (int) $po['warehouse_id'],
                        'batch_number' => trim((string) ($entry['batch_number'] ?? '')),
                        'expiry_date' => (string) $entry['expiry_date'],
                        'quantity' => $qtyToReceive,
                        'received_date' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                        'supplier_id' => (int) $po['supplier_id'],
                    ]);
                }

                $this->orders->updateItemQtyReceived($itemId, $alreadyReceived + $qtyToReceive);
                $receivedAny = true;
            }

            if (!$receivedAny) {
                throw new \App\Services\PurchaseOrderException('At least one receipt quantity greater than zero is required.', 422);
            }

            $refreshedItems = $this->orders->itemsByPoId($poId, true);
            $allReceived = true;
            foreach ($refreshedItems as $item) {
                if ((float) $item['qty_received'] < (float) $item['qty_ordered']) {
                    $allReceived = false;
                    break;
                }
            }

            $this->orders->setStatus($poId, $allReceived ? 'received' : 'partially_received');

            $pdo->commit();
            return $this->hydrate($poId);
        } catch (\App\Services\PurchaseOrderException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (StockMovementException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \App\Services\PurchaseOrderException($exception->getMessage(), $exception->statusCode(), $exception->extra());
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hydrate(int $poId): array
    {
        $po = $this->orders->findById($poId);
        if ($po === null) {
            throw new \App\Services\PurchaseOrderException('Purchase order not found.', 404);
        }

        $items = $this->orders->itemsByPoId($poId);
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['subtotal'];
        }

        return [
            'order' => $po,
            'items' => $items,
            'summary' => [
                'item_count' => count($items),
                'total_amount' => number_format($total, 2, '.', ''),
            ],
        ];
    }
}
