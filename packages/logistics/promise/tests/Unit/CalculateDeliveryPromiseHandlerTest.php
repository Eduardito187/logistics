<?php

declare(strict_types=1);

use Logistics\Promise\Application\Query\CalculateDeliveryPromise\CalculateDeliveryPromise;
use Logistics\Promise\Application\Query\CalculateDeliveryPromise\CalculateDeliveryPromiseHandler;
use Logistics\Promise\Application\ReadModel\PromiseOptionView;
use Logistics\Promise\Domain\Contract\CapacityCalendar;
use Logistics\Promise\Domain\Contract\CorridorAvailabilityProvider;
use Logistics\Promise\Domain\Contract\CoverageProfileProvider;
use Logistics\Promise\Domain\Contract\FulfillmentLeadTimeProvider;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Service\DeliveryPromiseCalculator;
use Logistics\Promise\Domain\ValueObject\LeadTime;
use Logistics\Promise\Domain\ValueObject\ServiceWindow;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Reloj fijo para el cálculo: todo "ahora" cae el martes 2026-10-13 a las 08:00.
 */
function promiseClock(): Clock
{
    return new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T08:00:00');
        }
    };
}

/**
 * Puerto de cobertura que devuelve siempre el perfil dado (o null si se pasa null).
 */
function coverageReturning(?ZoneServiceProfile $profile): CoverageProfileProvider
{
    return new class($profile) implements CoverageProfileProvider
    {
        public function __construct(private readonly ?ZoneServiceProfile $profile) {}

        public function profileFor(string $zoneId): ?ZoneServiceProfile
        {
            return $this->profile;
        }
    };
}

/**
 * Puerto de red que responde el booleano dado.
 */
function corridorReturning(bool $served): CorridorAvailabilityProvider
{
    return new class($served) implements CorridorAvailabilityProvider
    {
        public function __construct(private readonly bool $served) {}

        public function servesZone(string $zoneId): bool
        {
            return $this->served;
        }
    };
}

/**
 * Calendario de capacidad que devuelve el mismo cupo para cualquier fecha, y registra qué fechas se le
 * consultaron (para verificar que no se pregunta capacidad de días no servibles).
 *
 * @param list<SlotCapacity> $slots
 */
function capacityReturning(array $slots): CapacityCalendar
{
    return new class($slots) implements CapacityCalendar
    {
        /** @var list<string> */
        public array $askedDates = [];

        /** @param list<SlotCapacity> $slots */
        public function __construct(private readonly array $slots) {}

        public function availableSlots(string $zoneId, DateTimeImmutable $date): array
        {
            $this->askedDates[] = $date->format('Y-m-d');

            return $this->slots;
        }
    };
}

/**
 * Puerto de lead time que devuelve siempre el lead dado (y registra si se le consultó, para probar que el
 * camino retail no lo toca).
 */
function leadReturning(LeadTime $lead): FulfillmentLeadTimeProvider
{
    return new class($lead) implements FulfillmentLeadTimeProvider
    {
        public bool $wasAsked = false;

        public function __construct(private readonly LeadTime $lead) {}

        public function leadTimeFor(Ownership $ownership): LeadTime
        {
            $this->wasAsked = true;

            return $this->lead;
        }
    };
}

it('returns no options when the zone has no coverage profile', function (): void {
    $capacity = capacityReturning([SlotCapacity::of('AM', 5.0)]);

    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(null),
        corridorReturning(true),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    $options = $handler->handle(new CalculateDeliveryPromise('zone-1'));

    expect($options)->toBe([])
        ->and($capacity->askedDates)->toBe([]);
});

it('returns no options and skips capacity lookups when there is no corridor', function (): void {
    $capacity = capacityReturning([SlotCapacity::of('AM', 5.0)]);

    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([1, 2, 3, 4, 5, 6, 7], 0, [])),
        corridorReturning(false),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    $options = $handler->handle(new CalculateDeliveryPromise('zone-1'));

    expect($options)->toBe([])
        ->and($capacity->askedDates)->toBe([]);
});

it('combines coverage, corridor and capacity into promise option views', function (): void {
    $capacity = capacityReturning([
        SlotCapacity::of('09:00-13:00', 4.0),
        SlotCapacity::of('14:00-18:00', 0.0),
    ]);

    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([1, 2, 3, 4, 5, 6, 7], 0, [ServiceWindow::fromClock('08:00', '18:00')])),
        corridorReturning(true),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    // Horizonte de 2 días desde hoy (martes 13 y miércoles 14).
    $options = $handler->handle(new CalculateDeliveryPromise('zone-1', '2026-10-13', 2));

    // Un slot con cupo por día servible; el de cupo 0 se descarta.
    expect($options)->toHaveCount(2)
        ->and($options[0])->toBeInstanceOf(PromiseOptionView::class)
        ->and($options[0]->slotDate)->toBe('2026-10-13')
        ->and($options[0]->weekday)->toBe(2)
        ->and($options[0]->timeSlot)->toBe('09:00-13:00')
        ->and($options[0]->available)->toBe(4.0)
        ->and($options[1]->slotDate)->toBe('2026-10-14')
        ->and($capacity->askedDates)->toBe(['2026-10-13', '2026-10-14']);
});

it('only asks capacity for dates that passed the coverage and lead filters', function (): void {
    $capacity = capacityReturning([SlotCapacity::of('AM', 2.0)]);

    // Solo atiende miércoles (ISO 3): en un horizonte de 7 días desde el martes 13 solo cae el 14.
    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([3], 0, [])),
        corridorReturning(true),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    $options = $handler->handle(new CalculateDeliveryPromise('zone-1', '2026-10-13', 7));

    expect($capacity->askedDates)->toBe(['2026-10-14'])
        ->and($options)->toHaveCount(1)
        ->and($options[0]->slotDate)->toBe('2026-10-14');
});

it('rejects a blank zone', function (): void {
    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(null),
        corridorReturning(true),
        capacityReturning([]),
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    expect(fn (): array => $handler->handle(new CalculateDeliveryPromise('   ')))
        ->toThrow(PromiseException::class);
});

it('rejects an invalid from date', function (): void {
    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([1, 2, 3, 4, 5, 6, 7], 0, [])),
        corridorReturning(true),
        capacityReturning([]),
        new DeliveryPromiseCalculator,
        promiseClock(),
    );

    expect(fn (): array => $handler->handle(new CalculateDeliveryPromise('zone-1', '13-10-2026')))
        ->toThrow(PromiseException::class);
});

// --- lead time del marketplace (aditivo) -------------------------------------------------------

it('leaves the retail promise intact and never consults the lead provider', function (): void {
    $capacity = capacityReturning([SlotCapacity::of('AM', 5.0)]);
    $leads = leadReturning(LeadTime::ofDays(5)); // un lead grande que NO debe aplicarse al retail

    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([1, 2, 3, 4, 5, 6, 7], 0, [])),
        corridorReturning(true),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
        $leads,
    );

    // Consulta retail (sin modo de fulfillment): las fechas son las de siempre desde el martes 13.
    $options = $handler->handle(new CalculateDeliveryPromise('zone-1', '2026-10-13', 3));

    expect($capacity->askedDates)->toBe(['2026-10-13', '2026-10-14', '2026-10-15'])
        ->and($options)->toHaveCount(3)
        ->and($leads->wasAsked)->toBeFalse();
});

it('adds the seller lead time to a dropship promise', function (): void {
    $capacity = capacityReturning([SlotCapacity::of('AM', 5.0)]);
    $leads = leadReturning(LeadTime::ofDays(1)); // el seller tarda 1 día más en despachar

    $handler = new CalculateDeliveryPromiseHandler(
        coverageReturning(ZoneServiceProfile::of([1, 2, 3, 4, 5, 6, 7], 0, [])),
        corridorReturning(true),
        $capacity,
        new DeliveryPromiseCalculator,
        promiseClock(),
        $leads,
    );

    $options = $handler->handle(new CalculateDeliveryPromise(
        'zone-1',
        '2026-10-13',
        3,
        'dropship',
        Uuid::generate()->value,
    ));

    // El lead de 1 día empuja la fecha más temprana del 13 al 14: ya no se consulta capacidad del 13.
    expect($capacity->askedDates)->toBe(['2026-10-14', '2026-10-15'])
        ->and($options)->toHaveCount(2)
        ->and($options[0]->slotDate)->toBe('2026-10-14')
        ->and($leads->wasAsked)->toBeTrue();
});
