<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentTracking;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
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

        if ($tracking === null) {
            return null;
        }

        // Referencia externa humana del envío (lado lectura), junto al UUID crudo de shipment_id.
        // Mismo patrón que el belongsTo shipment() del TrackingRecord, resuelto aquí sin N+1.
        $externalId = ShipmentRecord::query()->whereKey($tracking->shipmentId())->value('external_id');

        return ShipmentTrackingView::fromTracking($tracking, is_string($externalId) ? $externalId : null);
    }
}
