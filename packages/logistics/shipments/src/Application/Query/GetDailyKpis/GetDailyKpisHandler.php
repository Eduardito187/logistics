<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\GetDailyKpis;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shipments\Application\Contract\DailyKpisReader;
use Logistics\Shipments\Application\ReadModel\DailyKpisView;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Resuelve los KPIs del día delegando en el lector agregado (SQL de solo lectura, caché corta).
 *
 * @implements QueryHandler<GetDailyKpis, DailyKpisView>
 */
final class GetDailyKpisHandler implements QueryHandler
{
    public function __construct(
        private readonly DailyKpisReader $reader,
    ) {}

    public function handle(Query $query): DailyKpisView
    {
        if (!$query instanceof GetDailyKpis) {
            throw ShipmentsException::because('GetDailyKpisHandler solo maneja GetDailyKpis');
        }

        if ($query->emptyScope) {
            return DailyKpisView::empty($query->date, $query->scope, (new DateTimeImmutable('now'))->format(DATE_ATOM));
        }

        return $this->reader->daily(
            date: $query->date,
            scope: $query->scope,
            nodeId: $query->nodeId,
            vehicleId: $query->vehicleId,
            driverId: $query->driverId,
        );
    }
}
