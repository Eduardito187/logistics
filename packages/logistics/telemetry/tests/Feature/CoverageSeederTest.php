<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Telemetry\Infrastructure\Seeder\TelemetryDemoSeeder;

/**
 * Cobertura del seeder de demo del contexto Telemetría (Patrón D del brief). Con vehículos de fleet
 * sembrados, el seeder siembra geocercas reales y, para hasta dos vehículos, un recorrido con su trip
 * cerrado y la posición en vivo. Se verifica también su idempotencia (un segundo run no duplica).
 */
it('siembra geocercas, trips y posiciones de demo sin reventar', function (): void {
    // Dos vehículos de flota para que el seeder materialice recorridos (evita datos huérfanos).
    VehicleFactory::new()->count(2)->create();

    $this->seed(TelemetryDemoSeeder::class);

    expect(DB::table('telemetry_geofences')->count())->toBeGreaterThan(0)
        ->and(DB::table('telemetry_pings')->count())->toBeGreaterThan(0)
        ->and(DB::table('telemetry_trips')->count())->toBeGreaterThan(0)
        ->and(DB::table('telemetry_vehicle_positions')->count())->toBeGreaterThan(0);
});

it('es idempotente: un segundo run no duplica las geocercas de demo', function (): void {
    $this->seed(TelemetryDemoSeeder::class);
    $after = DB::table('telemetry_geofences')->count();

    $this->seed(TelemetryDemoSeeder::class);

    expect(DB::table('telemetry_geofences')->count())->toBe($after);
});
