<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\CustomerLedgerModel;
use App\Models\CustomerModel;
use App\Models\SalesOrderPaymentModel;
use App\Models\SalesOrderModel;

final class SalesOrderService
{
    private SalesOrderModel $orders;
    private CustomerModel $customers;
    private CustomerLedgerModel $ledger;
    private SalesOrderPaymentModel $payments;
    private StockMovementService $stockMovements;

    public function __construct()
    {
        $this->orders = new SalesOrderModel();
        $this->customers = new CustomerModel();
        $this->ledger = new CustomerLedgerModel();
        $this->payments = new SalesOrderPaymentModel();
        $this->stockMovements = new StockMovementService();
    }

    public function createDraft(array $header, array $items, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $soId = $this->orders->createDraft($header, $userId);
            $this->orders->replaceItems($soId, $items);

            $pdo->commit();
            return $this->hydrate($soId);
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function updateDraft(int $soId, array $header, array $items): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $order = $this->orders->findById($soId, true);
            if ($order === null) {
                throw new \App\Services\SalesOrderException('Sales order not found.', 404);
            }
            if ($order['status'] !== 'draft') {
                throw new \App\Services\SalesOrderException('Only draft sales orders can be updated.', 409);
            }

            if (!empty($header['customer_name']) && (int) ($header['customer_id'] ?? 0) <= 0) {
                $header['customer_id'] = null;
            }

            $this->orders->updateDraftHeader($soId, $header);
            $this->orders->replaceItems($soId, $items);

            $pdo->commit();
            return $this->hydrate($soId);
        } catch (\App\Services\SalesOrderException $exception) {
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

    public function confirm(int $soId, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $order = $this->orders->findById($soId, true);
            if ($order === null) {
                throw new \App\Services\SalesOrderException('Sales order not found.', 404);
            }
            if ($order['status'] !== 'draft') {
                throw new \App\Services\SalesOrderException('Only draft sales orders can be confirmed.', 409);
            }

            $items = $this->orders->itemsBySoId($soId, true);
            if ($items === []) {
                throw new \App\Services\SalesOrderException('Cannot confirm sales order without items.', 422);
            }

            $totalAmount = $this->orders->totalAmountBySoId($soId);
            $initialPayment = (float) ($order['initial_payment'] ?? 0);
            if ($initialPayment > $totalAmount) {
                throw new \App\Services\SalesOrderException('Initial payment cannot exceed the order total.', 422);
            }
            if ($order['payment_mode'] === 'cash' && abs($initialPayment - $totalAmount) > 0.009) {
                throw new \App\Services\SalesOrderException('Cash sales require initial payment equal to the full order total.', 422);
            }
            if ($order['payment_mode'] === 'credit' && $initialPayment > 0.009) {
                throw new \App\Services\SalesOrderException('Credit sales must have zero initial payment.', 422);
            }
            if ($order['payment_mode'] === 'mixed' && ($initialPayment <= 0.009 || $initialPayment >= $totalAmount - 0.009)) {
                throw new \App\Services\SalesOrderException('Mixed sales require an initial payment less than the full order total.', 422);
            }

            $customerId = $this->resolveCustomerForOrder($order);
            if ($customerId !== null && (int) ($order['customer_id'] ?? 0) !== $customerId) {
                $this->orders->attachCustomer($soId, $customerId);
                $order['customer_id'] = $customerId;
            }

            if ($customerId !== null) {
                $outstandingAmount = max(0.0, $totalAmount - $initialPayment);

                if (in_array($order['payment_mode'], ['credit', 'mixed'], true)) {
                    $creditStatus = $this->customers->creditStatusForUpdate($customerId);
                    if ($creditStatus === null) {
                        throw new \App\Services\SalesOrderException('The selected customer is not active.', 422);
                    }

                    $projectedBalance = $creditStatus['balance'] + $outstandingAmount;
                    if ($projectedBalance > $creditStatus['credit_limit']) {
                        $availableCredit = max(0.0, $creditStatus['credit_limit'] - $creditStatus['balance']);
                        throw new \App\Services\SalesOrderException(
                            sprintf(
                                'Credit limit exceeded. Available credit for %s is NGN %s.',
                                $creditStatus['full_name'],
                                number_format($availableCredit, 2)
                            ),
                            409,
                            [
                                'customer_id' => $customerId,
                                'current_balance' => number_format($creditStatus['balance'], 2, '.', ''),
                                'credit_limit' => number_format($creditStatus['credit_limit'], 2, '.', ''),
                                'available_credit' => number_format($availableCredit, 2, '.', ''),
                                'projected_balance' => number_format($projectedBalance, 2, '.', ''),
                            ]
                        );
                    }
                }

                $paymentId = null;
                if ($initialPayment > 0) {
                    $paymentId = $this->payments->create([
                        'sales_order_id' => $soId,
                        'amount' => number_format($initialPayment, 2, '.', ''),
                        'method' => 'cash',
                        'reference' => null,
                        'notes' => 'Initial payment at order confirmation.',
                        'paid_at' => date('Y-m-d H:i:s'),
                        'created_by' => $userId,
                    ]);
                }

                if ($outstandingAmount > 0) {
                    $this->ledger->record([
                        'customer_id' => $customerId,
                        'sales_order_id' => $soId,
                        'sales_order_payment_id' => null,
                        'entry_type' => 'sale',
                        'direction' => 'debit',
                        'amount' => number_format($outstandingAmount, 2, '.', ''),
                        'reference' => $order['so_number'],
                        'notes' => 'Sales order confirmed with outstanding balance.',
                        'created_by' => $userId,
                    ]);
                }
            } else {
                $initialPayment = (float) ($order['initial_payment'] ?? 0);
                if ($initialPayment > 0) {
                    $this->payments->create([
                        'sales_order_id' => $soId,
                        'amount' => number_format($initialPayment, 2, '.', ''),
                        'method' => 'cash',
                        'reference' => null,
                        'notes' => 'Initial payment at order confirmation.',
                        'paid_at' => date('Y-m-d H:i:s'),
                        'created_by' => $userId,
                    ]);
                }
            }

            $this->orders->setStatus($soId, 'confirmed');

            $pdo->commit();
            return $this->hydrate($soId);
        } catch (\App\Services\SalesOrderException $exception) {
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

    public function fulfill(int $soId, array $fulfillments, int $userId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $order = $this->orders->findById($soId, true);
            if ($order === null) {
                throw new \App\Services\SalesOrderException('Sales order not found.', 404);
            }
            if (!in_array($order['status'], ['confirmed', 'partially_fulfilled'], true)) {
                throw new \App\Services\SalesOrderException('Only confirmed or partially fulfilled sales orders can be fulfilled.', 409);
            }

            $items = $this->orders->itemsBySoId($soId, true);
            if ($items === []) {
                throw new \App\Services\SalesOrderException('Sales order has no items.', 422);
            }

            $itemsById = [];
            foreach ($items as $item) {
                $itemsById[(int) $item['id']] = $item;
            }

            $fulfilledAny = false;

            foreach ($fulfillments as $entry) {
                $itemId = (int) ($entry['item_id'] ?? 0);
                $qtyToFulfill = (float) ($entry['quantity'] ?? 0);

                if ($qtyToFulfill <= 0) {
                    continue;
                }

                if (!isset($itemsById[$itemId])) {
                    throw new \App\Services\SalesOrderException('One or more fulfillment lines do not belong to this sales order.', 422);
                }

                $item = $itemsById[$itemId];
                $ordered = (float) $item['qty_ordered'];
                $alreadyFulfilled = (float) $item['qty_fulfilled'];
                $remaining = $ordered - $alreadyFulfilled;

                if ($qtyToFulfill > $remaining) {
                    throw new \App\Services\SalesOrderException('Fulfillment quantity exceeds remaining quantity for at least one line item.', 422, [
                        'item_id' => $itemId,
                        'remaining' => $remaining,
                    ]);
                }

                $this->stockMovements->record([
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => (int) $order['warehouse_id'],
                    'type' => 'out',
                    'quantity' => $qtyToFulfill,
                    'reference' => $order['so_number'],
                    'notes' => 'SO fulfillment item #' . $itemId,
                ], $userId, false);
                $this->stockMovements->deductBatchFefo(
                    (int) $item['product_id'],
                    (int) $order['warehouse_id'],
                    $qtyToFulfill
                );

                $this->orders->updateItemQtyFulfilled($itemId, $alreadyFulfilled + $qtyToFulfill);
                $fulfilledAny = true;
            }

            if (!$fulfilledAny) {
                throw new \App\Services\SalesOrderException('At least one fulfillment quantity greater than zero is required.', 422);
            }

            $refreshedItems = $this->orders->itemsBySoId($soId, true);
            $allFulfilled = true;
            foreach ($refreshedItems as $item) {
                if ((float) $item['qty_fulfilled'] < (float) $item['qty_ordered']) {
                    $allFulfilled = false;
                    break;
                }
            }

            $this->orders->setStatus($soId, $allFulfilled ? 'fulfilled' : 'partially_fulfilled');

            $pdo->commit();
            return $this->hydrate($soId);
        } catch (\App\Services\SalesOrderException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (StockMovementException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \App\Services\SalesOrderException($exception->getMessage(), $exception->statusCode(), $exception->extra());
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function hydrate(int $soId): array
    {
        $order = $this->orders->findById($soId);
        if ($order === null) {
            throw new \App\Services\SalesOrderException('Sales order not found.', 404);
        }

        $items = $this->orders->itemsBySoId($soId);
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) $item['subtotal'];
        }

        $payments = $this->payments->bySalesOrderId($soId);
        $totalPaid = 0.0;
        foreach ($payments as $payment) {
            $totalPaid += (float) $payment['amount'];
        }

        $customerSummary = null;
        if (!empty($order['customer_id'])) {
            $customerSummary = $this->ledger->summary((int) $order['customer_id']);
        }

        return [
            'order' => $order,
            'items' => $items,
            'payments' => $payments,
            'summary' => [
                'item_count' => count($items),
                'total_amount' => number_format($total, 2, '.', ''),
                'total_paid' => number_format($totalPaid, 2, '.', ''),
                'balance_due' => number_format(max(0.0, $total - $totalPaid), 2, '.', ''),
            ],
            'customer_summary' => $customerSummary,
        ];
    }

    public function receipt(int $soId): array
    {
        return $this->hydrate($soId);
    }

    private function resolveCustomerForOrder(array $order): ?int
    {
        if (!empty($order['customer_id'])) {
            return (int) $order['customer_id'];
        }

        $customerName = trim((string) ($order['customer_name'] ?? ''));
        if ($customerName === '') {
            return null;
        }

        $customer = $this->customers->findOrCreateByName($customerName);
        return (int) $customer['id'];
    }
}
