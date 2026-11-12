<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListDeliveriesByVehicle;

use Logistics\Execution\Application\Contract\DeliveryReferenceReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Application\ReadModel\VehicleDeliveriesPage;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Hoja de ruta del vehículo, enriquecida EN LOTE (sin N+1): referencia legible (pedido/cliente/dirección)
 * y bultos de cada envío (units_total / unit_ids). Pagina en la base si la consulta trae límite.
 *
 * @implements QueryHandler<ListDeliveriesByVehicle, VehicleDeliveriesPage>
 */
final class ListDeliveriesByVehicleHandler implements QueryHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly DeliveryReferenceReader $references,
        private readonly ?ShipmentUnitIdsReader $units = null,
    ) {}

    public function handle(Query $query): VehicleDeliveriesPage
    {
        if (!$query instanceof ListDeliveriesByVehicle) {
            throw ExecutionException::because('ListDeliveriesByVehicleHandler solo maneja ListDeliveriesByVehicle');
        }

        $deliveries = [];
        $shipmentIds = [];
        foreach ($this->deliveries->findByVehicleId($query->vehicleId, $query->limit, $query->offset) as $delivery) {
            $deliveries[] = $delivery;
            $shipmentIds[] = $delivery->shipmentId();
        }

        // Referencia legible y bultos de TODOS los envíos de la página, una consulta por cada uno.
        $refs = $this->references->forShipmentIds($shipmentIds);
        $unitIds = $this->units?->forShipmentIds($shipmentIds) ?? [];

        $views = [];
        foreach ($deliveries as $delivery) {
            $views[] = DeliveryView::fromDelivery(
                $delivery,
                $refs[$delivery->shipmentId()] ?? null,
                $unitIds[$delivery->shipmentId()] ?? [],
            );
        }

        $total = $query->limit === null ? count($views) : $this->deliveries->countByVehicleId($query->vehicleId);

        return new VehicleDeliveriesPage($views, $total);
    }
}
