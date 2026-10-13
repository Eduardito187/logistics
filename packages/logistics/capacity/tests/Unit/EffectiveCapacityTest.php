<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityHandler;
use Logistics\Capacity\Application\Query\GetEffectiveCapacity\GetEffectiveCapacityQuery;
use Logistics\Capacity\Application\ReadModel\EffectiveCapacityView;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\Contract\CapacityRuleProvider;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;
use Logistics\Capacity\Infrastructure\Settings\ConfiguredCapacityRuleProvider;

/**
 * Repositorio de cupos en memoria para aislar el handler del almacenamiento. findBucket devuelve el
 * bucket precargado si su clave natural coincide; el resto de métodos son suficientes para la consulta.
 */
function inMemoryCapacityRepository(?CapacityBucket $preloaded): CapacityRepository
{
    return new class($preloaded) implements CapacityRepository
    {
        public function __construct(private ?CapacityBucket $preloaded) {}

        public function nextIdentity(): CapacityBucketId
        {
            return CapacityBucketId::generate();
        }

        public function save(CapacityBucket $bucket): void {}

        public function findById(CapacityBucketId $id): ?CapacityBucket
        {
            return $this->preloaded;
        }

        public function findBucket(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            return $this->preloaded;
        }

        public function findBucketForUpdate(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?CapacityBucket
        {
            return $this->preloaded;
        }

        public function byDate(SlotDate $date): array
        {
            return $this->preloaded === null ? [] : [$this->preloaded];
        }
    };
}

function ruleProviderReturning(?float $total): CapacityRuleProvider
{
    return new class($total) implements CapacityRuleProvider
    {
        public function __construct(private ?float $total) {}

        public function defaultTotalFor(BucketScope $scope, SlotDate $date, TimeSlot $slot, CapacityUnit $unit): ?Quantity
        {
            return $this->total === null ? null : Quantity::of($this->total);
        }
    };
}

function effectiveQuery(): GetEffectiveCapacityQuery
{
    return new GetEffectiveCapacityQuery(
        nodeId: '11111111-1111-7111-8111-111111111111',
        zoneId: null,
        date: '2026-10-13',
        timeSlot: '09:00-13:00',
        unit: 'deliveries',
    );
}

it('returns the explicit bucket when one exists (source=bucket)', function (): void {
    $bucket = CapacityBucket::define(
        CapacityBucketId::generate(),
        BucketScope::forNode('11111111-1111-7111-8111-111111111111'),
        SlotDate::fromString('2026-10-13'),
        TimeSlot::fromString('09:00-13:00'),
        CapacityUnit::Deliveries,
        Quantity::of(100.0),
    );
    $bucket->consume(Quantity::of(30.0));

    $handler = new GetEffectiveCapacityHandler(
        inMemoryCapacityRepository($bucket),
        ruleProviderReturning(999.0),
    );

    $view = $handler->handle(effectiveQuery());

    expect($view)->toBeInstanceOf(EffectiveCapacityView::class)
        ->and($view->source)->toBe('bucket')
        ->and($view->total)->toBe(100.0)
        ->and($view->used)->toBe(30.0)
        ->and($view->available)->toBe(70.0);
});

it('falls back to the rule default when there is no bucket (source=rule)', function (): void {
    $handler = new GetEffectiveCapacityHandler(
        inMemoryCapacityRepository(null),
        ruleProviderReturning(120.0),
    );

    $view = $handler->handle(effectiveQuery());

    expect($view->source)->toBe('rule')
        ->and($view->bucketId)->toBeNull()
        ->and($view->total)->toBe(120.0)
        ->and($view->used)->toBe(0.0)
        ->and($view->available)->toBe(120.0);
});

it('reports zero capacity when neither bucket nor rule applies (source=none)', function (): void {
    $handler = new GetEffectiveCapacityHandler(
        inMemoryCapacityRepository(null),
        ruleProviderReturning(null),
    );

    $view = $handler->handle(effectiveQuery());

    expect($view->source)->toBe('none')
        ->and($view->total)->toBe(0.0)
        ->and($view->available)->toBe(0.0)
        ->and($view->full)->toBeTrue();
});

it('reads default totals and per-slot overrides from configuration', function (): void {
    $config = new Repository([
        'logistics-capacity' => [
            'default_totals' => ['deliveries' => 120, 'weight' => 8000, 'volume' => 40],
            'by_slot' => ['deliveries' => ['08:00-12:00' => 150]],
        ],
    ]);
    $provider = new ConfiguredCapacityRuleProvider($config);
    $scope = BucketScope::forNode('11111111-1111-7111-8111-111111111111');
    $date = SlotDate::fromString('2026-10-13');

    $byUnit = $provider->defaultTotalFor($scope, $date, TimeSlot::fromString('16:00-20:00'), CapacityUnit::Deliveries);
    $bySlot = $provider->defaultTotalFor($scope, $date, TimeSlot::fromString('08:00-12:00'), CapacityUnit::Deliveries);
    $missing = $provider->defaultTotalFor($scope, $date, TimeSlot::fromString('16:00-20:00'), CapacityUnit::Volume);

    expect($byUnit?->value())->toBe(120.0)
        ->and($bySlot?->value())->toBe(150.0)
        ->and($missing?->value())->toBe(40.0);
});
