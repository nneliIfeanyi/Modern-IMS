<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class CustomerLedgerModel
{
    public function record(array $data): int
    {
        $sql = 'INSERT INTO customer_ledger (
                    customer_id,
                    sales_order_id,
                    sales_order_payment_id,
                    entry_type,
                    direction,
                    amount,
                    reference,
                    notes,
                    created_by
                ) VALUES (
                    :customer_id,
                    :sales_order_id,
                    :sales_order_payment_id,
                    :entry_type,
                    :direction,
                    :amount,
                    :reference,
                    :notes,
                    :created_by
                )';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);
        return (int) Database::connection()->lastInsertId();
    }

    public function summary(int $customerId): array
    {
        $stmt = Database::connection()->prepare("SELECT
                c.opening_balance,
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE 0 END), 0) AS total_debits,
                COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS total_credits
            FROM customers c
            LEFT JOIN customer_ledger cl ON cl.customer_id = c.id
            WHERE c.id = :customer_id
            GROUP BY c.id, c.opening_balance");
        $stmt->execute(['customer_id' => $customerId]);
        $row = $stmt->fetch() ?: ['total_debits' => 0, 'total_credits' => 0];

        return [
            'total_debits' => (float) $row['total_debits'],
            'total_credits' => (float) $row['total_credits'],
            'balance' => (float) ($row['opening_balance'] ?? 0) + (float) $row['total_debits'] - (float) $row['total_credits'],
        ];
    }

    public function entries(int $customerId, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare('SELECT
                cl.id,
                cl.entry_type,
                cl.direction,
                cl.amount,
                cl.reference,
                cl.notes,
                cl.sales_order_id,
                cl.sales_order_payment_id,
                cl.created_at,
                u.full_name AS created_by_name
            FROM customer_ledger cl
            LEFT JOIN users u ON u.id = cl.created_by
            WHERE cl.customer_id = :customer_id
            ORDER BY cl.created_at DESC, cl.id DESC
            LIMIT :limit');
        $stmt->bindValue(':customer_id', $customerId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    }
}
