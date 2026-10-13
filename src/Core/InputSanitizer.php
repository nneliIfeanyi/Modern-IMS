<?php

declare(strict_types=1);

namespace App\Core;

final class InputSanitizer
{
    public static function sanitizeArray(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            $safeKey = is_string($key) ? self::sanitizeString($key) : $key;
            $result[$safeKey] = self::sanitizeValue($value);
        }

        return $result;
    }

    public static function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return self::sanitizeArray($value);
        }

        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        return $value;
    }

    private static function sanitizeString(string $value): string
    {
        // Drop null bytes and non-printable control characters except whitespace controls.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    }
}
