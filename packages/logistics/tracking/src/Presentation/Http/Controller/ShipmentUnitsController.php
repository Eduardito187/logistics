<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Tracking\Application\Query\GetShipmentUnits\GetShipmentUnits;
use Logistics\Tracking\Application\ReadModel\UnitTrackingView;
use Logistics\Tracking\Presentation\Http\ApiResponder;

/**
 * Detalle PER-PRODUCTO del seguimiento, interno por id de envío (puerta /api/v1/app/*).
 *
 * Lo consume personal autorizado (operaciones, reconciliación de productos). Devuelve la lista de productos
 * del envío con su estado, última ubicación y último escaneo. Lista vacía si el envío todavía no tiene
 * productos proyectados.
 *
 * Presentation habla con Application vía el QueryBus; nunca toca Infrastructure ni el dominio.
 */
final class ShipmentUnitsController
{
    use ApiResponder;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    public function index(string $shipmentId): JsonResponse
    {
        /** @var list<UnitTrackingView> $units */
        $units = $this->queryBus->ask(new GetShipmentUnits($shipmentId));

        return $this->data([
            'shipment_id' => $shipmentId,
            'units' => array_map(static fn (UnitTrackingView $unit): array => $unit->toArray(), $units),
        ]);
    }
}
