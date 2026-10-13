<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\SmsLogModel;

final class SmsNotificationService
{
    private SmsLogModel $logs;

    public function __construct()
    {
        $this->logs = new SmsLogModel();
    }

    public function notifyLowStock(int $productId, int $userId): void
    {
        try {
            $this->sendLowStock($productId, $userId);
        } catch (\Throwable $exception) {
            error_log('SMS low-stock notification failed: ' . $exception->getMessage());
        }
    }

    public function notifyPayment(array $customer, float $amount, string $method, int $paymentId, int $userId): void
    {
        try {
            $this->sendPayment($customer, $amount, $method, $paymentId, $userId);
        } catch (\Throwable $exception) {
            error_log('SMS payment notification failed: ' . $exception->getMessage());
        }
    }

    public function recent(int $limit = 25): array
    {
        return $this->logs->recent($limit);
    }

    public function sendExpiryBatch(array $batch, string $recipient): void
    {
        $today = new \DateTimeImmutable('today');
        $expiry = new \DateTimeImmutable((string) $batch['expiry_date']);
        $days = max(0, (int) $today->diff($expiry)->format('%r%a'));
        $message = sprintf(
            'IMS expiry alert: %s batch %s has %s units and expires in %d day(s) on %s at %s.',
            $batch['product_name'],
            $batch['batch_number'],
            $batch['quantity'],
            $days,
            $batch['expiry_date'],
            $batch['warehouse_name']
        );

        $this->send('expiry_alert', $recipient, $message, [
            'product_id' => null,
            'batch_id' => (int) $batch['id'],
        ]);
    }

    private function sendLowStock(int $productId, int $userId): void
    {
        $stmt = Database::connection()->prepare('SELECT
                p.name,
                p.sku,
                p.reorder_level,
                COALESCE(SUM(s.quantity), 0) AS quantity
            FROM products p
            LEFT JOIN stock s ON s.product_id = p.id
            WHERE p.id = :id
            GROUP BY p.id, p.name, p.sku, p.reorder_level');
        $stmt->execute(['id' => $productId]);
        $product = $stmt->fetch();

        if (!$product || (float) $product['quantity'] > (float) $product['reorder_level']) {
            return;
        }

        if ($this->logs->hasRecentForProduct('low_stock', $productId)) {
            return;
        }

        $recipients = $this->configuredRecipients('SMS_LOW_STOCK_RECIPIENTS');
        if ($recipients === []) {
            return;
        }

        $message = sprintf(
            'IMS low stock: %s (%s) has %s units remaining. Reorder level: %s.',
            $product['name'],
            $product['sku'],
            $product['quantity'],
            $product['reorder_level']
        );

        foreach ($recipients as $recipient) {
            $this->send('low_stock', $recipient, $message, [
                'product_id' => $productId,
                'user_id' => $userId,
            ]);
        }
    }

    private function sendPayment(array $customer, float $amount, string $method, int $paymentId, int $userId): void
    {
        if (!$this->envBool('SMS_PAYMENT_ALERTS', true) || trim((string) ($customer['phone'] ?? '')) === '') {
            return;
        }

        $message = sprintf(
            'Payment received by IMS: %s paid NGN %s via %s. Thank you.',
            $customer['full_name'],
            number_format($amount, 2),
            str_replace('_', ' ', $method)
        );

        $this->send('customer_payment', (string) $customer['phone'], $message, [
            'customer_id' => (int) $customer['id'],
            'payment_id' => $paymentId,
            'user_id' => $userId,
        ]);
    }

    private function send(string $eventType, string $recipient, string $message, array $relations): void
    {
        $logId = null;
        try {
            $normalizedRecipient = $this->normalizeRecipient($recipient);
            if ($normalizedRecipient === '') {
                throw new \RuntimeException('Recipient phone number is invalid.');
            }

            $logId = $this->logs->createPending([
                'event_type' => $eventType,
                'recipient' => $normalizedRecipient,
                'message' => $message,
                'customer_id' => $relations['customer_id'] ?? null,
                'product_id' => $relations['product_id'] ?? null,
                'payment_id' => $relations['payment_id'] ?? null,
                'batch_id' => $relations['batch_id'] ?? null,
                'created_by' => $relations['user_id'] ?? null,
            ]);

            $response = $this->callProvider($normalizedRecipient, $message);
            $this->logs->markSent($logId, $response['message_id'], $response['body']);
        } catch (\Throwable $exception) {
            if ($logId !== null) {
                try {
                    $this->logs->markFailed($logId, $exception->getMessage());
                } catch (\Throwable $logException) {
                    error_log('SMS failure could not be recorded: ' . $logException->getMessage());
                }
            }
            throw $exception;
        }
    }

    private function callProvider(string $recipient, string $message): array
    {
        $url = trim((string) ($_ENV['EBULKSMS_API_URL'] ?? ''));
        $username = trim((string) ($_ENV['EBULKSMS_USERNAME'] ?? ''));
        $apiKey = trim((string) ($_ENV['EBULKSMS_API_KEY'] ?? ''));
        $sender = trim((string) ($_ENV['EBULKSMS_SENDER_ID'] ?? 'IMS'));

        if ($url === '' || $username === '' || $apiKey === '') {
            throw new \RuntimeException('eBulkSMS is not configured.');
        }

        $payload = [
            'SMS' => [
                'auth' => [
                    'username' => $username,
                    'apikey' => $apiKey,
                ],
                'message' => [
                    'sender' => $sender,
                    'messagetext' => $message,
                    'flash' => '0',
                    'recipients' => [
                        ['number' => $recipient],
                    ],
                ],
            ],
        ];

        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize SMS provider client.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('eBulkSMS request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }

        $body = (string) $body;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('eBulkSMS returned HTTP ' . $status . ': ' . mb_substr($body, 0, 500));
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded) && $this->providerIndicatesFailure($decoded)) {
            throw new \RuntimeException('eBulkSMS rejected the message: ' . mb_substr($body, 0, 500));
        }

        return [
            'body' => mb_substr($body, 0, 2000),
            'message_id' => $this->findMessageId($decoded),
        ];
    }

    private function providerIndicatesFailure(array $response): bool
    {
        $status = strtolower((string) ($response['status'] ?? $response['Status'] ?? ''));
        return in_array($status, ['error', 'failed', 'failure', 'false'], true);
    }

    private function findMessageId(mixed $value): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $key => $item) {
            if (in_array(strtolower((string) $key), ['message_id', 'messageid', 'messageid', 'id'], true) && is_scalar($item)) {
                return (string) $item;
            }
            $found = $this->findMessageId($item);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function configuredRecipients(string $key): array
    {
        $value = (string) ($_ENV[$key] ?? '');
        $recipients = array_map('trim', explode(',', $value));
        $recipients = array_filter($recipients, static fn(string $recipient): bool => $recipient !== '');

        return array_values(array_unique($recipients));
    }

    private function normalizeRecipient(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0')) {
            return '234' . substr($digits, 1);
        }

        return $digits;
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? null;
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
