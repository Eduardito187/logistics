<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Presentation\Http;

use Illuminate\Http\Request;
use Logistics\Access\Presentation\Http\Middleware\AuthenticateIntegrationToken;

/**
 * Lee del request los datos que el middleware `access.token` (contexto access) dejó al autenticar el token.
 *
 * Contrato con access (se replican las claves de atributo a propósito, para NO depender por composer del
 * package access y mantener el portal extraíble): cuando `access` se extraiga, un gateway seguirá
 * poblando estos mismos atributos/headers.
 *
 * @see AuthenticateIntegrationToken
 */
final class TokenContext
{
    /** Atributo donde `access.token` deja los permisos efectivos del token (list<string>). */
    public const PERMISSIONS_ATTRIBUTE = 'access.token.permissions';

    /** Atributo donde `access.token` deja el id del token autenticado. */
    public const TOKEN_ID_ATTRIBUTE = 'access.token.id';

    /**
     * Permisos efectivos del token autenticado. Lista vacía si la request no pasó por `access.token`.
     *
     * @return list<string>
     */
    public static function permissions(Request $request): array
    {
        $granted = $request->attributes->get(self::PERMISSIONS_ATTRIBUTE);
        if (!is_array($granted)) {
            return [];
        }

        $keys = [];
        foreach ($granted as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
