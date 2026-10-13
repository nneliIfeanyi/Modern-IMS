<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function success(mixed $data = null, string $message = 'OK', int $statusCode = 200): HttpResponse
    {
        return new HttpResponse($statusCode, [
            'status' => 'ok',
            'data' => $data,
            'message' => $message,
        ]);
    }

    public static function error(string $message = 'Error', mixed $data = null, int $statusCode = 400): HttpResponse
    {
        return new HttpResponse($statusCode, [
            'status' => 'error',
            'data' => $data,
            'message' => $message,
        ]);
    }

    public static function raw(string $body, int $statusCode = 200, array $headers = []): HttpResponse
    {
        return new HttpResponse($statusCode, $body, $headers, true);
    }

    public static function send(HttpResponse $response): void
    {
        http_response_code($response->statusCode);

        if ($response->raw) {
            foreach ($response->headers as $name => $value) {
                header($name . ': ' . $value);
            }

            echo (string) $response->body;
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response->body, JSON_UNESCAPED_SLASHES);
    }
}
