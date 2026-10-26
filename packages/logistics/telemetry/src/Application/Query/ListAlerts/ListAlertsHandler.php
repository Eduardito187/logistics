<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListAlerts;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;

/**
 * Devuelve la lista de alertas como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa (compat con el mapa en vivo, que pide solo las no reconocidas).
 *
 * @implements QueryHandler<ListAlerts, list<AlertView>|Page<AlertView>>
 */
final readonly class ListAlertsHandler implements QueryHandler
{
    public function __construct(
        private AlertRepository $alerts,
    ) {}

    /**
     * @return list<AlertView>|Page<AlertView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListAlerts);

        $views = array_map(
            static fn (TelemetryAlert $alert): AlertView => AlertView::fromDomain($alert),
            $this->alerts->listBy($query->vehicleId, $query->type, $query->acknowledged, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->alerts->countBy($query->vehicleId, $query->type, $query->acknowledged));
    }
}
