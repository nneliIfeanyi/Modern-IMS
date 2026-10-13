<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ExpiryAlertModel
{
    public function claim(int $batchId, string $recipient): ?int
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO expiry_alerts (batch_id, recipient, status)
            VALUES (:batch_id, :recipient, \'pending\')');
        $stmt->execute(['batch_id' => $batchId, 'recipient' => $recipient]);

        if ($stmt->rowCount() > 0) {
            return (int) Database::connection()->lastInsertId();
        }

        $existing = Database::connection()->prepare('SELECT id, status
            FROM expiry_alerts
            WHERE batch_id = :batch_id AND recipient = :recipient
            LIMIT 1');
        $existing->execute(['batch_id' => $batchId, 'recipient' => $recipient]);
        $row = $existing->fetch();

        if (!$row || $row['status'] === 'sent') {
            return null;
        }

        $retry = Database::connection()->prepare('UPDATE expiry_alerts
            SET status = \'pending\', provider_response = NULL
            WHERE id = :id AND status = \'failed\'');
        $retry->execute(['id' => (int) $row['id']]);

        return $retry->rowCount() > 0 ? (int) $row['id'] : null;
    }

    public function markSent(int $id): void
    {
        $stmt = Database::connection()->prepare('UPDATE expiry_alerts
            SET status = \'sent\', sent_at = CURRENT_TIMESTAMP
            WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function markFailed(int $id, string $response): void
    {
        $stmt = Database::connection()->prepare('UPDATE expiry_alerts
            SET status = \'failed\', provider_response = :provider_response
            WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'provider_response' => mb_substr($response, 0, 2000),
        ]);
    }
}