<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Command\EmitOrderLabels;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de emitir las etiquetas de un pedido DERIVANDO todo del propio pedido: el backend recorre los
 * envíos hermanos (multishipping), deriva origen/destino/líneas del envío y del plan publicado, y emite
 * solo lo AUTORIZADO y aún PENDING (idempotente/parcial). Es la emisión "por orden" que usa la app con el
 * body vacío, en contraste con EmitShipmentLabels (lote explícito con origen/destino/líneas a mano).
 *
 * `originOverride` es un escape hatch opcional: si el integrador conoce el CD de origen, lo impone; si no,
 * cada grupo usa el origen que resuelve el backend.
 */
final readonly class EmitOrderLabels implements Command
{
    public function __construct(
        public string $orderNumber,
        public ?string $originOverride = null,
    ) {}
}
