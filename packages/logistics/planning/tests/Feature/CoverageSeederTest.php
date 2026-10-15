<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Logistics\Planning\Database\Seeders\PlanningDemoSeeder;

// Ejercita el seeder de demo del contexto planificación (Infrastructure): recorre el camino real del
// dominio (borrador → propuesta → carga → publicación) por lo que cubre agregado, repositorio, mapper y
// optimizador. Con OSRM inalcanzable el optimizador degrada al orden por cercanía (offline).

beforeEach(function (): void {
    Http::fake(['*' => Http::response(null, 500)]);
});

it('siembra planes de demo sin reventar', function (): void {
    $this->seed(PlanningDemoSeeder::class);

    // Tres camiones de demo (uno publicado, uno propuesto, uno borrador).
    expect(DB::table('planning_plans')->count())->toBeGreaterThan(0)
        ->and(DB::table('planning_plan_stops')->count())->toBeGreaterThan(0);
});

it('es idempotente: un segundo run no duplica planes', function (): void {
    $this->seed(PlanningDemoSeeder::class);
    $planCount = DB::table('planning_plans')->count();

    $this->seed(PlanningDemoSeeder::class);

    expect(DB::table('planning_plans')->count())->toBe($planCount);
});
