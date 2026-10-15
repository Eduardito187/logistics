<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetGeofence;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\GeofenceView;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;

/**
 * Devuelve una geocerca por su id, o null si no existe.
 *
 * @implements QueryHandler<GetGeofence, GeofenceView|null>
 */
final readonly class GetGeofenceHandler implements QueryHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
    ) {}

    public function handle(Query $query): ?GeofenceView
    {
        assert($query instanceof GetGeofence);

        $geofence = $this->geofences->findById(GeofenceId::fromString($query->geofenceId));

        return $geofence === null ? null : GeofenceView::fromDomain($geofence);
    }
}
