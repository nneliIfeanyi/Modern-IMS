<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

final class CustomerModel
{
    public function all(string $search = ''): array
    {
        $where = ['c.is_active = 1'];
        $params = [];

        $term = trim($search);
        if ($term !== '') {
            $where[] = '(c.customer_code LIKE :term OR c.full_name LIKE :term_name OR COALESCE(c.phone, \'\') LIKE :term_phone OR COALESCE(c.email, \'\') LIKE :term_email)';
            $params['term'] = '%' . $term . '%';
            $params['term_name'] = '%' . $term . '%';
            $params['term_phone'] = '%' . $term . '%';
            $params['term_email'] = '%' . $term . '%';
        }

        $sql = "SELECT
                    c.id,
                    c.customer_code,
                    c.full_name,
                    c.phone,
                    c.email,
                    c.address,
                    c.credit_limit,
                    c.opening_balance,
                    c.is_active,
                    c.created_at,
                    c.updated_at,
                    COALESCE(SUM(CASE WHEN cl.direction = 'debit' THEN cl.amount ELSE -cl.amount END), 0) + c.opening_balance AS balance,
                    COALESCE(SUM(CASE WHEN cl.direction = 'debit' THEN cl.amount ELSE 0 END), 0) AS total_debits,
                    COALESCE(SUM(CASE WHEN cl.direction = 'credit' THEN cl.amount ELSE 0 END), 0) AS total_credits
                FROM customers c
                LEFT JOIN customer_ledger cl ON cl.customer_id = c.id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY c.id, c.customer_code, c.full_name, c.phone, c.email, c.address, c.credit_limit, c.opening_balance, c.is_active, c.created_at, c.updated_at
                ORDER BY c.full_name ASC";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }

    public function findById(int $id): ?array
    {
        $sql = "SELECT
                    c.id,
                    c.customer_code,
                    c.full_name,
                    c.phone,
                    c.email,
                    c.address,
                    c.credit_limit,
                    c.opening_balance,
                    c.is_active,
                    c.created_at,
                    c.updated_at,
                    COALESCE(SUM(CASE WHEN cl.direction = 'debit' THEN cl.amount ELSE -cl.amount END), 0) + c.opening_balance AS balance
                FROM customers c
                LEFT JOIN customer_ledger cl ON cl.customer_id = c.id
                WHERE c.id = :id
                GROUP BY c.id, c.customer_code, c.full_name, c.phone, c.email, c.address, c.credit_limit, c.opening_balance, c.is_active, c.created_at, c.updated_at
                LIMIT 1";

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $sql = 'INSERT INTO customers (customer_code, full_name, phone, email, address, credit_limit, opening_balance, is_active)
                VALUES (:customer_code, :full_name, :phone, :email, :address, :credit_limit, :opening_balance, 1)';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);
        return (int) Database::connection()->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $data['id'] = $id;
        $sql = 'UPDATE customers
                SET
                    full_name = :full_name,
                    phone = :phone,
                    email = :email,
                    address = :address,
                    credit_limit = :credit_limit,
                    opening_balance = :opening_balance,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);
        return $stmt->rowCount() > 0;
    }

    public function deactivate(int $id): bool
    {
        $stmt = Database::connection()->prepare('UPDATE customers SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function findActiveById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM customers WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function creditStatusForUpdate(int $id): ?array
    {
        $customerStmt = Database::connection()->prepare('SELECT id, full_name, credit_limit, opening_balance, is_active
            FROM customers
            WHERE id = :id AND is_active = 1
            LIMIT 1
            FOR UPDATE');
        $customerStmt->execute(['id' => $id]);
        $customer = $customerStmt->fetch();

        if (!$customer) {
            return null;
        }

        $ledgerStmt = Database::connection()->prepare("SELECT
                COALESCE(SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END), 0) AS ledger_balance
            FROM customer_ledger
            WHERE customer_id = :customer_id");
        $ledgerStmt->execute(['customer_id' => $id]);
        $ledger = $ledgerStmt->fetch() ?: ['ledger_balance' => 0];

        return [
            'id' => (int) $customer['id'],
            'full_name' => (string) $customer['full_name'],
            'credit_limit' => (float) $customer['credit_limit'],
            'balance' => (float) $customer['opening_balance'] + (float) $ledger['ledger_balance'],
        ];
    }

    public function findByName(string $fullName): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM customers WHERE full_name = :full_name LIMIT 1');
        $stmt->execute(['full_name' => $fullName]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findOrCreateByName(string $fullName): array
    {
        $existing = $this->findByName($fullName);
        if ($existing !== null) {
            return $existing;
        }

        $code = $this->nextCustomerCode();
        $id = $this->create([
            'customer_code' => $code,
            'full_name' => $fullName,
            'phone' => null,
            'email' => null,
            'address' => null,
            'credit_limit' => '0.00',
            'opening_balance' => '0.00',
        ]);

        $created = $this->findById($id);
        if ($created === null) {
            throw new \RuntimeException('Unable to create customer record.');
        }

        return $created;
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM customers WHERE customer_code = :customer_code';
        $params = ['customer_code' => $code];
        if ($exceptId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }
        $sql .= ' LIMIT 1';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    public function hasOpenSalesOrders(int $customerId): bool
    {
        $stmt = Database::connection()->prepare("SELECT id FROM sales_orders WHERE customer_id = :customer_id AND status IN ('draft', 'confirmed', 'partially_fulfilled') LIMIT 1");
        $stmt->execute(['customer_id' => $customerId]);
        return (bool) $stmt->fetch();
    }

    public function customerCode(int $id): ?string
    {
        $stmt = Database::connection()->prepare('SELECT customer_code FROM customers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? (string) $row['customer_code'] : null;
    }

    private function nextCustomerCode(): string
    {
        $stmt = Database::connection()->query("SELECT customer_code FROM customers WHERE customer_code LIKE 'CUST-%' ORDER BY id DESC LIMIT 1");
        $row = $stmt ? $stmt->fetch() : null;
        $current = 0;
        if ($row && preg_match('/CUST-(\d+)/', (string) $row['customer_code'], $matches)) {
            $current = (int) $matches[1];
        }

        return 'CUST-' . str_pad((string) ($current + 1), 4, '0', STR_PAD_LEFT);
    }
}
