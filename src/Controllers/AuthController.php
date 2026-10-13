<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\UserModel;

final class AuthController
{
    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public function login(Request $request)
    {
        $email = strtolower(trim((string) $request->json('email', '')));
        $password = (string) $request->json('password', '');

        $errors = [];
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        }
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            return Response::error('Validation failed.', ['fields' => $errors], 422);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['is_active'] !== 1 || !password_verify($password, $user['password_hash'])) {
            return Response::error('Invalid email or password.', null, 401);
        }

        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'user_id' => (int) $user['id'],
            'role' => $user['role_name'],
        ];

        return Response::success([
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role_name'],
        ], 'Login successful.');
    }

    public function logout(Request $request)
    {
        unset($request);

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool) $params['secure'], (bool) $params['httponly']);
        }

        session_destroy();

        return Response::success(null, 'Logout successful.');
    }

    public function me(Request $request)
    {
        $user = $request->attribute('auth_user');
        if (!is_array($user)) {
            return Response::error('Unauthenticated.', null, 401);
        }

        return Response::success($user, 'Authenticated user loaded.');
    }
}
