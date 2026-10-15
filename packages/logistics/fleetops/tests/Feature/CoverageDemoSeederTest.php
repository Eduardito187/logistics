<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\FleetOps\Infrastructure\Seeder\FleetOpsDemoSeeder;

/**
 * Cobertura del seeder de demo de FleetOps (Patrón D del brief). Sin vehículos de flota solo siembra los
 * precios de combustible (dato transversal) y corta; con un vehículo real de `fleet_vehicles` siembra
 * además el set completo por vehículo (perfil, cargas, plan, orden, salida y gastos). No caza bugs: solo
 * ejercita el seeder contra la base.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

it('siembra solo los precios de combustible cuando no hay vehículos de flota', function (): void {
    $this->seed(FleetOpsDemoSeeder::class);

    expect(DB::table('fleetops_fuel_prices')->where('source', 'ANH-demo')->count())->toBeGreaterThan(0);
});

it('siembra el set de demo completo cuando hay un vehículo de flota real', function (): void {
    $vehicleId = (string) Str::uuid7();
    DB::table('fleet_vehicles')->insert([
        'id' => $vehicleId,
        'plate' => 'SEED-001',
        'capacity_kg' => 5_000,
        'capacity_m3' => 20.000,
        'home_base_node_id' => (string) Str::uuid7(),
        'status' => 'active',
        'documents' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(FleetOpsDemoSeeder::class);

    expect(DB::table('fleetops_vehicle_profiles')->where('vehicle_id', $vehicleId)->count())->toBe(1)
        ->and(DB::table('fleetops_refuels')->where('vehicle_id', $vehicleId)->count())->toBeGreaterThan(0)
        ->and(DB::table('fleetops_maintenance_plans')->where('vehicle_id', $vehicleId)->count())->toBe(1)
        ->and(DB::table('fleetops_maintenance_orders')->where('vehicle_id', $vehicleId)->count())->toBe(1)
        ->and(DB::table('fleetops_departures')->where('vehicle_id', $vehicleId)->count())->toBe(1)
        ->and(DB::table('fleetops_expenses')->where('vehicle_id', $vehicleId)->count())->toBeGreaterThan(0);
});

it('es idempotente: correr el seeder dos veces no duplica los precios', function (): void {
    $this->seed(FleetOpsDemoSeeder::class);
    $countAfterFirst = DB::table('fleetops_fuel_prices')->where('source', 'ANH-demo')->count();

    $this->seed(FleetOpsDemoSeeder::class);

    expect(DB::table('fleetops_fuel_prices')->where('source', 'ANH-demo')->count())->toBe($countAfterFirst);
});
