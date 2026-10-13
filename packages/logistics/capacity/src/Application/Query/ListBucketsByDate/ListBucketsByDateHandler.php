<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\ListBucketsByDate;

use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve todos los cupos de una fecha como modelos de lectura.
 *
 * @implements QueryHandler<ListBucketsByDateQuery, list<CapacityView>>
 */
final readonly class ListBucketsByDateHandler implements QueryHandler
{
    public function __construct(private CapacityRepository $buckets) {}

    /**
     * @return list<CapacityView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListBucketsByDateQuery);

        $date = SlotDate::fromString($query->date);

        return array_map(CapacityView::fromBucket(...), $this->buckets->byDate($date));
    }
}
