<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListPreTripChecklists;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\PreTripChecklistView;
use Logistics\Fleet\Domain\Contract\FleetLabelReader;
use Logistics\Fleet\Domain\Contract\PreTripInspectionRepository;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las inspecciones pre-salida como proyecciones de lectura, filtradas y ordenadas en SQL
 * (fecha desc, id desc). Con `limit` baja LIMIT/OFFSET a SQL y devuelve una Page con el total.
 *
 * Enriquece cada vista con la placa del vehículo y el nombre del chofer, resueltos EN LOTE (dos
 * consultas para toda la página) para el historial del app, sin N+1.
 *
 * @implements QueryHandler<ListPreTripChecklists, list<PreTripChecklistView>|Page<PreTripChecklistView>>
 */
final readonly class ListPreTripChecklistsHandler implements QueryHandler
{
    public function __construct(
        private PreTripInspectionRepository $inspections,
        private FleetLabelReader $labels,
    ) {}

    /**
     * @return list<PreTripChecklistView>|Page<PreTripChecklistView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListPreTripChecklists);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $inspections = $this->inspections->listBy(
            $query->vehicleId,
            $query->onlyPassed,
            $from,
            $to,
            $query->limit,
            $query->offset,
            $query->routeId,
        );

        $plates = $this->labels->platesByVehicleId(array_values(array_map(
            static fn (PreTripInspection $inspection): string => $inspection->vehicleId()->toString(),
            $inspections,
        )));
        $names = $this->labels->namesByDriverId(array_values(array_map(
            static fn (PreTripInspection $inspection): string => $inspection->driverId()->toString(),
            $inspections,
        )));

        $views = array_map(
            static fn (PreTripInspection $inspection): PreTripChecklistView => PreTripChecklistView::fromDomain(
                $inspection,
                $plates[$inspection->vehicleId()->toString()] ?? null,
                $names[$inspection->driverId()->toString()] ?? null,
            ),
            $inspections,
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page(
            $views,
            $this->inspections->countBy($query->vehicleId, $query->onlyPassed, $from, $to, $query->routeId),
        );
    }
}
