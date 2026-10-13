<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SalesOrderPaymentModel
{
    public function create(array $data): int
    {
        $sql = 'INSERT INTO sales_order_payments (
                    sales_order_id,
                    amount,
                    method,
                    reference,
                    notes,
                    paid_at,
                    created_by
                ) VALUES (
                    :sales_order_id,
                    :amount,
                    :method,
                    :reference,
                    :notes,
                    :paid_at,
                    :created_by
                )';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);
        return (int) Database::connection()->lastInsertId();
    }

    public function bySalesOrderId(int $salesOrderId): array
    {
        $stmt = Database::connection()->prepare('SELECT
                id,
                sales_order_id,
                amount,
                method,
                reference,
                notes,
                paid_at,
                created_at
            FROM sales_order_payments
            WHERE sales_order_id = :sales_order_id
            ORDER BY paid_at ASC, id ASC');
        $stmt->execute(['sales_order_id' => $salesOrderId]);
        return $stmt->fetchAll() ?: [];
    }

    public function totalPaid(int $salesOrderId): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(amount), 0) AS total_paid FROM sales_order_payments WHERE sales_order_id = :sales_order_id');
        $stmt->execute(['sales_order_id' => $salesOrderId]);
        $row = $stmt->fetch() ?: ['total_paid' => 0];
        return (float) $row['total_paid'];
    }
}
