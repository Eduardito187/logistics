<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDeliveryState;

use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetDeliveryState, ?DeliveryView>
 */
final class GetDeliveryStateHandler implements QueryHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
    ) {}

    public function handle(Query $query): ?DeliveryView
    {
        if (!$query instanceof GetDeliveryState) {
            throw ExecutionException::because('GetDeliveryStateHandler solo maneja GetDeliveryState');
        }

        $delivery = $this->deliveries->findByShipmentId($query->shipmentId);

        return $delivery === null ? null : DeliveryView::fromDelivery($delivery);
    }
}
