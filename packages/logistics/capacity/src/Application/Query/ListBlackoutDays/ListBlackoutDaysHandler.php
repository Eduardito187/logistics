<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBlackoutDays;

use Logistics\Capacity\Application\ReadModel\BlackoutDayView;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los días bloqueados de un rango como modelos de lectura.
 *
 * @implements QueryHandler<ListBlackoutDaysQuery, list<BlackoutDayView>>
 */
final readonly class ListBlackoutDaysHandler implements QueryHandler
{
    public function __construct(private DispatchBlackoutRepository $blackouts) {}

    /**
     * @return list<BlackoutDayView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListBlackoutDaysQuery);

        $from = SlotDate::fromString($query->from);
        $to = SlotDate::fromString($query->to);

        return array_map(
            BlackoutDayView::fromDomain(...),
            $this->blackouts->listInRange($from, $to, $query->municipalityId),
        );
    }
}
