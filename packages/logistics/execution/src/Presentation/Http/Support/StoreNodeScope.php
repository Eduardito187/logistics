<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Support;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alcance por tienda del POD (server-side). El POD es por shipmentId (no manda nodeId en el body), así que
 * el middleware enforce.store.node no aplica directo: acá se replica su garantía para el rol Tienda leyendo
 * el nodo destino del envío (lectura directa a `shipments`, enriquecimiento cross-context como el resto del
 * admin). Si el envío no tiene nodo destino, la tienda NO puede probar que es suyo → se niega (política
 * segura). Los demás roles operan cross-nodo y pasan directo.
 *
 * Lo usan el POD individual, el cierre por envío (/complete) y el lote de escaneos.
 */
final class StoreNodeScope
{
    /** Motivo del rechazo (inglés: viaja tal cual en el problem detail). */
    public const string DENIED_DETAIL = 'This action is restricted to your assigned store node.';

    /**
     * ¿El usuario puede registrar el POD de este envío?
     */
    public static function allows(mixed $user, string $shipmentId): bool
    {
        // Solo el rol Tienda queda acotado a su nodo; el resto opera cross-nodo sin restricción.
        if (!$user instanceof User || !$user->hasRole(Role::Tienda)) {
            return true;
        }

        $assignedNode = $user->node_id;
        $destinationNodeId = DB::table('shipments')->where('id', $shipmentId)->value('destination_node_id');

        return is_string($assignedNode) && $assignedNode !== ''
            && is_string($destinationNodeId) && $destinationNodeId !== ''
            && $destinationNodeId === $assignedNode;
    }
}
