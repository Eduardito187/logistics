<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Command\GeocodeShipmentAddress\GeocodeShipmentAddress;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta app: re-geocodifica (recalcula la confianza de) la dirección de un pedido.
 *
 * Primero confirma la existencia vía QueryBus para devolver 404 limpio; luego despacha el comando
 * GeocodeShipmentAddress por el CommandBus (transacción + outbox) y devuelve el pedido actualizado.
 */
final class GeocodeShipmentAddressController
{
    use JsonEnvelope;

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $shipment): JsonResponse
    {
        /** @var ShipmentView|null $before */
        $before = $this->queryBus->ask(new FindShipment($shipment));

        if ($before === null) {
            return $this->error("Shipment {$shipment} not found", 404);
        }

        $this->commandBus->dispatch(new GeocodeShipmentAddress($shipment));

        /** @var ShipmentView|null $after */
        $after = $this->queryBus->ask(new FindShipment($shipment));

        return $this->data(($after ?? $before)->toArray());
    }
}
