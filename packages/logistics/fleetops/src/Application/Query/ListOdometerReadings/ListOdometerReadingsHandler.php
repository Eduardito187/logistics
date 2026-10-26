<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListOdometerReadings;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\OdometerView;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las lecturas de odómetro que cumplen los filtros como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa, por compatibilidad.
 *
 * @implements QueryHandler<ListOdometerReadings, list<OdometerView>|Page<OdometerView>>
 */
final readonly class ListOdometerReadingsHandler implements QueryHandler
{
    public function __construct(
        private OdometerRepository $readings,
    ) {}

    /**
     * @return list<OdometerView>|Page<OdometerView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListOdometerReadings);

        $from = $query->from !== null ? new DateTimeImmutable($query->from) : null;
        $to = $query->to !== null ? new DateTimeImmutable($query->to) : null;

        $views = array_map(
            static fn (OdometerReading $reading): OdometerView => OdometerView::fromDomain($reading),
            $this->readings->listBy($query->vehicleId, $from, $to, $query->limit, $query->offset),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->readings->countBy($query->vehicleId, $from, $to));
    }
}
