<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class UserModel
{
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
