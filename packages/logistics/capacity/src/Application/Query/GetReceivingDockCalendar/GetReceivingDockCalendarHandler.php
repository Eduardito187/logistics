<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetReceivingDockCalendar;

use Logistics\Capacity\Application\ReadModel\ReceivingDockCapacityView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los cupos de recepción (andenes) de un almacén central en una fecha como modelos de lectura,
 * ordenados por andén/franja/unidad. El cockpit de inbound lo usa para pintar la disponibilidad de
 * andenes del día.
 *
 * @implements QueryHandler<GetReceivingDockCalendarQuery, list<ReceivingDockCapacityView>>
 */
final readonly class GetReceivingDockCalendarHandler implements QueryHandler
{
    public function __construct(private CapacityRepository $buckets) {}

    /**
     * @return list<ReceivingDockCapacityView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetReceivingDockCalendarQuery);

        $date = SlotDate::fromString($query->date);

        $buckets = $this->buckets->inboundByWarehouseAndDate($query->warehouseId, $date);

        return array_map(ReceivingDockCapacityView::fromBucket(...), $buckets);
    }
}
