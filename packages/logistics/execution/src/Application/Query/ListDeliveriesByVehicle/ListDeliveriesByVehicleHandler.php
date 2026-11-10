<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListDeliveriesByVehicle;

use Logistics\Execution\Application\Contract\DeliveryReferenceReader;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListDeliveriesByVehicle, list<DeliveryView>>
 */
final class ListDeliveriesByVehicleHandler implements QueryHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly DeliveryReferenceReader $references,
    ) {}

    /**
     * @return list<DeliveryView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListDeliveriesByVehicle) {
            throw ExecutionException::because('ListDeliveriesByVehicleHandler solo maneja ListDeliveriesByVehicle');
        }

        $deliveries = [];
        $shipmentIds = [];
        foreach ($this->deliveries->findByVehicleId($query->vehicleId) as $delivery) {
            $deliveries[] = $delivery;
            $shipmentIds[] = $delivery->shipmentId();
        }

        // Referencia legible de TODOS los envíos en UNA consulta (sin N+1), para que el chofer vea
        // pedido/cliente/dirección en vez del UUID.
        $refs = $this->references->forShipmentIds($shipmentIds);

        $views = [];
        foreach ($deliveries as $delivery) {
            $views[] = DeliveryView::fromDelivery($delivery, $refs[$delivery->shipmentId()] ?? null);
        }

        return $views;
    }
}
