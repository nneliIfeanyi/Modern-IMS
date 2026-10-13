<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SupplierModel
{
    public function allActive(?string $search = null): array
    {
        $sql = 'SELECT
                    s.id,
                    s.name,
                    s.contact_person,
                    s.email,
                    s.phone,
                    s.address,
                    s.is_active,
                    s.created_at,
                    s.updated_at,
                    (
                        SELECT COUNT(*)
                        FROM purchase_orders po
                        WHERE po.supplier_id = s.id
                          AND po.status IN (\'draft\', \'ordered\', \'partially_received\')
                    ) AS open_po_count,
                    (
                        SELECT COALESCE(SUM(poi.qty_received * poi.unit_cost), 0)
                        FROM purchase_orders po2
                        INNER JOIN purchase_order_items poi ON poi.purchase_order_id = po2.id
                        WHERE po2.supplier_id = s.id
                          AND po2.status IN (\'partially_received\', \'received\')
                          AND YEAR(COALESCE(po2.order_date, po2.created_at)) = YEAR(CURDATE())
                    ) AS total_spend_ytd
                FROM suppliers s
                WHERE s.is_active = 1';

        $params = [];
        $term = trim((string) $search);
        if ($term !== '') {
            $sql .= ' AND (
                        s.name LIKE :search_name
                        OR COALESCE(s.contact_person, \'\') LIKE :search_contact
                        OR COALESCE(s.email, \'\') LIKE :search_email
                        OR COALESCE(s.phone, \'\') LIKE :search_phone
                    )';
            $params['search_name'] = '%' . $term . '%';
            $params['search_contact'] = '%' . $term . '%';
            $params['search_email'] = '%' . $term . '%';
            $params['search_phone'] = '%' . $term . '%';
        }

        $sql .= ' ORDER BY s.name ASC';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll() ?: [];
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO suppliers (name, contact_person, email, phone, address, is_active)
                VALUES (:name, :contact_person, :email, :phone, :address, 1)';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data['id'] = $id;
        $sql = 'UPDATE suppliers
                SET
                    name = :name,
                    contact_person = :contact_person,
                    email = :email,
                    phone = :phone,
                    address = :address,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return $stmt->rowCount() > 0;
    }

    public function deactivate(int $id): bool
    {
        $sql = 'UPDATE suppliers
                SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM suppliers WHERE name = :name AND is_active = 1';
        $params = ['name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function hasOpenPurchaseOrders(int $id): bool
    {
        $sql = 'SELECT id
                FROM purchase_orders
                WHERE supplier_id = :supplier_id
                  AND status IN (\'draft\', \'ordered\', \'partially_received\')
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['supplier_id' => $id]);

        return (bool) $stmt->fetch();
    }

    public function findById(int $id): ?array
    {
        $sql = 'SELECT id, name, contact_person, email, phone, address, is_active, created_at, updated_at
                FROM suppliers
                WHERE id = :id
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
