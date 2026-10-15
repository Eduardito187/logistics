<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetAvailableCapacity;

use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el modelo de lectura del cupo pedido, o null si no existe.
 *
 * @implements QueryHandler<GetAvailableCapacityQuery, CapacityView|null>
 */
final readonly class GetAvailableCapacityHandler implements QueryHandler
{
    public function __construct(private CapacityRepository $buckets) {}

    public function handle(Query $query): ?CapacityView
    {
        assert($query instanceof GetAvailableCapacityQuery);

        $scope = BucketScope::fromNullable($query->nodeId, $query->zoneId, $query->municipalityId);
        $date = SlotDate::fromString($query->date);
        $slot = TimeSlot::fromString($query->timeSlot);
        $unit = CapacityUnit::tryFrom($query->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$query->unit}");

        $bucket = $this->buckets->findBucket($scope, $date, $slot, $unit);

        return $bucket === null ? null : CapacityView::fromBucket($bucket);
    }
}
