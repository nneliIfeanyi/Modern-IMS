<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SmsLogModel
{
    public function createPending(array $data): int
    {
        $data += ['batch_id' => null];
        $stmt = Database::connection()->prepare('INSERT INTO sms_logs (
                event_type,
                recipient,
                message,
                status,
                customer_id,
                product_id,
                payment_id,
                batch_id,
                created_by
            ) VALUES (
                :event_type,
                :recipient,
                :message,
                \'pending\',
                :customer_id,
                :product_id,
                :payment_id,
                :batch_id,
                :created_by
            )');
        $stmt->execute($data);

        return (int) Database::connection()->lastInsertId();
    }

    public function markSent(int $id, ?string $providerMessageId, string $providerResponse): void
    {
        $stmt = Database::connection()->prepare('UPDATE sms_logs
            SET status = \'sent\',
                provider_message_id = :provider_message_id,
                provider_response = :provider_response,
                sent_at = CURRENT_TIMESTAMP
            WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'provider_message_id' => $providerMessageId,
            'provider_response' => $providerResponse,
        ]);
    }

    public function markFailed(int $id, string $providerResponse): void
    {
        $stmt = Database::connection()->prepare('UPDATE sms_logs
            SET status = \'failed\', provider_response = :provider_response
            WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'provider_response' => $providerResponse,
        ]);
    }

    public function recent(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $stmt = Database::connection()->query('SELECT
                id,
                event_type,
                recipient,
                message,
                provider_message_id,
                status,
                provider_response,
                customer_id,
                product_id,
                payment_id,
                batch_id,
                created_at,
                sent_at
            FROM sms_logs
            ORDER BY created_at DESC, id DESC
            LIMIT ' . $limit);

        return $stmt->fetchAll() ?: [];
    }

    public function hasRecentForProduct(string $eventType, int $productId, int $hours = 24): bool
    {
                $hours = max(1, min($hours, 720));
                $stmt = Database::connection()->prepare('SELECT id
            FROM sms_logs
            WHERE event_type = :event_type
              AND product_id = :product_id
                            AND created_at >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ' . $hours . ' HOUR)
            LIMIT 1');
        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();

        return (bool) $stmt->fetch();
    }
}
