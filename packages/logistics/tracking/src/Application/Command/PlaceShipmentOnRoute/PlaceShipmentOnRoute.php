<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\PlaceShipmentOnRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * Ubica un envío en la ruta publicada del camión: fija su ruta y su posición en la secuencia de
 * reparto (1-based). De la posición el seguimiento deriva cuántas paradas faltan antes de la del
 * cliente.
 *
 * Es el PUENTE que, al integrar los contextos, consume la ruta publicada por planning: el relay se
 * suscribe a planning.plan_published, lee la secuencia de paradas del plan y manda un PlaceShipmentOnRoute
 * por cada envío (routeId = id del plan, routePosition = su StopSequence). Acá NO llega el agregado de
 * planning, solo datos primitivos: tracking no se acopla a otro contexto.
 */
final readonly class PlaceShipmentOnRoute implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $routeId,
        public int $routePosition,
    ) {}
}
