<?php

declare(strict_types=1);

return [
    'app' => [
        'name' => $_ENV['APP_NAME'] ?? '',
        'env' => $_ENV['APP_ENV'] ?? '',
        'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
        'url' => $_ENV['APP_URL'] ?? '',
    ],
    'db' => [
        'host' => $_ENV['DB_HOST'] ?? '',
        'port' => (int) ($_ENV['DB_PORT'] ?? ''),
        'database' => $_ENV['DB_DATABASE'] ?? '',
        'username' => $_ENV['DB_USERNAME'] ?? '',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset' => 'utf8mb4',
    ],
];
