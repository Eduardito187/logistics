<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Support;

use App\Enums\Role;
use App\Models\User;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Arma el actor de alcance desde el usuario autenticado de la puerta app: sus roles y sus vínculos
 * (`city`, `node_id`, `driver_id`, `carrier_id`). El alcance sale SIEMPRE del usuario, nunca de un
 * parámetro que mande la app.
 *
 * `users.city` es el código de departamento del usuario (lo mantiene el contexto de identidad). Se lee de
 * forma tolerante: si el usuario no tiene ciudad, no queda restringido por ciudad.
 */
final class ScopeActorResolver
{
    public static function fromUser(mixed $user): ShipmentScopeActor
    {
        // Sin usuario de la app no hay a quién acotar (la autenticación es otra capa de la puerta).
        if (!$user instanceof User) {
            return ShipmentScopeActor::unrestricted();
        }

        return ShipmentScopeActor::fromIdentity(
            roles: array_map(static fn (Role $role): string => $role->value, $user->roles()),
            // Lectura tolerante: si la columna todavía no existe en este entorno, es «sin ciudad».
            city: self::text($user->getAttributes()['city'] ?? null),
            nodeId: self::text($user->node_id),
            driverId: self::text($user->driver_id),
            carrierId: self::text($user->carrier_id),
            strictUnlinked: (bool) config('logistics-shipments.scope.strict_unlinked', false),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
