<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class PingController
{
    public function index(Request $request)
    {
        unset($request);

        $dbStatus = 'ok';
        try {
            Database::connection();
        } catch (\Throwable) {
            $dbStatus = 'error';
        }

        return Response::success([
            'service' => 'ims-api',
            'db' => $dbStatus,
            'time' => gmdate('c'),
        ], 'Ping successful.');
    }
}
