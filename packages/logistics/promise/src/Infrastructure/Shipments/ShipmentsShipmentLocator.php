<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Shipments;

use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * Implementación del puerto de ubicación de envío apoyada en el contexto de envíos.
 *
 * Lee el envío con la consulta pública de shipments y devuelve la coordenada ya geocodificada de su
 * dirección de entrega. El cruce es de solo lectura, por el QueryBus y por primitivos; este adaptador no
 * importa infraestructura ni dominio de shipments, solo su mensaje de consulta y su modelo de lectura.
 *
 * Tolerante: si el envío no existe o no tiene coordenada (dirección sin geocodificar), devuelve null, y
 * el caso de uso decide (la oferta responde vacío; la reserva exige municipio).
 */
final readonly class ShipmentsShipmentLocator implements ShipmentLocator
{
    public function __construct(private QueryBus $queryBus) {}

    public function locate(string $shipmentId): ?array
    {
        /** @var ShipmentView|null $view */
        $view = $this->queryBus->ask(new FindShipment($shipmentId));

        if ($view === null || $view->lat === null || $view->lng === null) {
            return null;
        }

        return ['latitude' => $view->lat, 'longitude' => $view->lng];
    }
}
