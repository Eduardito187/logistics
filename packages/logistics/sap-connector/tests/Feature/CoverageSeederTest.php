<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\SapConnector\Infrastructure\Persistence\Seeders\SapConnectorSeeder;

/**
 * Cobertura del seeder de demo del conector SAP (patrón D): siembra el mapa de traducción y traspasos
 * en todos los estados. Es idempotente, así que correrlo dos veces no duplica filas.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere el stack de Laravel booteado dentro de los containers.',
);

it('siembra mapeos y traspasos de demo sin reventar', function (): void {
    $this->seed(SapConnectorSeeder::class);

    expect(DB::table('sap_material_mappings')->count())->toBeGreaterThan(0)
        ->and(DB::table('sap_stock_transfers')->count())->toBeGreaterThan(0);
});

it('es idempotente: reaplicar el seeder no duplica filas', function (): void {
    $this->seed(SapConnectorSeeder::class);
    $mappings = DB::table('sap_material_mappings')->count();
    $transfers = DB::table('sap_stock_transfers')->count();

    $this->seed(SapConnectorSeeder::class);

    expect(DB::table('sap_material_mappings')->count())->toBe($mappings)
        ->and(DB::table('sap_stock_transfers')->count())->toBe($transfers);
});
