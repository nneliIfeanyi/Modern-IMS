<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class PurchaseOrderModel
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
            $where[] = 'po.status = :status';
            $params['status'] = $status;
        }

        $supplierId = (int) ($filters['supplier_id'] ?? 0);
        if ($supplierId > 0) {
            $where[] = 'po.supplier_id = :supplier_id';
            $params['supplier_id'] = $supplierId;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(po.po_number LIKE :search_number OR s.name LIKE :search_supplier)';
            $params['search_number'] = '%' . $search . '%';
            $params['search_supplier'] = '%' . $search . '%';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $countSql = "SELECT COUNT(*) AS total FROM purchase_orders po INNER JOIN suppliers s ON s.id = po.supplier_id {$whereSql}";
        $countStmt = Database::connection()->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue(':' . $key, $value);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetch()['total'] ?? 0);

        $sql = "SELECT
                    po.id,
                    po.po_number,
                    po.status,
                    po.order_date,
                    po.expected_date,
                    po.created_at,
                    po.updated_at,
                    po.supplier_id,
                    s.name AS supplier_name,
                    po.warehouse_id,
                    w.name AS warehouse_name,
                    COALESCE(COUNT(poi.id), 0) AS item_count,
                    COALESCE(SUM(poi.qty_ordered * poi.unit_cost), 0) AS total_amount
                FROM purchase_orders po
                INNER JOIN suppliers s ON s.id = po.supplier_id
                INNER JOIN warehouses w ON w.id = po.warehouse_id
                LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id
                {$whereSql}
                GROUP BY
                    po.id, po.po_number, po.status, po.order_date, po.expected_date, po.created_at, po.updated_at,
                    po.supplier_id, s.name, po.warehouse_id, w.name
                ORDER BY po.created_at DESC, po.id DESC
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
                    po.id,
                    po.po_number,
                    po.supplier_id,
                    s.name AS supplier_name,
                    po.warehouse_id,
                    w.name AS warehouse_name,
                    po.status,
                    po.order_date,
                    po.expected_date,
                    po.notes,
                    po.created_by,
                    u.full_name AS created_by_name,
                    po.created_at,
                    po.updated_at
                FROM purchase_orders po
                INNER JOIN suppliers s ON s.id = po.supplier_id
                INNER JOIN warehouses w ON w.id = po.warehouse_id
                LEFT JOIN users u ON u.id = po.created_by
                WHERE po.id = :id
                LIMIT 1' . $lock;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function itemsByPoId(int $poId, bool $forUpdate = false): array
    {
        $lock = $forUpdate ? ' FOR UPDATE' : '';

        $sql = 'SELECT
                    poi.id,
                    poi.purchase_order_id,
                    poi.product_id,
                    p.name AS product_name,
                    p.sku,
                    poi.qty_ordered,
                    poi.qty_received,
                    poi.unit_cost,
                    (poi.qty_ordered * poi.unit_cost) AS subtotal
                FROM purchase_order_items poi
                INNER JOIN products p ON p.id = poi.product_id
                WHERE poi.purchase_order_id = :po_id
                ORDER BY poi.id ASC' . $lock;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['po_id' => $poId]);

        return $stmt->fetchAll() ?: [];
    }

    public function createDraft(array $header, int $createdBy): int
    {
        $tmpPoNumber = 'PO-TMP-' . uniqid('', true);

        $sql = 'INSERT INTO purchase_orders (
                    po_number,
                    supplier_id,
                    warehouse_id,
                    status,
                    order_date,
                    expected_date,
                    notes,
                    created_by
                ) VALUES (
                    :po_number,
                    :supplier_id,
                    :warehouse_id,
                    :status,
                    :order_date,
                    :expected_date,
                    :notes,
                    :created_by
                )';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'po_number' => $tmpPoNumber,
            'supplier_id' => $header['supplier_id'],
            'warehouse_id' => $header['warehouse_id'],
            'status' => 'draft',
            'order_date' => $header['order_date'],
            'expected_date' => $header['expected_date'],
            'notes' => $header['notes'],
            'created_by' => $createdBy,
        ]);

        $id = (int) Database::connection()->lastInsertId();
        $poNumber = $this->buildPoNumber($id);

        $update = Database::connection()->prepare('UPDATE purchase_orders SET po_number = :po_number WHERE id = :id');
        $update->execute(['po_number' => $poNumber, 'id' => $id]);

        return $id;
    }

    public function updateDraftHeader(int $id, array $header): void
    {
        $sql = 'UPDATE purchase_orders
                SET
                    supplier_id = :supplier_id,
                    warehouse_id = :warehouse_id,
                    order_date = :order_date,
                    expected_date = :expected_date,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'supplier_id' => $header['supplier_id'],
            'warehouse_id' => $header['warehouse_id'],
            'order_date' => $header['order_date'],
            'expected_date' => $header['expected_date'],
            'notes' => $header['notes'],
        ]);
    }

    public function replaceItems(int $poId, array $items): void
    {
        $delete = Database::connection()->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = :po_id');
        $delete->execute(['po_id' => $poId]);

        $insert = Database::connection()->prepare('INSERT INTO purchase_order_items (purchase_order_id, product_id, qty_ordered, qty_received, unit_cost) VALUES (:po_id, :product_id, :qty_ordered, :qty_received, :unit_cost)');

        foreach ($items as $item) {
            $insert->execute([
                'po_id' => $poId,
                'product_id' => $item['product_id'],
                'qty_ordered' => $item['qty_ordered'],
                'qty_received' => 0,
                'unit_cost' => $item['unit_cost'],
            ]);
        }
    }

    public function setStatus(int $id, string $status): void
    {
        $sql = 'UPDATE purchase_orders SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function updateItemQtyReceived(int $itemId, float $qtyReceived): void
    {
        $sql = 'UPDATE purchase_order_items
                SET qty_received = :qty_received, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            'id' => $itemId,
            'qty_received' => $qtyReceived,
        ]);
    }

    private function buildPoNumber(int $id): string
    {
        return 'PO-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }
}
