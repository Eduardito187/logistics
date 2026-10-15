<?php

declare(strict_types=1);

use Logistics\Promise\Application\Query\ListMunicipalityPromise\ListMunicipalityPromise;
use Logistics\Promise\Application\Query\ListMunicipalityPromise\ListMunicipalityPromiseHandler;
use Logistics\Promise\Application\ReadModel\MunicipalitySlotView;
use Logistics\Promise\Domain\Contract\MunicipalityDispatchCalendar;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Logistics\Promise\Domain\Contract\ShipmentLocator;
use Logistics\Promise\Domain\Service\CustomerLocationResolver;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Shared\Domain\Clock\Clock;

/*
| K-05 — Promesa de entrega al cliente por municipio. Prueban que el handler:
|  - lista las franjas con cupo del municipio a lo largo del horizonte, en primitivos y sin PII;
|  - excluye los días sin franjas despachables (el calendario, que filtra feriados/horizonte, no las devuelve);
|  - resuelve el municipio tanto de una coordenada como de un envío;
|  - devuelve vacío (no error) cuando el municipio no se puede resolver.
*/

/** Reloj fijo para el horizonte del test (prefijo propio: evita colisión con promiseClock de otro test). */
function muniPromiseClock(string $today = '2026-11-20'): Clock
{
    return new class($today) implements Clock
    {
        public function __construct(private readonly string $today) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable($this->today.' 10:00:00');
        }
    };
}

/** Resolver de municipio falso: mapea una coordenada o devuelve un municipio fijo. */
function muniPromiseResolver(?string $municipalityId): MunicipalityResolver
{
    return new class($municipalityId) implements MunicipalityResolver
    {
        public function __construct(private readonly ?string $municipalityId) {}

        public function resolve(float $latitude, float $longitude): ?string
        {
            return $this->municipalityId;
        }
    };
}

/** Localizador de envío falso: coordenada fija (o null si el envío no está geocodificado). */
function muniPromiseShipmentLocator(?array $point): ShipmentLocator
{
    return new class($point) implements ShipmentLocator
    {
        /** @param array{latitude: float, longitude: float}|null $point */
        public function __construct(private readonly ?array $point) {}

        public function locate(string $shipmentId): ?array
        {
            return $this->point;
        }
    };
}

/**
 * Calendario de despacho por municipio falso: devuelve, por fecha (Y-m-d), la lista de SlotCapacity. Una
 * fecha ausente (o lista vacía) modela un día sin franjas despachables — el día bloqueado o fuera de
 * horizonte, que el calendario real ya filtra.
 *
 * @param array<string, list<SlotCapacity>> $byDate
 */
function muniPromiseCalendar(array $byDate): MunicipalityDispatchCalendar
{
    return new class($byDate) implements MunicipalityDispatchCalendar
    {
        /** @param array<string, list<SlotCapacity>> $byDate */
        public function __construct(private readonly array $byDate) {}

        public function availableSlots(string $municipalityId, DateTimeImmutable $date): array
        {
            return $this->byDate[$date->format('Y-m-d')] ?? [];
        }
    };
}

function muniPromiseHandler(
    MunicipalityResolver $resolver,
    MunicipalityDispatchCalendar $calendar,
    ?ShipmentLocator $shipments = null,
    string $today = '2026-11-20',
): ListMunicipalityPromiseHandler {
    return new ListMunicipalityPromiseHandler(
        new CustomerLocationResolver($resolver, $shipments ?? muniPromiseShipmentLocator(null)),
        $calendar,
        muniPromiseClock($today),
    );
}

it('lists the available slots of the municipality across the horizon (no PII)', function (): void {
    $calendar = muniPromiseCalendar([
        '2026-11-20' => [SlotCapacity::of('09:00-13:00', 5.0), SlotCapacity::of('14:00-18:00', 2.0)],
        '2026-11-21' => [SlotCapacity::of('09:00-13:00', 3.0)],
    ]);
    $handler = muniPromiseHandler(muniPromiseResolver('muni-a'), $calendar);

    /** @var list<MunicipalitySlotView> $views */
    $views = $handler->handle(new ListMunicipalityPromise(
        latitude: -16.5,
        longitude: -68.1,
        fromDate: '2026-11-20',
        horizonDays: 2,
    ));

    expect($views)->toHaveCount(3)
        ->and($views[0])->toBeInstanceOf(MunicipalitySlotView::class)
        ->and($views[0]->toArray())->toBe([
            'municipality_id' => 'muni-a',
            'slot_date' => '2026-11-20',
            'time_slot' => '09:00-13:00',
            'available' => 5.0,
        ])
        ->and($views[2]->slotDate)->toBe('2026-11-21')
        ->and($views[2]->timeSlot)->toBe('09:00-13:00');
});

it('excludes days that have no dispatchable slots (blocked or out of horizon)', function (): void {
    // El día del medio no está en el calendario: modela un feriado / fuera de horizonte ya filtrado.
    $calendar = muniPromiseCalendar([
        '2026-11-20' => [SlotCapacity::of('09:00-13:00', 5.0)],
        '2026-11-22' => [SlotCapacity::of('09:00-13:00', 4.0)],
    ]);
    $handler = muniPromiseHandler(muniPromiseResolver('muni-a'), $calendar);

    $views = $handler->handle(new ListMunicipalityPromise(
        municipalityId: 'muni-a',
        fromDate: '2026-11-20',
        horizonDays: 3,
    ));

    $dates = array_map(static fn (MunicipalitySlotView $v): string => $v->slotDate, $views);

    expect($dates)->toBe(['2026-11-20', '2026-11-22'])
        ->and($dates)->not->toContain('2026-11-21');
});

it('resolves the municipality from a shipment coordinate', function (): void {
    $calendar = muniPromiseCalendar(['2026-11-20' => [SlotCapacity::of('09:00-13:00', 1.0)]]);
    $handler = muniPromiseHandler(
        muniPromiseResolver('muni-b'),
        $calendar,
        muniPromiseShipmentLocator(['latitude' => -17.4, 'longitude' => -66.1]),
    );

    $views = $handler->handle(new ListMunicipalityPromise(
        shipmentId: '0192f0a0-0000-7000-8000-000000000001',
        fromDate: '2026-11-20',
        horizonDays: 1,
    ));

    expect($views)->toHaveCount(1)
        ->and($views[0]->municipalityId)->toBe('muni-b');
});

it('returns empty when the municipality cannot be resolved (out of coverage)', function (): void {
    $calendar = muniPromiseCalendar(['2026-11-20' => [SlotCapacity::of('09:00-13:00', 5.0)]]);
    $handler = muniPromiseHandler(muniPromiseResolver(null), $calendar);

    $views = $handler->handle(new ListMunicipalityPromise(
        latitude: 0.0,
        longitude: 0.0,
        fromDate: '2026-11-20',
        horizonDays: 2,
    ));

    expect($views)->toBe([]);
});
