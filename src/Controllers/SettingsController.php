<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\UserModel;

final class SettingsController
{
    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public function profile(Request $request)
    {
        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);
        if ($userId <= 0) {
            return Response::error('Unauthenticated.', null, 401);
        }

        $user = $this->users->findActiveById($userId);
        if ($user === null) {
            return Response::error('User not found.', null, 404);
        }

        return Response::success([
            'id' => (int) $user['id'],
            'full_name' => (string) $user['full_name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role_name'],
        ], 'Profile loaded.');
    }

    public function updateProfile(Request $request)
    {
        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);
        if ($userId <= 0) {
            return Response::error('Unauthenticated.', null, 401);
        }

        $fullName = trim((string) $request->json('full_name', ''));
        $email = strtolower(trim((string) $request->json('email', '')));

        $errors = [];
        if ($fullName === '') {
            $errors['full_name'] = 'Full name is required.';
        } elseif (mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Full name must not exceed 120 characters.';
        }

        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (mb_strlen($email) > 190) {
            $errors['email'] = 'Email must not exceed 190 characters.';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email must be a valid email address.';
        } elseif ($this->users->emailExists($email, $userId)) {
            $errors['email'] = 'Email is already used by another account.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $this->users->updateProfile($userId, [
            'full_name' => $fullName,
            'email' => $email,
        ]);

        $user = $this->users->findActiveById($userId);
        if ($user === null) {
            return Response::error('User not found.', null, 404);
        }

        return Response::success([
            'id' => (int) $user['id'],
            'full_name' => (string) $user['full_name'],
            'email' => (string) $user['email'],
            'role' => (string) $user['role_name'],
        ], 'Profile updated.');
    }

    public function updatePassword(Request $request)
    {
        $authUser = $request->attribute('auth_user');
        $userId = (int) ($authUser['id'] ?? 0);
        if ($userId <= 0) {
            return Response::error('Unauthenticated.', null, 401);
        }

        $currentPassword = (string) $request->json('current_password', '');
        $newPassword = (string) $request->json('new_password', '');
        $confirmPassword = (string) $request->json('confirm_password', '');

        $errors = [];
        if ($currentPassword === '') {
            $errors['current_password'] = 'Current password is required.';
        }
        if ($newPassword === '') {
            $errors['new_password'] = 'New password is required.';
        } elseif (strlen($newPassword) < 8) {
            $errors['new_password'] = 'New password must be at least 8 characters.';
        }
        if ($confirmPassword === '') {
            $errors['confirm_password'] = 'Please confirm your new password.';
        } elseif ($confirmPassword !== $newPassword) {
            $errors['confirm_password'] = 'Confirmation does not match new password.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        if (!$this->users->verifyPassword($userId, $currentPassword)) {
            return Response::error('Current password is incorrect.', ['fields' => ['current_password' => 'Current password is incorrect.']], 422);
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->users->updatePasswordHash($userId, $hash);

        return Response::success(null, 'Password updated.');
    }
}
