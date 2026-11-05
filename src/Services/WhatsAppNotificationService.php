<?php

declare(strict_types=1);

namespace App\Services;

final class WhatsAppNotificationService
{
    public function notifyPurchaseOrder(array $purchaseOrder, array $supplier): array
    {
        try {
            $this->sendPurchaseOrder($purchaseOrder, $supplier);

            return [
                'sent' => true,
                'message' => 'WhatsApp accepted the purchase-order notification.',
            ];
        } catch (\Exception $exception) {
            error_log('WhatsApp purchase-order notification failed: ' . $exception->getMessage());

            return [
                'sent' => false,
                'message' => 'WhatsApp notification failed. Check the server log and provider configuration.',
            ];
        }
    }

    private function sendPurchaseOrder(array $purchaseOrder, array $supplier): void
    {
        $accessToken = trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''));
        $phoneNumberId = trim((string) ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? ''));
        $apiVersion = trim((string) ($_ENV['WHATSAPP_GRAPH_API_VERSION'] ?? ''));
        $templateName = trim((string) ($_ENV['WHATSAPP_PO_TEMPLATE_NAME'] ?? ''));
        $templateLanguage = trim((string) ($_ENV['WHATSAPP_PO_TEMPLATE_LANGUAGE'] ?? 'en_US'));

        if ($accessToken === '' || $phoneNumberId === '' || $apiVersion === '' || $templateName === '') {
            throw new \RuntimeException('WhatsApp sending credentials, API version, or purchase-order template are not configured.');
        }
        if (!preg_match('/^v\d+\.\d+$/', $apiVersion)) {
            throw new \RuntimeException('WHATSAPP_GRAPH_API_VERSION must use the format vNN.N.');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $templateName)) {
            throw new \RuntimeException('WHATSAPP_PO_TEMPLATE_NAME contains invalid characters.');
        }

        $order = $purchaseOrder['order'] ?? [];
        $summary = $purchaseOrder['summary'] ?? [];
        if (!is_array($order) || !is_array($summary)) {
            throw new \RuntimeException('Purchase-order data is incomplete.');
        }

        $recipient = $this->normalizePhone((string) ($supplier['phone'] ?? ''));
        $supplierName = trim((string) ($supplier['name'] ?? ''));
        $poNumber = trim((string) ($order['po_number'] ?? ''));
        if ($supplierName === '' || $poNumber === '') {
            throw new \RuntimeException('Supplier or purchase-order details are incomplete.');
        }

        $expectedDate = trim((string) ($order['expected_date'] ?? ''));
        if ($expectedDate === '') {
            $expectedDate = 'Not specified';
        }

        $itemsText = $this->formatOrderItems($purchaseOrder['items'] ?? []);
        $totalAmount = (string) ($summary['total_amount'] ?? '0.00');
        $messageBody = sprintf(
            "Hello %s, IMS has sent purchase order %s.\nItems and quantities:\n%s\nOrder total: %s. Expected delivery: %s. Please confirm receipt.",
            $supplierName,
            $poNumber,
            $itemsText,
            $totalAmount,
            $expectedDate
        );
        if (mb_strlen($messageBody, 'UTF-8') > 1024) {
            throw new \RuntimeException('Purchase-order message exceeds WhatsApp template body length; no items were omitted.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $supplierName],
                        ['type' => 'text', 'text' => $poNumber],
                        ['type' => 'text', 'text' => $itemsText],
                        ['type' => 'text', 'text' => $totalAmount],
                        ['type' => 'text', 'text' => $expectedDate],
                    ],
                ]],
            ],
        ];

        $url = sprintf(
            'https://graph.facebook.com/%s/%s/messages',
            $apiVersion,
            rawurlencode($phoneNumberId)
        );
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize the WhatsApp provider client.');
        }

        try {
            $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedPayload,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
            ]);

            $body = curl_exec($handle);
            $error = curl_error($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($handle);
        }

        if ($body === false || $error !== '') {
            throw new \RuntimeException('WhatsApp provider request failed: ' . ($error !== '' ? $error : 'unknown error'));
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('WhatsApp provider returned HTTP ' . $status . '.');
        }

        $response = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($response) || empty($response['messages'][0]['id'])) {
            throw new \RuntimeException('WhatsApp provider did not confirm acceptance of the message.');
        }
    }

    private function formatOrderItems(mixed $items): string
    {
        if (!is_array($items) || $items === []) {
            throw new \RuntimeException('Purchase order has no items to include in the WhatsApp message.');
        }

        $lines = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                throw new \RuntimeException('Purchase-order item details are invalid.');
            }

            $productName = preg_replace('/\s+/u', ' ', trim((string) ($item['product_name'] ?? '')));
            $quantityValue = trim((string) ($item['qty_ordered'] ?? ''));
            if ($productName === null
                || $productName === ''
                || !preg_match('/^\d+(?:\.0+)?$/', $quantityValue)
                || (float) $quantityValue < 1) {
                throw new \RuntimeException('Purchase-order item name or quantity is invalid.');
            }

            $lines[] = sprintf('%s x %d', $productName, (int) $quantityValue);
        }

        return implode("\n", $lines);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim($phone)) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+' . substr($phone, 2);
        }
        if (!preg_match('/^\+?[1-9]\d{7,14}$/', $phone)) {
            throw new \RuntimeException('Supplier phone must include a valid international country code.');
        }

        return ltrim($phone, '+');
    }
}
