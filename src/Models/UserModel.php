<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UserModel
{
    public function allForAdmin(): array
    {
        $sql = 'SELECT u.id, u.full_name, u.email, u.role_id, u.is_active, r.name AS role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                ORDER BY u.full_name ASC, u.id ASC';

        return Database::connection()->query($sql)->fetchAll() ?: [];
    }

    public function allRoles(): array
    {
        $sql = 'SELECT id, name FROM roles ORDER BY name ASC';
        return Database::connection()->query($sql)->fetchAll() ?: [];
    }

    public function roleExists(int $roleId): bool
    {
        $stmt = Database::connection()->prepare('SELECT id FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $roleId]);

        return (bool) $stmt->fetch();
    }

    public function findRole(int $roleId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $roleId]);
        $role = $stmt->fetch();

        return $role ?: null;
    }

    public function findForAdmin(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.full_name, u.email, u.role_id, u.is_active, r.name AS role_name
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function createForAdmin(string $fullName, string $email, int $roleId, string $passwordHash): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (full_name, email, role_id, password_hash, is_active)
             VALUES (:full_name, :email, :role_id, :password_hash, 1)'
        );
        $stmt->execute([
            'full_name' => $fullName,
            'email' => $email,
            'role_id' => $roleId,
            'password_hash' => $passwordHash,
        ]);

        return (int) Database::connection()->lastInsertId();
    }

    public function updateForAdmin(int $id, string $fullName, string $email, int $roleId, bool $isActive, ?string $passwordHash): bool
    {
        $sql = 'UPDATE users
                SET full_name = :full_name,
                    email = :email,
                    role_id = :role_id,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP';
        $params = [
            'id' => $id,
            'full_name' => $fullName,
            'email' => $email,
            'role_id' => $roleId,
            'is_active' => $isActive ? 1 : 0,
        ];

        if ($passwordHash !== null) {
            $sql .= ', password_hash = :password_hash';
            $params['password_hash'] = $passwordHash;
        }

        $sql .= ' WHERE id = :id';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function deactivateForAdmin(int $id): bool
    {
        $stmt = Database::connection()->prepare(
            'UPDATE users SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    public function activeAdminCount(): int
    {
        $stmt = Database::connection()->query(
            "SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.name = 'admin'"
        );

        return (int) $stmt->fetchColumn();
    }

    public function findByEmail(string $email): ?array
    {
        $sql = 'SELECT u.id, u.full_name, u.email, u.password_hash, u.is_active, r.name AS role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.email = :email
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function findActiveById(int $id): ?array
    {
        $sql = 'SELECT u.id, u.full_name, u.email, u.is_active, r.name AS role_name
                FROM users u
                INNER JOIN roles r ON r.id = u.role_id
                WHERE u.id = :id AND u.is_active = 1
                LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        return $user ?: null;
    }

    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = ['email' => $email];

        if ($exceptId !== null && $exceptId > 0) {
            $sql .= ' AND id != :id';
            $params['id'] = $exceptId;
        }

        $sql .= ' LIMIT 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetch();
    }

    public function updateProfile(int $id, array $data): bool
    {
        $data['id'] = $id;
        $sql = 'UPDATE users
                SET
                    full_name = :full_name,
                    email = :email,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id AND is_active = 1';

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($data);

        return $stmt->rowCount() > 0;
    }

    public function verifyPassword(int $id, string $plain): bool
    {
        $stmt = Database::connection()->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row || !isset($row['password_hash'])) {
            return false;
        }

        return password_verify($plain, (string) $row['password_hash']);
    }

    public function updatePasswordHash(int $id, string $hash): bool
    {
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1');
        $stmt->execute([
            'id' => $id,
            'password_hash' => $hash,
        ]);

        return $stmt->rowCount() > 0;
    }
}
