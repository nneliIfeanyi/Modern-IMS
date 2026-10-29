<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class WhatsAppWebhookController
{
    public function verify(Request $request)
    {
        $verifyToken = trim((string) ($_ENV['WHATSAPP_WEBHOOK_VERIFY_TOKEN'] ?? ''));
        if ($verifyToken === '') {
            return Response::raw('Webhook verify token is not configured.', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $mode = $this->queryValue($request, 'hub.mode');
        $providedToken = $this->queryValue($request, 'hub.verify_token');
        $challenge = $this->queryValue($request, 'hub.challenge');

        if ($mode !== 'subscribe' || $challenge === '' || !hash_equals($verifyToken, $providedToken)) {
            return Response::raw('Forbidden', 403, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return Response::raw($challenge, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function receive(Request $request)
    {
        $appSecret = trim((string) ($_ENV['WHATSAPP_APP_SECRET'] ?? ''));
        if ($appSecret === '') {
            return Response::raw('Webhook signature verification is not configured.', 503, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        $signature = $this->headerValue($request, 'X-Hub-Signature-256');
        $expectedSignature = 'sha256=' . hash_hmac('sha256', $request->rawBody(), $appSecret);
        if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
            return Response::raw('Invalid signature', 403, [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        return Response::raw('EVENT_RECEIVED', 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function queryValue(Request $request, string $name): string
    {
        $normalizedName = str_replace('.', '_', $name);
        $value = $request->query($name, $request->query($normalizedName, ''));

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function headerValue(Request $request, string $name): string
    {
        foreach ($request->headers() as $headerName => $value) {
            if (strcasecmp((string) $headerName, $name) === 0 && is_scalar($value)) {
                return trim((string) $value);
            }
        }

        return '';
    }
}