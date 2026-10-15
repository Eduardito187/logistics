<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListAlerts;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;

/**
 * Devuelve la lista de alertas como proyecciones de lectura.
 *
 * @implements QueryHandler<ListAlerts, list<AlertView>>
 */
final readonly class ListAlertsHandler implements QueryHandler
{
    public function __construct(
        private AlertRepository $alerts,
    ) {}

    /**
     * @return list<AlertView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListAlerts);

        $alerts = $this->alerts->listBy($query->vehicleId, $query->type, $query->acknowledged);

        return array_map(static fn (TelemetryAlert $alert): AlertView => AlertView::fromDomain($alert), $alerts);
    }
}
