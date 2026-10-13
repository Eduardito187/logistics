<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentTracking;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Tracking\Application\ReadModel\ShipmentTrackingView;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * @implements QueryHandler<GetShipmentTracking, ?ShipmentTrackingView>
 */
final class GetShipmentTrackingHandler implements QueryHandler
{
    public function __construct(
        private readonly TrackingRepository $trackings,
    ) {}

    public function handle(Query $query): ?ShipmentTrackingView
    {
        if (!$query instanceof GetShipmentTracking) {
            throw TrackingException::because('GetShipmentTrackingHandler solo maneja GetShipmentTracking');
        }

        $tracking = $this->trackings->findByShipmentId($query->shipmentId);

        return $tracking === null ? null : ShipmentTrackingView::fromTracking($tracking);
    }
}
