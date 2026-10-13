<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ExpiryNotificationService;

final class DashboardController
{
    private \App\Models\DashboardModel $dashboard;
    private ExpiryNotificationService $expiryNotifications;

    public function __construct()
    {
        $this->dashboard = new \App\Models\DashboardModel();
        $this->expiryNotifications = new ExpiryNotificationService();
    }

    public function summary(Request $request)
    {
        unset($request);

        try {
            $this->expiryNotifications->check();
        } catch (\Throwable $exception) {
            error_log('Expiry notification check failed: ' . $exception->getMessage());
        }

        return Response::success($this->dashboard->summary(), 'Dashboard summary loaded.');
    }

    public function movementsTrend(Request $request)
    {
        $days = (int) $request->query('days', 30);
        if ($days <= 0 || $days > 365) {
            return Response::error('days must be between 1 and 365.', null, 422);
        }

        return Response::success($this->dashboard->movementsTrend($days), 'Dashboard movement trend loaded.');
    }

    public function lowStock(Request $request)
    {
        unset($request);

        return Response::success($this->dashboard->lowStockItems(), 'Low stock items loaded.');
    }
}
