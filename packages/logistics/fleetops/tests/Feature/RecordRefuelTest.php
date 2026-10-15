<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\FleetOps\Application\Command\RecordRefuel\RecordRefuel;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Flujo real de registro de una carga de combustible contra PostgreSQL (logistics_test): el comando deja
 * la fila en `fleetops_refuels` con el costo total calculado y publica su evento al outbox dentro de la
 * misma transacción.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('records a refuel row with the computed total and an outbox event', function (): void {
    $vehicleId = '019299aa-0000-7000-8000-00000000f001';

    app(CommandBus::class)->dispatch(new RecordRefuel(
        vehicleId: $vehicleId,
        driverId: null,
        fuelType: 'diesel',
        liters: 50.0,
        pricePerLiterCents: 372,
        currency: 'BOB',
        odometerKm: 120_000,
        station: 'YPFB Central',
        fullTank: true,
        refueledAt: '2026-10-10T08:00:00+00:00',
        note: null,
    ));

    $refuel = RefuelRecord::query()->where('vehicle_id', $vehicleId)->first();

    // 372 centavos/litro × 50 litros = 18600.
    expect($refuel)->not->toBeNull()
        ->and((int) $refuel->total_cost_cents)->toBe(18_600)
        ->and((bool) $refuel->full_tank)->toBeTrue()
        ->and((int) $refuel->odometer_km)->toBe(120_000);

    expect(DB::table('outbox_messages')->where('aggregate_type', 'fleetops.refuel')->count())->toBe(1)
        ->and(DB::table('outbox_messages')->where('aggregate_type', 'like', 'fleetops.%')->count())->toBeGreaterThanOrEqual(1);
});
