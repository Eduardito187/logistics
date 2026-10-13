<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta app: detalle de un pedido recibido por su id.
 *
 * Lee vía QueryBus (FindShipment → ?ShipmentView). Devuelve 404 si no existe.
 */
final class ShowShipmentController
{
    use JsonEnvelope;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function __invoke(string $shipment): JsonResponse
    {
        /** @var ShipmentView|null $view */
        $view = $this->queryBus->ask(new FindShipment($shipment));

        if ($view === null) {
            return $this->error("Shipment {$shipment} not found", 404);
        }

        return $this->data($view->toArray());
    }
}
