<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

it('crea un turno y calcula su duración en minutos', function (): void {
    $shift = WorkShift::between(
        new DateTimeImmutable('2026-10-14 06:00:00'),
        new DateTimeImmutable('2026-10-14 14:30:00'),
    );

    expect($shift->durationInMinutes())->toBe(510)
        ->and($shift->startMinuteOfDay())->toBe(360)
        ->and($shift->endMinuteOfDay())->toBe(870);
});

it('rechaza un turno que termina antes o al mismo tiempo que empieza', function (): void {
    expect(fn () => WorkShift::between(
        new DateTimeImmutable('2026-10-14 10:00:00'),
        new DateTimeImmutable('2026-10-14 10:00:00'),
    ))->toThrow(FleetException::class, 'terminar después de empezar');
});

it('rechaza un turno de más de 24 horas', function (): void {
    expect(fn () => WorkShift::between(
        new DateTimeImmutable('2026-10-14 06:00:00'),
        new DateTimeImmutable('2026-10-15 06:01:00'),
    ))->toThrow(FleetException::class, 'más de 24 horas');
});

it('admite turnos nocturnos que cruzan la medianoche: el fin pasa de 1440 minutos', function (): void {
    $shift = WorkShift::between(
        new DateTimeImmutable('2026-10-14 22:00:00'),
        new DateTimeImmutable('2026-10-15 02:00:00'),
    );

    expect($shift->startMinuteOfDay())->toBe(1320)
        ->and($shift->endMinuteOfDay())->toBe(1560)
        ->and($shift->durationInMinutes())->toBe(240);
});

it('detecta solapamiento entre turnos', function (): void {
    $a = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 12:00'));
    $b = WorkShift::between(new DateTimeImmutable('2026-10-14 11:00'), new DateTimeImmutable('2026-10-14 15:00'));
    $c = WorkShift::between(new DateTimeImmutable('2026-10-14 12:00'), new DateTimeImmutable('2026-10-14 16:00'));

    expect($a->overlaps($b))->toBeTrue()
        ->and($b->overlaps($a))->toBeTrue()
        // Turnos que solo se tocan en el extremo no se solapan.
        ->and($a->overlaps($c))->toBeFalse();
});

it('calcula el descanso entre turnos sin importar el orden', function (): void {
    $morning = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00'));
    $nextDay = WorkShift::between(new DateTimeImmutable('2026-10-15 02:00'), new DateTimeImmutable('2026-10-15 10:00'));

    // De las 14:00 a las 02:00 del día siguiente hay 12 horas = 720 minutos.
    expect($morning->restMinutesTo($nextDay))->toBe(720)
        ->and($nextDay->restMinutesTo($morning))->toBe(720);
});

it('el descanso es cero entre turnos que se solapan', function (): void {
    $a = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 12:00'));
    $b = WorkShift::between(new DateTimeImmutable('2026-10-14 10:00'), new DateTimeImmutable('2026-10-14 15:00'));

    expect($a->restMinutesTo($b))->toBe(0);
});

it('va y vuelve de su forma primitiva', function (): void {
    $shift = WorkShift::between(
        new DateTimeImmutable('2026-10-14 06:00:00'),
        new DateTimeImmutable('2026-10-14 14:00:00'),
    );

    $restored = WorkShift::fromArray($shift->toArray());

    expect($restored->durationInMinutes())->toBe($shift->durationInMinutes())
        ->and($restored->startsAt->getTimestamp())->toBe($shift->startsAt->getTimestamp())
        ->and($restored->endsAt->getTimestamp())->toBe($shift->endsAt->getTimestamp());
});
