<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetEffectiveCapacity;

use Logistics\Capacity\Application\ReadModel\EffectiveCapacityView;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRuleProvider;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la capacidad efectiva de una clave: si hay un cupo definido, lo devuelve; si no, consulta
 * las reglas configurables (CapacityRuleProvider) para el total por defecto; si tampoco hay regla,
 * devuelve capacidad cero (source 'none'). Es la consulta que usa planning para saber cuánto puede
 * agendar sin exigir que cada bucket esté pre-creado.
 *
 * @implements QueryHandler<GetEffectiveCapacityQuery, EffectiveCapacityView>
 */
final readonly class GetEffectiveCapacityHandler implements QueryHandler
{
    public function __construct(
        private CapacityRepository $buckets,
        private CapacityRuleProvider $rules,
    ) {}

    public function handle(Query $query): EffectiveCapacityView
    {
        assert($query instanceof GetEffectiveCapacityQuery);

        $scope = BucketScope::fromNullable($query->nodeId, $query->zoneId, $query->municipalityId);
        $date = SlotDate::fromString($query->date);
        $slot = TimeSlot::fromString($query->timeSlot);
        $unit = CapacityUnit::tryFrom($query->unit)
            ?? throw CapacityException::because("unidad de capacidad desconocida: {$query->unit}");

        $bucket = $this->buckets->findBucket($scope, $date, $slot, $unit);
        if ($bucket !== null) {
            return EffectiveCapacityView::fromBucket($bucket);
        }

        $defaultTotal = $this->rules->defaultTotalFor($scope, $date, $slot, $unit);
        if ($defaultTotal !== null) {
            return EffectiveCapacityView::fromRule($scope, $date, $slot, $unit, $defaultTotal);
        }

        return EffectiveCapacityView::none($scope, $date, $slot, $unit);
    }
}
