<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\ListAlerts;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;

/**
 * Devuelve la lista de alertas como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa (compat con el mapa en vivo, que pide solo las no reconocidas).
 * Con `enrich`, completa placa y chofer de la página en lote (dos queries, sin N+1).
 *
 * @implements QueryHandler<ListAlerts, list<AlertView>|Page<AlertView>>
 */
final readonly class ListAlertsHandler implements QueryHandler
{
    public function __construct(
        private AlertRepository $alerts,
        private FleetDirectory $fleet,
    ) {}

    /**
     * @return list<AlertView>|Page<AlertView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListAlerts);

        $views = array_map(
            static fn (TelemetryAlert $alert): AlertView => AlertView::fromDomain($alert),
            $this->alerts->listBy(
                $query->vehicleId,
                $query->type,
                $query->acknowledged,
                $query->limit,
                $query->offset,
                $query->from,
                $query->to,
            ),
        );

        if ($query->enrich) {
            $views = $this->enrich($views);
        }

        if ($query->limit === null) {
            return $views;
        }

        return new Page(
            $views,
            $this->alerts->countBy($query->vehicleId, $query->type, $query->acknowledged, $query->from, $query->to),
        );
    }

    /**
     * @param list<AlertView> $views
     *
     * @return list<AlertView>
     */
    private function enrich(array $views): array
    {
        if ($views === []) {
            return [];
        }

        $plates = $this->fleet->plates(array_values(array_unique(array_map(
            static fn (AlertView $view): string => $view->vehicleId,
            $views,
        ))));
        $names = $this->fleet->driverNames(array_values(array_unique(array_filter(array_map(
            static fn (AlertView $view): string => $view->driverId ?? '',
            $views,
        ), static fn (string $id): bool => $id !== ''))));

        return array_map(
            static fn (AlertView $view): AlertView => $view->withNames(
                $plates[$view->vehicleId] ?? null,
                $view->driverId !== null ? ($names[$view->driverId] ?? null) : null,
            ),
            $views,
        );
    }
}
