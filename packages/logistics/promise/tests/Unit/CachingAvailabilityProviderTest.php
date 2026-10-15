<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Logistics\Capacity\Infrastructure\Cache\CapacityGenerationKey;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Infrastructure\Availability\CachingAvailabilityProvider;

/**
 * Proveedor en memoria que cuenta las veces que calcula, para detectar si el cache evitó el recálculo.
 */
function countingAvailabilityProvider(array $slots): AvailabilityProvider
{
    return new class($slots) implements AvailabilityProvider
    {
        public int $calls = 0;

        /** @param list<array{string, string}> $slots */
        public function __construct(private readonly array $slots) {}

        public function availableSlots(string $zoneId, DateTimeImmutable $date): array
        {
            $this->calls++;

            return array_map(
                fn (array $pair): DeliverySlot => DeliverySlot::of($date, $pair[1]),
                $this->slots,
            );
        }

        public function callCount(): int
        {
            return $this->calls;
        }
    };
}

it('returns the inner slots rebuilt from their primitive form', function (): void {
    $inner = countingAvailabilityProvider([['2026-10-20', '09:00-13:00'], ['2026-10-20', '14:00-18:00']]);
    $provider = new CachingAvailabilityProvider($inner, new CacheRepository(new ArrayStore));

    $slots = $provider->availableSlots('zone-1', new DateTimeImmutable('2026-10-20'));

    expect($slots)->toHaveCount(2)
        ->and($slots[0])->toBeInstanceOf(DeliverySlot::class)
        ->and($slots[0]->timeSlot)->toBe('09:00-13:00')
        ->and($slots[0]->dateString())->toBe('2026-10-20')
        ->and($slots[1]->timeSlot)->toBe('14:00-18:00');
});

it('serves the second identical call from cache without recomputing', function (): void {
    $inner = countingAvailabilityProvider([['2026-10-20', '09:00-13:00']]);
    $provider = new CachingAvailabilityProvider($inner, new CacheRepository(new ArrayStore));
    $date = new DateTimeImmutable('2026-10-20');

    $provider->availableSlots('zone-1', $date);
    $provider->availableSlots('zone-1', $date);

    expect($inner->callCount())->toBe(1);
});

it('stores the primitive array, never the domain object (regla #7)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $provider = new CachingAvailabilityProvider(
        countingAvailabilityProvider([['2026-10-20', '09:00-13:00']]),
        $cache,
    );

    $provider->availableSlots('zone-1', new DateTimeImmutable('2026-10-20'));

    $stored = $cache->get('logistics:promise:slots:zone-1:2026-10-20:g0');
    expect($stored)->toBe([['slot_date' => '2026-10-20', 'time_slot' => '09:00-13:00']]);
});

it('recomputes after the capacity generation of the day bumps (event invalidation)', function (): void {
    $cache = new CacheRepository(new ArrayStore);
    $inner = countingAvailabilityProvider([['2026-10-20', '09:00-13:00']]);
    $provider = new CachingAvailabilityProvider($inner, $cache);
    $date = new DateTimeImmutable('2026-10-20');

    $provider->availableSlots('zone-1', $date);
    expect($inner->callCount())->toBe(1);

    // Simula el evento: capacity cambió un cupo del día y subió su generación.
    $cache->increment(CapacityGenerationKey::forDate('2026-10-20'));

    $provider->availableSlots('zone-1', $date);
    expect($inner->callCount())->toBe(2);
});

it('keeps separate cache entries per zone', function (): void {
    $inner = countingAvailabilityProvider([['2026-10-20', '09:00-13:00']]);
    $provider = new CachingAvailabilityProvider($inner, new CacheRepository(new ArrayStore));
    $date = new DateTimeImmutable('2026-10-20');

    $provider->availableSlots('zone-1', $date);
    $provider->availableSlots('zone-2', $date);

    expect($inner->callCount())->toBe(2);
});
