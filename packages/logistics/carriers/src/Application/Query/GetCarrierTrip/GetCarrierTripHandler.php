<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\GetCarrierTrip;

use Logistics\Carriers\Application\ReadModel\CarrierTripView;
use Logistics\Carriers\Domain\Contract\CarrierTripRepository;
use Logistics\Carriers\Domain\Exception\CarrierTripNotFound;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la proyección de estado de un viaje. Falla con CarrierTripNotFound si no existe.
 *
 * @implements QueryHandler<GetCarrierTrip, CarrierTripView>
 */
final readonly class GetCarrierTripHandler implements QueryHandler
{
    public function __construct(
        private CarrierTripRepository $trips,
    ) {}

    public function handle(Query $query): CarrierTripView
    {
        assert($query instanceof GetCarrierTrip);

        $trip = $this->trips->findById(CarrierTripId::fromString($query->tripId));
        if ($trip === null) {
            throw CarrierTripNotFound::withId($query->tripId);
        }

        return CarrierTripView::fromDomain($trip);
    }
}
