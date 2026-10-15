<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Query\CheckDispatchDay\CheckDispatchDayQuery;
use Logistics\Capacity\Application\Query\ListBucketsByDate\ListBucketsByDateQuery;
use Logistics\Capacity\Application\ReadModel\CapacityView;
use Logistics\Capacity\Application\ReadModel\DispatchDayAvailabilityView;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedAvailabilityProvider;
use Logistics\Promise\Infrastructure\Availability\CapacityBackedCapacityCalendar;
use Logistics\Promise\Infrastructure\Availability\DispatchDayGate;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * QueryBus falso que responde la lista de cupos del día y el veredicto de despacho por (municipio, fecha).
 * Permite probar el filtro de día bloqueado / fuera de horizonte sin tocar capacity.
 */
function blackoutQueryBus(array $buckets, array $dispatchByMunicipality): QueryBus
{
    return new class($buckets, $dispatchByMunicipality) implements QueryBus
    {
        /**
         * @param list<CapacityView>                                $buckets
         * @param array<string, array{blocked: bool, within: bool}> $dispatch
         */
        public function __construct(
            private readonly array $buckets,
            private readonly array $dispatch,
        ) {}

        public function ask(Query $query): mixed
        {
            if ($query instanceof ListBucketsByDateQuery) {
                return $this->buckets;
            }

            if ($query instanceof CheckDispatchDayQuery) {
                $key = $query->municipalityId ?? '';
                $row = $this->dispatch[$key] ?? ['blocked' => false, 'within' => true];

                return new DispatchDayAvailabilityView(
                    $query->date,
                    $query->municipalityId,
                    $row['blocked'],
                    $row['blocked'] ? 'feriado' : null,
                    $row['within'],
                    null,
                );
            }

            throw new RuntimeException('consulta inesperada: '.$query::class);
        }
    };
}

function deliveriesBucket(string $zoneId, string $municipalityId, string $slot, float $available): CapacityView
{
    return new CapacityView(
        id: 'bucket-'.$slot,
        nodeId: null,
        zoneId: $zoneId,
        municipalityId: $municipalityId,
        date: '2026-11-20',
        timeSlot: $slot,
        unit: CapacityUnit::Deliveries->value,
        total: $available,
        used: 0.0,
        available: $available,
        full: false,
    );
}

it('does not offer a slot whose municipality has the day blocked', function (): void {
    $buckets = [
        deliveriesBucket('zone-1', 'muni-a', '09:00-13:00', 5.0),
        deliveriesBucket('zone-1', 'muni-a', '14:00-18:00', 5.0),
    ];
    $bus = blackoutQueryBus($buckets, ['muni-a' => ['blocked' => true, 'within' => true]]);
    $provider = new CapacityBackedAvailabilityProvider($bus, new DispatchDayGate($bus));

    $slots = $provider->availableSlots('zone-1', new DateTimeImmutable('2026-11-20'));

    expect($slots)->toBe([]);
});

it('does not offer a slot whose municipality is outside the active horizon', function (): void {
    $buckets = [deliveriesBucket('zone-1', 'muni-a', '09:00-13:00', 5.0)];
    $bus = blackoutQueryBus($buckets, ['muni-a' => ['blocked' => false, 'within' => false]]);
    $provider = new CapacityBackedAvailabilityProvider($bus, new DispatchDayGate($bus));

    expect($provider->availableSlots('zone-1', new DateTimeImmutable('2026-11-20')))->toBe([]);
});

it('offers slots when the day is open and within the horizon', function (): void {
    $buckets = [deliveriesBucket('zone-1', 'muni-a', '09:00-13:00', 5.0)];
    $bus = blackoutQueryBus($buckets, ['muni-a' => ['blocked' => false, 'within' => true]]);
    $provider = new CapacityBackedAvailabilityProvider($bus, new DispatchDayGate($bus));

    $slots = $provider->availableSlots('zone-1', new DateTimeImmutable('2026-11-20'));

    expect($slots)->toHaveCount(1)
        ->and($slots[0])->toBeInstanceOf(DeliverySlot::class)
        ->and($slots[0]->timeSlot)->toBe('09:00-13:00');
});

it('capacity calendar drops the free capacity of a blocked municipality day', function (): void {
    $buckets = [deliveriesBucket('zone-1', 'muni-a', '09:00-13:00', 5.0)];
    $bus = blackoutQueryBus($buckets, ['muni-a' => ['blocked' => true, 'within' => true]]);
    $calendar = new CapacityBackedCapacityCalendar($bus, new DispatchDayGate($bus));

    expect($calendar->availableSlots('zone-1', new DateTimeImmutable('2026-11-20')))->toBe([]);
});

it('capacity calendar keeps the free capacity when the day is dispatchable', function (): void {
    $buckets = [deliveriesBucket('zone-1', 'muni-a', '09:00-13:00', 5.0)];
    $bus = blackoutQueryBus($buckets, ['muni-a' => ['blocked' => false, 'within' => true]]);
    $calendar = new CapacityBackedCapacityCalendar($bus, new DispatchDayGate($bus));

    $slots = $calendar->availableSlots('zone-1', new DateTimeImmutable('2026-11-20'));

    expect($slots)->toHaveCount(1)
        ->and($slots[0])->toBeInstanceOf(SlotCapacity::class)
        ->and($slots[0]->available)->toBe(5.0);
});
