<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListDeliveriesByVehicle;

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
    ) {}

    /**
     * @return list<DeliveryView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListDeliveriesByVehicle) {
            throw ExecutionException::because('ListDeliveriesByVehicleHandler solo maneja ListDeliveriesByVehicle');
        }

        $views = [];
        foreach ($this->deliveries->findByVehicleId($query->vehicleId) as $delivery) {
            $views[] = DeliveryView::fromDelivery($delivery);
        }

        return $views;
    }
}
