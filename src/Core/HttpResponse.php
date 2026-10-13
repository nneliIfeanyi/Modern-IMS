<?php

declare(strict_types=1);

namespace App\Core;

final class HttpResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly mixed $body,
        public readonly array $headers = [],
        public readonly bool $raw = false
    ) {}
}
