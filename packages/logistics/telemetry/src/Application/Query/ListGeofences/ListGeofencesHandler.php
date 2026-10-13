<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListGeofences;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\GeofenceView;
use Logistics\Telemetry\Domain\Contract\GeofenceRepository;
use Logistics\Telemetry\Domain\Model\Geofence;

/**
 * Devuelve la lista de geocercas como proyecciones de lectura.
 *
 * @implements QueryHandler<ListGeofences, list<GeofenceView>>
 */
final readonly class ListGeofencesHandler implements QueryHandler
{
    public function __construct(
        private GeofenceRepository $geofences,
    ) {}

    /**
     * @return list<GeofenceView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListGeofences);

        $geofences = $this->geofences->all();
        if ($query->type !== null) {
            $geofences = array_values(array_filter(
                $geofences,
                static fn (Geofence $geofence): bool => $geofence->type()->value === $query->type,
            ));
        }

        return array_map(static fn (Geofence $geofence): GeofenceView => GeofenceView::fromDomain($geofence), $geofences);
    }
}
