<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Shipments\Infrastructure\Persistence\Seeders\ShipmentsDemoSeeder;

/**
 * Cobertura del seeder de demostración del contexto shipments (Patrón D del brief): construye los
 * pedidos por el dominio y los persiste por el repositorio. Idempotente: reejecutarlo no duplica.
 * Contra PostgreSQL (base logistics_test).
 */
it('siembra pedidos de demostración sin reventar y es idempotente', function (): void {
    $this->seed(ShipmentsDemoSeeder::class);

    $count = DB::table('shipments')->count();
    expect($count)->toBeGreaterThan(0);

    // Segundo pase: no debe duplicar (idempotente por fuente + id externo).
    $this->seed(ShipmentsDemoSeeder::class);
    expect(DB::table('shipments')->count())->toBe($count);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
