<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Infrastructure\Identity;

use InvalidArgumentException;
use Logistics\SellerPortal\Application\Contract\SellerContextResolver;
use Logistics\SellerPortal\Domain\Exception\SellerPortalException;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Resuelve el seller del token escaneando sus permisos en busca de un scope `sellerportal.seller.<uuidhex>`.
 *
 * Es la implementación del contrato con `access` MIENTRAS el token no exponga un claim `seller_id` de
 * primera clase (ver README, "gancho para el claim real"). Reglas de seguridad:
 *  - Debe haber EXACTAMENTE un scope de seller. Cero ⇒ el token no identifica un seller (p. ej. un token
 *    `*` comodín). Más de uno (distintos) ⇒ ambiguo. En ambos casos se rechaza: nunca se deja que un
 *    token lea datos de más de un seller.
 *  - El `<uuidhex>` son los 32 dígitos hex del UUID del seller sin guiones (las claves de `access` solo
 *    admiten `[a-z0-9]`); se reinsertan los guiones y se valida como UUID real.
 */
final class PermissionScopedSellerContextResolver implements SellerContextResolver
{
    /** Prefijo del permiso que codifica el seller dueño del token. */
    public const SCOPE_PREFIX = 'sellerportal.seller.';

    public function resolve(array $permissionKeys): string
    {
        $sellers = [];
        foreach ($permissionKeys as $key) {
            $normalized = strtolower(trim($key));
            if (!str_starts_with($normalized, self::SCOPE_PREFIX)) {
                continue;
            }

            $hex = substr($normalized, strlen(self::SCOPE_PREFIX));
            $uuid = $this->hexToUuid($hex);
            if ($uuid !== null && !in_array($uuid, $sellers, true)) {
                $sellers[] = $uuid;
            }
        }

        if ($sellers === []) {
            throw SellerPortalException::because(
                'El token no está asociado a ningún seller (falta el scope sellerportal.seller.<uuid>).',
            );
        }

        if (count($sellers) > 1) {
            throw SellerPortalException::because('El token está asociado a más de un seller; es ambiguo.');
        }

        return $sellers[0];
    }

    /**
     * Convierte 32 dígitos hex (UUID sin guiones) en un UUID canónico validado, o null si no aplica.
     */
    private function hexToUuid(string $hex): ?string
    {
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            return null;
        }

        $canonical = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        try {
            return Uuid::fromString($canonical)->value;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Codifica un UUID de seller en su clave de permiso de scope (helper para emitir tokens / tests).
     */
    public static function scopeKeyFor(string $sellerId): string
    {
        return self::SCOPE_PREFIX.str_replace('-', '', strtolower($sellerId));
    }
}
