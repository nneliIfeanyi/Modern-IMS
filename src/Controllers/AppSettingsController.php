<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class AppSettingsController
{
    private \App\Models\AppSettingModel $settings;

    public function __construct()
    {
        $this->settings = new \App\Models\AppSettingModel();
    }

    public function show(Request $request)
    {
        unset($request);

        return Response::success($this->settings->getAll(), 'App settings loaded.');
    }

    public function update(Request $request)
    {
        $siteName = trim((string) $request->json('site_name', ''));
        $themeColor = trim((string) $request->json('theme_color', ''));

        $errors = [];
        if ($siteName === '') {
            $errors['site_name'] = 'Site name is required.';
        } elseif (mb_strlen($siteName) > 100) {
            $errors['site_name'] = 'Site name must not exceed 100 characters.';
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $themeColor)) {
            $errors['theme_color'] = 'Theme color must be a valid hex color like #0d6efd.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $authUser = $request->attribute('auth_user');
        $updatedBy = (int) ($authUser['id'] ?? 0);

        $this->settings->upsertMany([
            'site_name' => $siteName,
            'theme_color' => strtolower($themeColor),
        ], $updatedBy > 0 ? $updatedBy : null);

        return Response::success($this->settings->getAll(), 'App settings updated.');
    }
}
