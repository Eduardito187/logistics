<?php

declare(strict_types=1);

namespace Logistics\SellerPortal\Application\Contract;

use Logistics\SellerPortal\Domain\Exception\SellerPortalException;

/**
 * Resuelve el `sellerId` del principal autenticado a partir de los permisos efectivos del token.
 *
 * Es el único punto de acoplamiento con el contrato de `access`: hoy el seller viaja codificado en un
 * permiso `sellerportal.seller.<uuidhex>` (ver README). El día que `access` exponga un claim de primera
 * clase, se cambia solo la implementación; la interfaz, los controllers y las rutas no se tocan.
 */
interface SellerContextResolver
{
    /**
     * Devuelve el UUID del seller dueño del token.
     *
     * @param list<string> $permissionKeys permisos efectivos que dejó el middleware `access.token`
     *
     * @throws SellerPortalException si el token no identifica a exactamente un seller
     */
    public function resolve(array $permissionKeys): string;
}
