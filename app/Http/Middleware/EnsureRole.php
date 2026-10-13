<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el usuario autenticado tenga al menos uno de los roles indicados.
 *
 * Uso en rutas: `->middleware('role:supervisor,admin')`.
 */
final class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        $required = [];
        foreach ($roles as $role) {
            $case = Role::tryFrom($role);
            if ($case !== null) {
                $required[] = $case;
            }
        }

        if ($required === [] || !$user->hasAnyRole(...$required)) {
            return new JsonResponse(['message' => 'This action is not allowed for your role.'], 403);
        }

        return $next($request);
    }
}
