<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Http\Support;

use Illuminate\Http\Request;

/**
 * Resuelve el id del usuario autenticado de la puerta app como string. Es la ÚNICA fuente del dueño del
 * estado de lectura: el cliente nunca puede indicar a nombre de quién marca (cualquier `user_id` que
 * viaje en el body o la query se ignora).
 */
final class AuthenticatedUser
{
    public static function id(Request $request): ?string
    {
        $identifier = $request->user()?->getAuthIdentifier();

        if (is_int($identifier) || (is_string($identifier) && trim($identifier) !== '')) {
            return (string) $identifier;
        }

        return null;
    }
}
