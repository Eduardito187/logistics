<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe a un usuario del rol Tienda a operar únicamente sobre SU nodo de tienda.
 *
 * Autorización server-side (no se confía en la app): en los endpoints de recepción en nodo / escaneo,
 * el nodo objetivo de la petición (body o route) debe coincidir con `users.node_id` del usuario tienda.
 *
 * Uso en rutas, indicando DE DÓNDE sale el id del nodo objetivo:
 *   ->middleware('enforce.store.node:body.destination_node_id')
 *   ->middleware('enforce.store.node:body.node_id')
 *   ->middleware('enforce.store.node:route.nodeId')
 *
 * Los demás roles (chofer, supervisor, admin, etc.) operan cross-nodo y no se restringen: pasan directo.
 */
final class EnsureStoreNodeScope
{
    /**
     * @param string $source Origen del id del nodo objetivo con la forma `body.<campo>` o `route.<param>`.
     */
    public function handle(Request $request, Closure $next, string $source): Response
    {
        $user = $request->user();

        // Solo el rol Tienda queda acotado a su nodo; el resto opera cross-nodo sin restricción.
        if (!$user instanceof User || !$user->hasRole(Role::Tienda)) {
            return $next($request);
        }

        $assignedNode = $user->node_id;

        // Usuario tienda sin nodo asignado: no puede operar recepción en ningún nodo.
        if ($assignedNode === null || $assignedNode === '') {
            return $this->forbidden($request);
        }

        $targetNode = $this->resolveTargetNode($request, $source);

        // El nodo objetivo debe ser exactamente el nodo asignado al usuario tienda.
        if ($targetNode === null || $targetNode !== $assignedNode) {
            return $this->forbidden($request);
        }

        return $next($request);
    }

    /**
     * Extrae el id del nodo objetivo del body o de la route, según el parámetro del middleware.
     */
    private function resolveTargetNode(Request $request, string $source): ?string
    {
        [$where, $key] = array_pad(explode('.', $source, 2), 2, '');

        $value = match ($where) {
            'body' => $request->input($key),
            'route' => $request->route($key),
            default => null,
        };

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function forbidden(Request $request): Response
    {
        return ProblemDetails::fromCode(
            ErrorCode::Forbidden,
            'This action is restricted to your assigned store node.',
        )->toResponse($request);
    }
}
