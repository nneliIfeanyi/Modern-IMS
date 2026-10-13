<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Models\UserModel;

final class AuthMiddleware
{
    private UserModel $users;

    public function __construct()
    {
        $this->users = new UserModel();
    }

    public function handle(Request $request, callable $next)
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth) || !isset($auth['user_id'])) {
            return Response::error('Unauthenticated.', null, 401);
        }

        $user = $this->users->findActiveById((int) $auth['user_id']);
        if ($user === null) {
            return Response::error('Unauthenticated.', null, 401);
        }

        $request->setAttribute('auth_user', [
            'id' => (int) $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role_name'],
        ]);
        $request->setAttribute('auth_role', $user['role_name']);

        return $next($request);
    }
}
