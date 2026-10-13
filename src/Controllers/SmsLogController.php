<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SmsNotificationService;

final class SmsLogController
{
    private SmsNotificationService $sms;

    public function __construct()
    {
        $this->sms = new SmsNotificationService();
    }

    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 25);
        return Response::success($this->sms->recent($limit), 'SMS logs loaded.');
    }
}
