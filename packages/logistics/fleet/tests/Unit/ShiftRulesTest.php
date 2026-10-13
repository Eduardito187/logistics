<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

function shiftRules(): ShiftRules
{
    // Ventana 05:00–22:00, jornada máxima 10 h, descanso mínimo 10 h.
    return ShiftRules::fromHours(10, 5, 22, 10);
}

it('construye reglas desde horas', function (): void {
    $rules = shiftRules();

    expect($rules->maxDailyMinutes)->toBe(600)
        ->and($rules->earliestStartMinute)->toBe(300)
        ->and($rules->latestEndMinute)->toBe(1320)
        ->and($rules->minRestMinutes)->toBe(600);
});

it('rechaza reglas inválidas', function (): void {
    expect(fn () => ShiftRules::of(0, 300, 1320, 600))->toThrow(FleetException::class, 'jornada máxima');
    expect(fn () => ShiftRules::of(600, 1320, 300, 600))->toThrow(FleetException::class, 'ventana de turno es inválida');
    expect(fn () => ShiftRules::of(600, -1, 1320, 600))->toThrow(FleetException::class, 'no puede ser negativo');
    expect(fn () => ShiftRules::of(600, 300, 1320, -1))->toThrow(FleetException::class, 'descanso mínimo no puede ser negativo');
});

it('acepta un turno dentro de la ventana y la jornada', function (): void {
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00'));

    shiftRules()->ensureWindowAndDuration($shift);
})->throwsNoExceptions();

it('rechaza un turno que empieza antes de la ventana', function (): void {
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 04:00'), new DateTimeImmutable('2026-10-14 10:00'));

    expect(fn () => shiftRules()->ensureWindowAndDuration($shift))
        ->toThrow(FleetException::class, 'antes de la ventana');
});

it('rechaza un turno que termina después de la ventana', function (): void {
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 15:00'), new DateTimeImmutable('2026-10-14 23:00'));

    expect(fn () => shiftRules()->ensureWindowAndDuration($shift))
        ->toThrow(FleetException::class, 'después de la ventana');
});

it('rechaza un turno que excede la jornada máxima', function (): void {
    $shift = WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 20:00'));

    expect(fn () => shiftRules()->ensureWindowAndDuration($shift))
        ->toThrow(FleetException::class, 'jornada máxima');
});

it('acepta un turno con descanso suficiente respecto de los existentes', function (): void {
    $candidate = WorkShift::between(new DateTimeImmutable('2026-10-15 06:00'), new DateTimeImmutable('2026-10-15 14:00'));
    $existing = [
        WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00')),
    ];

    shiftRules()->ensureRestRespected($candidate, $existing);
})->throwsNoExceptions();

it('rechaza un turno que se solapa con uno existente', function (): void {
    $candidate = WorkShift::between(new DateTimeImmutable('2026-10-14 10:00'), new DateTimeImmutable('2026-10-14 18:00'));
    $existing = [
        WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00')),
    ];

    expect(fn () => shiftRules()->ensureRestRespected($candidate, $existing))
        ->toThrow(FleetException::class, 'se solapa');
});

it('rechaza un turno que no respeta el descanso mínimo', function (): void {
    // El existente termina 14:00; el candidato arranca a las 20:00 del mismo día = solo 6 h de descanso.
    $candidate = WorkShift::between(new DateTimeImmutable('2026-10-14 20:00'), new DateTimeImmutable('2026-10-14 21:30'));
    $existing = [
        WorkShift::between(new DateTimeImmutable('2026-10-14 06:00'), new DateTimeImmutable('2026-10-14 14:00')),
    ];

    expect(fn () => shiftRules()->ensureRestRespected($candidate, $existing))
        ->toThrow(FleetException::class, 'descanso mínimo');
});
