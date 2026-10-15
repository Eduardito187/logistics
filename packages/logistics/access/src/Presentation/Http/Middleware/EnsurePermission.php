<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Access\Application\Service\PermissionResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que el principal autenticado tenga un permiso concreto (p. ej. `access.permission:shipments.read`).
 *
 * Lee los permisos efectivos que {@see AuthenticateIntegrationToken} dejó en la request y los contrasta
 * con el exigido vía {@see PermissionResolver::grants()} (que respeta el comodín "*"). Si no hay permisos
 * resueltos, la petición no se autenticó por este carril → 401; si hay pero no alcanzan → 403.
 */
final class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $granted = $request->attributes->get(AuthenticateIntegrationToken::PERMISSIONS_ATTRIBUTE);

        if (!is_array($granted)) {
            return new JsonResponse([
                'error' => ['code' => 'unauthenticated', 'message' => 'No hay un principal autenticado con permisos.'],
            ], 401);
        }

        /** @var list<string> $granted */
        if (!PermissionResolver::grants($granted, $permission)) {
            return new JsonResponse([
                'error' => [
                    'code' => 'forbidden',
                    'message' => 'El token no tiene el permiso requerido.',
                    'details' => ['required' => $permission],
                ],
            ], 403);
        }

        return $next($request);
    }
}
