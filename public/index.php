<?php

declare(strict_types=1);

use App\Controllers\PingController;
use App\Controllers\AuthController;
use App\Controllers\AppSettingsController;
use App\Controllers\CategoryController;
use App\Controllers\CustomerController;
use App\Controllers\DashboardController;
use App\Controllers\ProductController;
use App\Controllers\ReportsController;
use App\Controllers\SettingsController;
use App\Controllers\SmsLogController;
use App\Controllers\StockMovementController;
use App\Controllers\SalesOrderController;
use App\Controllers\SupplierController;
use App\Controllers\PurchaseOrderController;
use App\Controllers\WarehouseController;
use App\Controllers\WhatsAppWebhookController;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsMiddleware;

error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__);

set_exception_handler(static function (Throwable $exception): void {
    $logDir = $GLOBALS['ims_log_dir'] ?? ($GLOBALS['ims_root'] . '/storage/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    $logFile = rtrim((string) $logDir, '/\\') . '/app.log';
    $line = sprintf(
        "[%s] %s in %s:%d\n%s\n\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    @file_put_contents($logFile, $line, FILE_APPEND);

    Response::send(Response::error('Internal server error.', [
        'error' => 'An unexpected error occurred.',
    ], 500));
});

$composerAutoload = $root . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    require $root . '/src/Core/Autoloader.php';
}

$envFile = $root . '/.env';
if (!is_file($envFile)) {
    $envFile = $root . '/.env.example';
}

Env::load($envFile);
$config = require $root . '/config/config.php';

$GLOBALS['ims_root'] = $root;
$GLOBALS['ims_log_dir'] = $root . '/storage/logs';

Database::init($config['db']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('ims_session');
    session_start();
}

$request = Request::capture();
$router = new Router();

$router->add('GET', '/api/ping', [PingController::class, 'index'], [CorsMiddleware::class]);
$router->add('GET', '/api/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
$router->add('POST', '/api/whatsapp/webhook', [WhatsAppWebhookController::class, 'receive']);
$router->add('POST', '/api/auth/login', [AuthController::class, 'login'], [CorsMiddleware::class]);
$router->add('POST', '/api/auth/logout', [AuthController::class, 'logout'], [CorsMiddleware::class, AuthMiddleware::class]);
$router->add('GET', '/api/auth/me', [AuthController::class, 'me'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/admin/ping', static fn(Request $req) => Response::success([
    'user' => $req->attribute('auth_user'),
], 'Admin route check successful.'), [CorsMiddleware::class, 'auth', 'role:admin']);

$router->add('GET', '/api/categories', [CategoryController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/categories', [CategoryController::class, 'create'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/categories/{id}', [CategoryController::class, 'update'], [CorsMiddleware::class, 'auth']);
$router->add('DELETE', '/api/categories/{id}', [CategoryController::class, 'delete'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/sms-logs', [SmsLogController::class, 'index'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/products', [ProductController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/products', [ProductController::class, 'create'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/products/{id}', [ProductController::class, 'show'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/products/{id}', [ProductController::class, 'update'], [CorsMiddleware::class, 'auth']);
$router->add('DELETE', '/api/products/{id}', [ProductController::class, 'delete'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/warehouses', [WarehouseController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/warehouses', [WarehouseController::class, 'create'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/warehouses/{id}', [WarehouseController::class, 'update'], [CorsMiddleware::class, 'auth']);
$router->add('DELETE', '/api/warehouses/{id}', [WarehouseController::class, 'delete'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/suppliers', [SupplierController::class, 'index'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('POST', '/api/suppliers', [SupplierController::class, 'create'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('PUT', '/api/suppliers/{id}', [SupplierController::class, 'update'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('DELETE', '/api/suppliers/{id}', [SupplierController::class, 'delete'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);

$router->add('GET', '/api/stock-movements', [StockMovementController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/stock-movements', [StockMovementController::class, 'create'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/dashboard/summary', [DashboardController::class, 'summary'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/dashboard/movements-trend', [DashboardController::class, 'movementsTrend'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/dashboard/low-stock', [DashboardController::class, 'lowStock'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/purchase-orders', [PurchaseOrderController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/purchase-orders', [PurchaseOrderController::class, 'create'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/purchase-orders/{id}', [PurchaseOrderController::class, 'show'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/purchase-orders/{id}', [PurchaseOrderController::class, 'update'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/purchase-orders/{id}/send', [PurchaseOrderController::class, 'send'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/sales-orders', [\App\Controllers\SalesOrderController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/sales-orders', [\App\Controllers\SalesOrderController::class, 'create'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/sales-orders/{id}', [\App\Controllers\SalesOrderController::class, 'show'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/sales-orders/{id}', [\App\Controllers\SalesOrderController::class, 'update'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/sales-orders/{id}/confirm', [\App\Controllers\SalesOrderController::class, 'confirm'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/sales-orders/{id}/fulfill', [\App\Controllers\SalesOrderController::class, 'fulfill'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/reports/stock-valuation', [ReportsController::class, 'stockValuation'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('GET', '/api/reports/movement-history', [ReportsController::class, 'movementHistory'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('GET', '/api/reports/low-stock', [ReportsController::class, 'lowStock'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);

$router->add('GET', '/api/settings/profile', [SettingsController::class, 'profile'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('PUT', '/api/settings/profile', [SettingsController::class, 'updateProfile'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('PUT', '/api/settings/password', [SettingsController::class, 'updatePassword'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('GET', '/api/settings/roles', [SettingsController::class, 'roles'], [CorsMiddleware::class, 'auth', 'role:admin']);
$router->add('GET', '/api/settings/users', [SettingsController::class, 'users'], [CorsMiddleware::class, 'auth', 'role:admin']);
$router->add('POST', '/api/settings/users', [SettingsController::class, 'createUser'], [CorsMiddleware::class, 'auth', 'role:admin']);
$router->add('PUT', '/api/settings/users/{id}', [SettingsController::class, 'updateUser'], [CorsMiddleware::class, 'auth', 'role:admin']);
$router->add('DELETE', '/api/settings/users/{id}', [SettingsController::class, 'deactivateUser'], [CorsMiddleware::class, 'auth', 'role:admin']);
$router->add('GET', '/api/settings/app', [AppSettingsController::class, 'show'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/settings/app', [AppSettingsController::class, 'update'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);

$router->add('GET', '/api/customers', [CustomerController::class, 'index'], [CorsMiddleware::class, 'auth']);
$router->add('POST', '/api/customers', [CustomerController::class, 'create'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('GET', '/api/customers/{id}', [CustomerController::class, 'show'], [CorsMiddleware::class, 'auth']);
$router->add('PUT', '/api/customers/{id}', [CustomerController::class, 'update'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('DELETE', '/api/customers/{id}', [CustomerController::class, 'delete'], [CorsMiddleware::class, 'auth', 'role:admin,manager']);
$router->add('POST', '/api/customers/{id}/payments', [CustomerController::class, 'payment'], [CorsMiddleware::class, 'auth']);
$router->add('GET', '/api/customers/{id}/statement', [CustomerController::class, 'statement'], [CorsMiddleware::class, 'auth']);

$router->add('GET', '/api/sales-orders/{id}/receipt', [\App\Controllers\SalesOrderController::class, 'receipt'], [CorsMiddleware::class, 'auth']);

$response = $router->dispatch($request);
Response::send($response);
