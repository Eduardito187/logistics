<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AssembleDailyRoute;

/**
 * Parada candidata de la ruta del chofer, tal como la recibe el caso de uso: el envío, su posición en la
 * secuencia de reparto (la que fijó el plan) y la foto legible del destino (pedido, cliente, dirección,
 * ETA y coordenadas). Todo salvo el envío y la secuencia es opcional. Lo aporta quien conoce el plan
 * publicado; ejecución no consulta planificación.
 */
final readonly class RouteStopInput
{
    public function __construct(
        public string $shipmentId,
        public int $sequence,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
        public ?string $etaAt = null,
        public ?float $lat = null,
        public ?float $lng = null,
    ) {}
}
