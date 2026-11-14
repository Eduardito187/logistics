<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\SyncRouteStops;

use Logistics\Shared\Application\Command\Command;

/**
 * Alinea el seguimiento de los envíos de una ruta con la ruta REAL del camión: posición de cada envío y
 * cuántas paradas pendientes tiene por delante. Se dispara cuando la ruta cambia (se arma, se suma o se
 * quita una parada). `detachedShipmentId` es el envío que acaba de salir de la ruta, si lo hay.
 *
 * `silent` es para la reconciliación: corrige la proyección SIN grabar eventos (no reemite avisos al
 * cliente por algo que ya pasó).
 */
final readonly class SyncRouteStops implements Command
{
    public function __construct(
        public string $routeId,
        public ?string $detachedShipmentId = null,
        public bool $silent = false,
    ) {}
}
