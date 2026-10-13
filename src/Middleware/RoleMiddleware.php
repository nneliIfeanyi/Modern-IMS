<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;

final class RoleMiddleware
{
    /** @var array<string> */
    private array $allowedRoles;

    /**
     * @param array<string> $allowedRoles
     */
    public function __construct(array $allowedRoles)
    {
        $this->allowedRoles = array_map(static fn(string $role): string => strtolower(trim($role)), $allowedRoles);
    }

    public function handle(Request $request, callable $next)
    {
        $role = strtolower((string) $request->attribute('auth_role', ''));
        if ($role === '') {
            return Response::error('Unauthenticated.', null, 401);
        }

        if (!in_array($role, $this->allowedRoles, true)) {
            return Response::error('Forbidden.', null, 403);
        }

        return $next($request);
    }
}
