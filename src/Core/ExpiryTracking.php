<?php

declare(strict_types=1);

namespace App\Core;

final class ExpiryTracking
{
    public static function enabled(): bool
    {
        if (array_key_exists('EXPIRY_TRACKING_ENABLED', $_ENV)) {
            return filter_var($_ENV['EXPIRY_TRACKING_ENABLED'], FILTER_VALIDATE_BOOLEAN);
        }

        return trim((string) ($_ENV['SMS_EXPIRY_RECIPIENTS'] ?? '')) !== '';
    }
}
