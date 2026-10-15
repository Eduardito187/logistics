<?php

declare(strict_types=1);

use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Tests de integración de turnos de tripulación contra PostgreSQL (base logistics_test).
 *
 * Cubren el round-trip del turno por el repositorio, la consulta de turnos por integrante (chofer o
 * ayudante, vía jsonb) y la validación de reglas al armar una tripulación por el CommandBus.
 */
beforeEach(function (): void {
    //
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('persiste y reconstituye el turno de una tripulación', function (): void {
    $driverId = Uuid::generate()->value;
    $vehicleId = Uuid::generate()->value;

    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: '2026-10-14',
        shiftStartsAt: '2026-10-14 06:00:00',
        shiftEndsAt: '2026-10-14 14:00:00',
    ));

    $row = CrewModel::query()->where('driver_id', $driverId)->firstOrFail();
    expect($row->shift_starts_at)->not->toBeNull()
        ->and($row->shift_ends_at)->not->toBeNull();

    /** @var CrewRepository $repo */
    $repo = app(CrewRepository::class);
    $shifts = $repo->workShiftsForMemberBetween($driverId, new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-15'));

    expect($shifts)->toHaveCount(1)
        ->and($shifts[0]->durationInMinutes())->toBe(480);
});

it('encuentra los turnos de un ayudante por la columna jsonb', function (): void {
    $helperId = Uuid::generate()->value;

    CrewModel::query()->create([
        'id' => Uuid::generate()->value,
        'driver_id' => Uuid::generate()->value,
        'helper_ids' => [$helperId],
        'vehicle_id' => Uuid::generate()->value,
        'assembled_for' => '2026-10-14',
        'shift_starts_at' => '2026-10-14T06:00:00-04:00',
        'shift_ends_at' => '2026-10-14T14:00:00-04:00',
    ]);

    /** @var CrewRepository $repo */
    $repo = app(CrewRepository::class);
    $shifts = $repo->workShiftsForMemberBetween($helperId, new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-15'));

    expect($shifts)->toHaveCount(1);
});

it('ignora las tripulaciones sin turno en la consulta de turnos', function (): void {
    $driverId = Uuid::generate()->value;

    CrewModel::query()->create([
        'id' => Uuid::generate()->value,
        'driver_id' => $driverId,
        'helper_ids' => [],
        'vehicle_id' => Uuid::generate()->value,
        'assembled_for' => '2026-10-14',
        'shift_starts_at' => null,
        'shift_ends_at' => null,
    ]);

    /** @var CrewRepository $repo */
    $repo = app(CrewRepository::class);
    $shifts = $repo->workShiftsForMemberBetween($driverId, new DateTimeImmutable('2026-10-13'), new DateTimeImmutable('2026-10-15'));

    expect($shifts)->toBeEmpty();
});

it('rechaza armar una tripulación cuyo turno viola el descanso mínimo del chofer', function (): void {
    $driverId = Uuid::generate()->value;
    $vehicleId = Uuid::generate()->value;

    // Primer turno válido (06:00–14:00).
    app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: '2026-10-14',
        shiftStartsAt: '2026-10-14 06:00:00',
        shiftEndsAt: '2026-10-14 14:00:00',
    ));

    // Segundo turno el mismo día de noche: solo 6 h de descanso respecto del primero.
    $second = fn () => app(CommandBus::class)->dispatch(new AssembleCrew(
        driverId: $driverId,
        helperIds: [],
        vehicleId: $vehicleId,
        assembledFor: '2026-10-14',
        shiftStartsAt: '2026-10-14 20:00:00',
        shiftEndsAt: '2026-10-14 21:30:00',
    ));

    expect($second)->toThrow(FleetException::class, 'descanso mínimo');

    // Solo quedó persistida la primera tripulación: la segunda no se armó.
    expect(CrewModel::query()->where('driver_id', $driverId)->count())->toBe(1);
});
