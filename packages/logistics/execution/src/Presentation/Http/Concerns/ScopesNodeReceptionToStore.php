<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Concerns;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alcance por tienda (server-side) para los endpoints de recepción en nodo cuyo nodo objetivo NO viene en
 * el cuerpo o la ruta (se deriva en el servidor: abrir con solo el envío, listar sin `node_id`), donde el
 * middleware `enforce.store.node` no puede decidir. Replica exactamente su garantía y su 403 RFC 7807:
 * el usuario Tienda solo opera sobre SU nodo (`users.node_id`); los demás roles operan cross-nodo.
 */
trait ScopesNodeReceptionToStore
{
    /**
     * Nodo al que queda acotado el usuario: null si NO es Tienda (sin restricción); '' si es Tienda sin
     * nodo asignado (no puede operar en ninguno); el id del nodo si es Tienda con nodo.
     */
    protected function storeScopeNode(Request $request): ?string
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->hasRole(Role::Tienda)) {
            return null;
        }

        return is_string($user->node_id) ? $user->node_id : '';
    }

    /**
     * Nodo asignado al usuario autenticado (cualquier rol), para usarlo como destino por defecto cuando
     * el envío todavía no tiene `destination_node_id`. Null si no tiene nodo.
     */
    protected function userNodeId(Request $request): ?string
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return null;
        }

        return is_string($user->node_id) && $user->node_id !== '' ? $user->node_id : null;
    }

    protected function storeScopeForbidden(Request $request): JsonResponse
    {
        return ProblemDetails::fromCode(
            ErrorCode::Forbidden,
            'This action is restricted to your assigned store node.',
        )->toResponse($request);
    }
}
