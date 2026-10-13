<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\ExpiryTracking;
use App\Models\ExpiryAlertModel;
use App\Models\InventoryBatchModel;

final class ExpiryNotificationService
{
    private InventoryBatchModel $batches;
    private ExpiryAlertModel $alerts;
    private SmsNotificationService $sms;

    public function __construct()
    {
        $this->batches = new InventoryBatchModel();
        $this->alerts = new ExpiryAlertModel();
        $this->sms = new SmsNotificationService();
    }

    public function check(): void
    {
        if (!ExpiryTracking::enabled()) {
            return;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        foreach ($this->batches->expiringForAlert() as $batch) {
            foreach ($recipients as $recipient) {
                $alertId = $this->alerts->claim((int) $batch['id'], $recipient);
                if ($alertId === null) {
                    continue;
                }

                try {
                    $this->sms->sendExpiryBatch($batch, $recipient);
                    $this->alerts->markSent($alertId);
                } catch (\Throwable $exception) {
                    $this->alerts->markFailed($alertId, $exception->getMessage());
                    error_log('SMS expiry notification failed: ' . $exception->getMessage());
                }
            }
        }
    }

    private function recipients(): array
    {
        $value = (string) ($_ENV['SMS_EXPIRY_RECIPIENTS'] ?? '');
        $recipients = array_map('trim', explode(',', $value));
        $recipients = array_filter($recipients, static fn(string $recipient): bool => $recipient !== '');

        return array_values(array_unique($recipients));
    }
}