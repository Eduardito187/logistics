<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Fleet\Application\Command\DisbandCrew\DisbandCrew;
use Logistics\Fleet\Application\Query\ListCrews\ListCrews;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

// Tripulaciones para el admin de la app: ver qué camión tiene cada chofer (por fecha, vehículo o chofer,
// con placa y nombres ya resueltos) y quitar una asignación.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Inserta una tripulación mínima y devuelve [crewId, vehicleId, driverId].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function cdtCrew(string $date, ?string $vehicleId = null, ?string $driverId = null): array
{
    $crewId = (string) Str::uuid7();
    $vehicleId ??= (string) Str::uuid7();
    $driverId ??= (string) Str::uuid7();

    DB::table('fleet_crews')->insert([
        'id' => $crewId,
        'driver_id' => $driverId,
        'helper_ids' => json_encode([(string) Str::uuid7()]),
        'vehicle_id' => $vehicleId,
        'assembled_for' => $date,
    ]);

    return [$crewId, $vehicleId, $driverId];
}

it('lista las tripulaciones filtrando por fecha, vehículo y chofer, con el total', function (): void {
    [, $vehicleA, $driverA] = cdtCrew('2026-11-14');
    cdtCrew('2026-11-14');
    cdtCrew('2026-11-15', $vehicleA);

    $bus = app(QueryBus::class);

    $ofDay = $bus->ask(new ListCrews('2026-11-14'));
    $ofVehicle = $bus->ask(new ListCrews(null, $vehicleA));
    $ofDriver = $bus->ask(new ListCrews(null, null, $driverA));

    expect(array_unique(array_column($ofDay['items'], 'assembled_for')))->toBe(['2026-11-14'])
        ->and($ofVehicle['total'])->toBe(2)
        ->and(array_unique(array_column($ofVehicle['items'], 'vehicle_id')))->toBe([$vehicleA])
        ->and($ofDriver['total'])->toBe(1)
        ->and($ofDriver['items'][0]['helpers'])->toHaveCount(1)
        ->and($ofDriver['items'][0])->toHaveKeys(['vehicle_plate', 'driver_name', 'shift_starts_at']);
});

it('pagina el listado', function (): void {
    $vehicle = (string) Str::uuid7();
    foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
        cdtCrew($date, $vehicle);
    }

    $page = app(QueryBus::class)->ask(new ListCrews(null, $vehicle, null, 2, 0));

    expect($page['total'])->toBe(3)
        ->and($page['items'])->toHaveCount(2)
        // De la más reciente a la más vieja.
        ->and($page['items'][0]['assembled_for'])->toBe('2026-11-12');
});

it('quita una asignación y rechaza quitar una que no existe', function (): void {
    [$crewId] = cdtCrew('2026-11-14');

    app(CommandBus::class)->dispatch(new DisbandCrew($crewId));

    expect(DB::table('fleet_crews')->where('id', $crewId)->exists())->toBeFalse()
        ->and(fn () => app(CommandBus::class)->dispatch(new DisbandCrew($crewId)))->toThrow(FleetException::class);
});
