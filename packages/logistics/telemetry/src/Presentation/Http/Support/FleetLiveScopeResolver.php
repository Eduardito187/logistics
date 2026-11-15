<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Support;

use App\Enums\Role;
use App\Models\User;
use Logistics\Telemetry\Application\Scope\FleetLiveScope;

/**
 * Arma el alcance de la flota en vivo desde el usuario autenticado de la puerta app: sus roles y su ciudad
 * (`users.city`, código de departamento). El alcance sale SIEMPRE del usuario, nunca de un parámetro que
 * mande la app, y es la misma regla con la que el host autoriza los canales `fleet.all` / `fleet.city.*`.
 *
 * El interruptor del modo estricto es el mismo de toda la plataforma
 * (`logistics-shipments.scope.strict_without_city`): se lee como configuración, sin depender del package.
 */
final class FleetLiveScopeResolver
{
    public static function fromUser(mixed $user): FleetLiveScope
    {
        // Sin usuario de la app no hay a quién acotar (la autenticación es otra capa de la puerta).
        if (!$user instanceof User) {
            return FleetLiveScope::unrestricted();
        }

        // Lectura tolerante: si la columna todavía no existe en este entorno, es «sin ciudad».
        $city = $user->getAttributes()['city'] ?? null;

        return FleetLiveScope::fromIdentity(
            roles: array_map(static fn (Role $role): string => $role->value, $user->roles()),
            city: is_string($city) && trim($city) !== '' ? trim($city) : null,
            strictWithoutCity: (bool) config('logistics-shipments.scope.strict_without_city', false),
        );
    }
}
