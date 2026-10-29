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

    public function roles(Request $request)
    {
        unset($request);

        return Response::success($this->users->allRoles(), 'Roles loaded.');
    }

    public function users(Request $request)
    {
        unset($request);

        return Response::success($this->users->allForAdmin(), 'Users loaded.');
    }

    public function createUser(Request $request)
    {
        [$payload, $errors] = $this->validatedUserPayload($request, true);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }
        if ($this->users->emailExists($payload['email'])) {
            return Response::error('Validation failed.', ['fields' => ['email' => 'Email is already used by another account.']], 422);
        }

        $id = $this->users->createForAdmin(
            $payload['full_name'],
            $payload['email'],
            $payload['role_id'],
            password_hash($payload['password'], PASSWORD_DEFAULT)
        );

        return Response::success($this->users->findForAdmin($id), 'User created.', 201);
    }

    public function updateUser(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid user id.', null, 422);
        }

        $user = $this->users->findForAdmin($id);
        if ($user === null) {
            return Response::error('User not found.', null, 404);
        }

        [$payload, $errors] = $this->validatedUserPayload($request, false);
        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $authUser = $request->attribute('auth_user');
        if ($id === (int) ($authUser['id'] ?? 0)) {
            if (!$payload['is_active']) {
                return Response::error('You cannot deactivate your own account.', null, 409);
            }
            if ($payload['role_name'] !== 'admin') {
                return Response::error('You cannot change your own role.', null, 409);
            }
        }

        if ($this->users->emailExists($payload['email'], $id)) {
            return Response::error('Validation failed.', ['fields' => ['email' => 'Email is already used by another account.']], 422);
        }

        $isActiveAdmin = (int) $user['is_active'] === 1 && $user['role_name'] === 'admin';
        $removesAdminAccess = !$payload['is_active'] || $payload['role_name'] !== 'admin';
        if ($isActiveAdmin && $removesAdminAccess && $this->users->activeAdminCount() <= 1) {
            return Response::error('At least one active admin account must remain.', null, 409);
        }

        $passwordHash = $payload['password'] === '' ? null : password_hash($payload['password'], PASSWORD_DEFAULT);
        $this->users->updateForAdmin(
            $id,
            $payload['full_name'],
            $payload['email'],
            $payload['role_id'],
            $payload['is_active'],
            $passwordHash
        );

        return Response::success($this->users->findForAdmin($id), 'User updated.');
    }

    public function deactivateUser(Request $request)
    {
        $id = (int) $request->attribute('id', 0);
        if ($id <= 0) {
            return Response::error('Invalid user id.', null, 422);
        }

        $authUser = $request->attribute('auth_user');
        $currentUserId = (int) ($authUser['id'] ?? 0);
        if ($id === $currentUserId) {
            return Response::error('You cannot deactivate your own account.', null, 409);
        }

        $user = $this->users->findForAdmin($id);
        if ($user === null) {
            return Response::error('User not found.', null, 404);
        }
        if ((int) $user['is_active'] === 1 && $user['role_name'] === 'admin' && $this->users->activeAdminCount() <= 1) {
            return Response::error('At least one active admin account must remain.', null, 409);
        }

        $this->users->deactivateForAdmin($id);
        return Response::success(null, 'User deactivated.');
    }

    private function validatedUserPayload(Request $request, bool $creating): array
    {
        $fullName = trim((string) $request->json('full_name', ''));
        $email = strtolower(trim((string) $request->json('email', '')));
        $roleId = filter_var($request->json('role_id'), FILTER_VALIDATE_INT);
        $password = (string) $request->json('password', '');
        $activeValue = $request->json('is_active', true);
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
        }

        if ($roleId === false || $roleId <= 0 || !$this->users->roleExists((int) $roleId)) {
            $errors['role_id'] = 'Select a valid role.';
        }

        if ($creating && $password === '') {
            $errors['password'] = 'Password is required for new users.';
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (!is_bool($activeValue) && !in_array($activeValue, [0, 1, '0', '1'], true)) {
            $errors['is_active'] = 'User status must be active or inactive.';
        }

        $role = $roleId !== false && $roleId > 0 ? $this->users->findRole((int) $roleId) : null;

        return [[
            'full_name' => $fullName,
            'email' => $email,
            'role_id' => (int) ($roleId ?: 0),
            'role_name' => (string) ($role['name'] ?? ''),
            'password' => $password,
            'is_active' => $activeValue === true || $activeValue === 1 || $activeValue === '1',
        ], $errors];
    }
}
