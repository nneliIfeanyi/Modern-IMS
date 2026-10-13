<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class SalesOrderModel
{
    public function list(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        $where = ['1 = 1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $where[] = 'so.status = :status';
            $params['status'] = $status;
        }

        $warehouseId = (int) ($filters['warehouse_id'] ?? 0);
        if ($warehouseId > 0) {
            $where[] = 'so.warehouse_id = :warehouse_id';
            $params['warehouse_id'] = $warehouseId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = "(so.so_number LIKE :search_number OR COALESCE(c.full_name, so.customer_name, '') LIKE :search_customer)";
            $params['search_number'] = '%' . $search . '%';
            $params['search_customer'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM sales_orders so {$whereSql}";
        $countStmt = Database::connection()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = "SELECT
                    so.id,
                    so.so_number,
                    so.customer_id,
                    COALESCE(c.full_name, so.customer_name) AS customer_name,
                    so.status,
                    so.payment_mode,
                    so.initial_payment,
                    so.order_date,
                    so.created_at,
                    so.updated_at,
                    so.warehouse_id,
                    w.name AS warehouse_name,
                    COALESCE((SELECT SUM(amount) FROM sales_order_payments sop WHERE sop.sales_order_id = so.id), 0) AS total_paid,
                    COALESCE(COUNT(soi.id), 0) AS item_count,
                    COALESCE(SUM(soi.qty_ordered * soi.unit_price), 0) AS total_amount
                FROM sales_orders so
                INNER JOIN warehouses w ON w.id = so.warehouse_id
                LEFT JOIN customers c ON c.id = so.customer_id
                LEFT JOIN sales_order_items soi ON soi.sales_order_id = so.id
                {$whereSql}
                GROUP BY
                    so.id,
                    so.so_number,
                    so.customer_id,
                    c.full_name,
                    so.customer_name,
                    so.status,
                    so.payment_mode,
                    so.initial_payment,
                    so.order_date,
                    so.created_at,
                    so.updated_at,
                    so.warehouse_id,
                    w.name
                ORDER BY so.created_at DESC, so.id DESC
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

    public function findById(int $id, bool $forUpdate = false): ?array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $sql = 'SELECT
                    so.id,
                    so.so_number,
                    so.warehouse_id,
                    w.name AS warehouse_name,
                    so.customer_id,
                    so.customer_name,
                    COALESCE(c.full_name, so.customer_name) AS customer_display_name,
                    so.status,
                    so.payment_mode,
                    so.order_date,
                    so.notes,
                    so.created_by,
                    u.full_name AS created_by_name,
                    so.created_at,
                    so.updated_at,
                    so.initial_payment
                FROM sales_orders so
                INNER JOIN warehouses w ON w.id = so.warehouse_id
                LEFT JOIN customers c ON c.id = so.customer_id
                LEFT JOIN users u ON u.id = so.created_by
                WHERE so.id = :id
                LIMIT 1' . $lock;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function itemsBySoId(int $soId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $sql = 'SELECT
                    soi.id,
                    soi.sales_order_id,
                    soi.product_id,
                    p.name AS product_name,
                    p.sku,
                    soi.qty_ordered,
                    soi.qty_fulfilled,
                    soi.unit_price,
                    (soi.qty_ordered * soi.unit_price) AS subtotal
                FROM sales_order_items soi
                INNER JOIN products p ON p.id = soi.product_id
                WHERE soi.sales_order_id = :so_id
                ORDER BY soi.id ASC' . $lock;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['so_id' => $soId]);

        return $stmt->fetchAll() ?: [];
    }

    public function createDraft(array $header, int $createdBy): int
    {
        $tmpNumber = 'SO-TMP-' . uniqid('', true);

        $sql = 'INSERT INTO sales_orders (
                    so_number,
                    warehouse_id,
                    customer_id,
                    customer_name,
                    status,
                    order_date,
                    payment_mode,
                    initial_payment,
                    notes,
                    created_by
                ) VALUES (
                    :so_number,
                    :warehouse_id,
                    :customer_id,
                    :customer_name,
                    :status,
                    :order_date,
                    :payment_mode,
                    :initial_payment,
                    :notes,
                    :created_by
                )';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'so_number' => $tmpNumber,
            'warehouse_id' => $header['warehouse_id'],
            'customer_id' => $header['customer_id'] ?? null,
            'customer_name' => $header['customer_name'],
            'status' => 'draft',
            'order_date' => $header['order_date'],
            'payment_mode' => $header['payment_mode'] ?? 'cash',
            'initial_payment' => $header['initial_payment'] ?? '0.00',
            'notes' => $header['notes'],
            'created_by' => $createdBy,
        ]);

        $id = (int) Database::connection()->lastInsertId();
        $soNumber = $this->buildSoNumber($id);

        $update = Database::connection()->prepare('UPDATE sales_orders SET so_number = :so_number WHERE id = :id');
        $update->execute(['so_number' => $soNumber, 'id' => $id]);

        return $id;
    }

    public function updateDraftHeader(int $id, array $header): void
    {
        $sql = 'UPDATE sales_orders
                SET
                    warehouse_id = :warehouse_id,
                    customer_id = :customer_id,
                    customer_name = :customer_name,
                    order_date = :order_date,
                    payment_mode = :payment_mode,
                    initial_payment = :initial_payment,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'warehouse_id' => $header['warehouse_id'],
            'customer_id' => $header['customer_id'] ?? null,
            'customer_name' => $header['customer_name'],
            'order_date' => $header['order_date'],
            'payment_mode' => $header['payment_mode'] ?? 'cash',
            'initial_payment' => $header['initial_payment'] ?? '0.00',
            'notes' => $header['notes'],
        ]);
    }

    public function replaceItems(int $soId, array $items): void
    {
        $delete = Database::connection()->prepare('DELETE FROM sales_order_items WHERE sales_order_id = :so_id');
        $delete->execute(['so_id' => $soId]);

        $insert = Database::connection()->prepare('INSERT INTO sales_order_items (sales_order_id, product_id, qty_ordered, qty_fulfilled, unit_price) VALUES (:so_id, :product_id, :qty_ordered, :qty_fulfilled, :unit_price)');

        foreach ($items as $item) {
            $insert->execute([
                'so_id' => $soId,
                'product_id' => $item['product_id'],
                'qty_ordered' => $item['qty_ordered'],
                'qty_fulfilled' => 0,
                'unit_price' => $item['unit_price'],
            ]);
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $sql = 'UPDATE sales_orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function attachCustomer(int $id, ?int $customerId): void
    {
        $stmt = Database::connection()->prepare('UPDATE sales_orders SET customer_id = :customer_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'customer_id' => $customerId,
        ]);
    }

    public function totalAmountBySoId(int $soId): float
    {
        $stmt = Database::connection()->prepare('SELECT COALESCE(SUM(qty_ordered * unit_price), 0) AS total_amount FROM sales_order_items WHERE sales_order_id = :so_id');
        $stmt->execute(['so_id' => $soId]);
        $row = $stmt->fetch() ?: ['total_amount' => 0];
        return (float) $row['total_amount'];
    }

    public function customerIdBySoId(int $soId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT customer_id FROM sales_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $soId]);
        $row = $stmt->fetch();
        if (!$row || $row['customer_id'] === null) {
            return null;
        }

        return (int) $row['customer_id'];
    }

    public function paymentModeBySoId(int $soId): string
    {
        $stmt = Database::connection()->prepare('SELECT payment_mode FROM sales_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $soId]);
        $row = $stmt->fetch();
        return (string) ($row['payment_mode'] ?? 'cash');
    }

    public function updateItemQtyFulfilled(int $itemId, float $qtyFulfilled): void
    {
        $sql = 'UPDATE sales_order_items
                SET qty_fulfilled = :qty_fulfilled, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id' => $itemId,
            'qty_fulfilled' => $qtyFulfilled,
        ]);
    }

    private function buildSoNumber(int $id): string
    {
        return 'SO-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
