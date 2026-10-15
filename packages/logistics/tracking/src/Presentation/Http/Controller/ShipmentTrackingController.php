<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetShipmentTracking\GetShipmentTracking;
use Logistics\Tracking\Application\ReadModel\ShipmentTrackingView;
use Logistics\Tracking\Presentation\Http\ApiResponder;

/**
 * Seguimiento INTERNO por id de envío (puerta /api/v1/app/*).
 *
 * Lo consume personal autorizado de la app interna (operaciones, back office, call center interno),
 * por eso devuelve el ShipmentTrackingView completo con claves internas y token. 404 si el envío
 * todavía no tiene seguimiento abierto.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class ShipmentTrackingController
{
    use ApiResponder;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function show(string $shipmentId): JsonResponse
    {
        $view = $this->queryBus->ask(new GetShipmentTracking($shipmentId));

        if (!$view instanceof ShipmentTrackingView) {
            return $this->error('tracking_not_found', 'This shipment has no tracking yet.', 404);
        }

        return $this->data($view->toArray());
    }
}
