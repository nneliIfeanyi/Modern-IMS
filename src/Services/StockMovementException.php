<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class StockMovementException extends RuntimeException
{
    private int $statusCode;
    private mixed $extra;

    public function __construct(string $message, int $statusCode = 400, mixed $extra = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->extra = $extra;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function extra(): mixed
    {
        return $this->extra;
    }
}
