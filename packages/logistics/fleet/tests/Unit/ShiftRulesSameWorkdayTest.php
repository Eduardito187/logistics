<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\ShiftRules;
use Logistics\Fleet\Domain\ValueObject\WorkShift;

// N salidas por día: dos turnos que caben juntos en una jornada máxima son tramos de la misma jornada (el
// chofer vuelve y sale de nuevo, con el mismo camión o con otro) y no se les exige el descanso entre
// jornadas. Ventana 05:00–22:00, jornada máxima 10 h, descanso mínimo 10 h.

function sameWorkdayShift(string $from, string $to): WorkShift
{
    return WorkShift::between(new DateTimeImmutable($from), new DateTimeImmutable($to));
}

it('acepta una segunda salida en la misma jornada sin exigir el descanso entre jornadas', function (): void {
    $rules = ShiftRules::fromHours(10, 5, 22, 10);

    $rules->ensureRestRespected(
        sameWorkdayShift('2026-11-14 13:00', '2026-11-14 16:00'),
        [sameWorkdayShift('2026-11-14 06:00', '2026-11-14 11:00')],
    );
})->throwsNoExceptions();

it('sigue rechazando el solapamiento entre dos salidas de la misma jornada', function (): void {
    $rules = ShiftRules::fromHours(10, 5, 22, 10);

    expect(fn () => $rules->ensureRestRespected(
        sameWorkdayShift('2026-11-14 10:00', '2026-11-14 13:00'),
        [sameWorkdayShift('2026-11-14 06:00', '2026-11-14 11:00')],
    ))->toThrow(FleetException::class, 'se solapa');
});

it('exige el descanso cuando las salidas juntas exceden la jornada máxima', function (): void {
    $rules = ShiftRules::fromHours(10, 5, 22, 10);

    // 06:00 → 18:00 son 12 h de lapso: ya no es una sola jornada, y entre ambas hay solo 5 h de descanso.
    expect(fn () => $rules->ensureRestRespected(
        sameWorkdayShift('2026-11-14 16:00', '2026-11-14 18:00'),
        [sameWorkdayShift('2026-11-14 06:00', '2026-11-14 11:00')],
    ))->toThrow(FleetException::class, 'descanso mínimo');
});
