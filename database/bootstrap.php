<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (is_file($autoload)) {
    require $autoload;
} else {
    require $root . '/src/Core/Autoloader.php';
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    $envFile = $root . '/.env.example';
}

Env::load($envFile);
$config = require $root . '/config/config.php';
Database::init($config['db']);

return [
    'root' => $root,
    'db' => Database::connection(),
];
