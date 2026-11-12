<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Puerto: horas de vida del ENLACE PÚBLICO de seguimiento una vez entregado el pedido (clave de
 * Configuración `tracking.public_link_ttl_hours`, valor `{"hours": N}`).
 *
 * La implementación debe devolver SIEMPRE un valor usable aunque la clave no esté publicada (docker nuevo
 * sin seeders): el default es {@see self::DEFAULT_HOURS}. Un valor ≤ 0 desactiva el vencimiento.
 */
interface PublicLinkTtl
{
    /** Default cuando la clave no está configurada: 72 h (3 días) desde la entrega. */
    public const int DEFAULT_HOURS = 72;

    public function hours(): int;
}
