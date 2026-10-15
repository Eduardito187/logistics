<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Service\DeliveryPromiseCalculator;
use Logistics\Promise\Domain\ValueObject\PromiseOption;
use Logistics\Promise\Domain\ValueObject\ServiceWindow;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;

/**
 * Perfil de zona de ejemplo: atiende los días indicados, con el tiempo base y las ventanas dadas.
 *
 * @param list<int>                         $serviceDays
 * @param list<array{0: string, 1: string}> $windows     horas 'HH:MM' por ventana
 */
function promiseProfile(array $serviceDays, int $leadMinutes = 0, array $windows = []): ZoneServiceProfile
{
    return ZoneServiceProfile::of(
        $serviceDays,
        $leadMinutes,
        array_map(
            static fn (array $w): ServiceWindow => ServiceWindow::fromClock($w[0], $w[1]),
            $windows,
        ),
    );
}

/**
 * @param  list<DateTimeImmutable> $dates
 * @return list<string>
 */
function datesToStrings(array $dates): array
{
    return array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
}

// --- serviceableDates --------------------------------------------------------------------------

it('returns no serviceable dates when there is no corridor to the zone', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    $dates = $calculator->serviceableDates(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        false,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T08:00:00'),
        7,
    );

    expect($dates)->toBe([]);
});

it('returns no serviceable dates when the zone serves no weekday', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    $dates = $calculator->serviceableDates(
        promiseProfile([]),
        true,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T08:00:00'),
        7,
    );

    expect($dates)->toBe([]);
});

it('returns no serviceable dates when the horizon is empty', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    $dates = $calculator->serviceableDates(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        true,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T08:00:00'),
        0,
    );

    expect($dates)->toBe([]);
});

it('lists every day in the horizon when the zone serves all weekdays with no lead time', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    // 2026-10-13 es martes (ISO 2).
    $dates = $calculator->serviceableDates(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        true,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T10:00:00'),
        3,
    );

    expect(datesToStrings($dates))->toBe(['2026-10-13', '2026-10-14', '2026-10-15']);
});

it('keeps only the weekdays the zone serves within the horizon', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    // Solo martes (ISO 2): desde el martes 13, con horizonte 8 días (offsets 0..7), caen el 13 y el 20.
    $dates = $calculator->serviceableDates(
        promiseProfile([2]),
        true,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T10:00:00'),
        8,
    );

    expect(datesToStrings($dates))->toBe(['2026-10-13', '2026-10-20']);
});

it('drops days earlier than the base lead time counted from now', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    // Tiempo base de 1 día (1440 min): a las 10:00 del 13, el día entregable más temprano es el 14.
    $dates = $calculator->serviceableDates(
        promiseProfile([1, 2, 3, 4, 5, 6, 7], 1440),
        true,
        new DateTimeImmutable('2026-10-13'),
        new DateTimeImmutable('2026-10-13T10:00:00'),
        3,
    );

    expect(datesToStrings($dates))->toBe(['2026-10-14', '2026-10-15']);
});

it('normalizes serviceable dates to midnight', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    $dates = $calculator->serviceableDates(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        true,
        new DateTimeImmutable('2026-10-13T17:45:00'),
        new DateTimeImmutable('2026-10-13T10:00:00'),
        1,
    );

    expect($dates)->toHaveCount(1)
        ->and($dates[0]->format('H:i:s'))->toBe('00:00:00')
        ->and($dates[0]->format('Y-m-d'))->toBe('2026-10-13');
});

// --- optionsFor --------------------------------------------------------------------------------

it('offers only slots that still have free capacity', function (): void {
    $calculator = new DeliveryPromiseCalculator;
    $date = new DateTimeImmutable('2026-10-20');

    $options = $calculator->optionsFor(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        $date,
        [
            SlotCapacity::of('AM', 3.0),
            SlotCapacity::of('PM', 0.0),
        ],
    );

    expect($options)->toHaveCount(1)
        ->and($options[0])->toBeInstanceOf(PromiseOption::class)
        ->and($options[0]->timeSlot)->toBe('AM')
        ->and($options[0]->available)->toBe(3.0)
        ->and($options[0]->dateString())->toBe('2026-10-20');
});

it('passes every slot when the zone declares no time windows', function (): void {
    $calculator = new DeliveryPromiseCalculator;
    $date = new DateTimeImmutable('2026-10-20');

    $options = $calculator->optionsFor(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        $date,
        [
            SlotCapacity::of('09:00-13:00', 2.0),
            SlotCapacity::of('14:00-18:00', 1.0),
        ],
    );

    expect($options)->toHaveCount(2);
});

it('keeps range slots that overlap a service window and drops those outside it', function (): void {
    $calculator = new DeliveryPromiseCalculator;
    $date = new DateTimeImmutable('2026-10-20');

    // La zona atiende de 09:00 a 13:00.
    $options = $calculator->optionsFor(
        promiseProfile([1, 2, 3, 4, 5, 6, 7], 0, [['09:00', '13:00']]),
        $date,
        [
            SlotCapacity::of('09:00-12:00', 4.0),
            SlotCapacity::of('14:00-18:00', 4.0),
        ],
    );

    expect($options)->toHaveCount(1)
        ->and($options[0]->timeSlot)->toBe('09:00-12:00');
});

it('lets non-range slot labels pass the window filter so capacity alone restricts them', function (): void {
    $calculator = new DeliveryPromiseCalculator;
    $date = new DateTimeImmutable('2026-10-20');

    // Con ventana declarada, una etiqueta tipo "AM" no se puede comparar por horario: debe pasar igual.
    $options = $calculator->optionsFor(
        promiseProfile([1, 2, 3, 4, 5, 6, 7], 0, [['09:00', '13:00']]),
        $date,
        [SlotCapacity::of('AM', 5.0)],
    );

    expect($options)->toHaveCount(1)
        ->and($options[0]->timeSlot)->toBe('AM');
});

it('returns no options when the zone has no capacity for the date', function (): void {
    $calculator = new DeliveryPromiseCalculator;

    $options = $calculator->optionsFor(
        promiseProfile([1, 2, 3, 4, 5, 6, 7]),
        new DateTimeImmutable('2026-10-20'),
        [],
    );

    expect($options)->toBe([]);
});
